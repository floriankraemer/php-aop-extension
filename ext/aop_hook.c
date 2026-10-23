/*
 * aop extension: the zend_execute_ex / zend_execute_internal hooks.
 *
 * This is the only file that relies on the VM's frame layout and teardown sequence, so it is where
 * PHP version differences go. Verified against PHP-8.3.6:
 *   - i_init_func_execute_data() / zend_copy_extra_args()  (Zend/zend_execute.c)
 *   - ZEND_DO_FCALL and the ZEND_CALL_TOP branch of zend_leave_helper  (Zend/zend_vm_def.h)
 *   - zend_call_function()  (Zend/zend_execute_API.c)
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_closures.h"
#include "zend_execute.h"
#include "zend_observer.h"
#include "php_aop.h"

#if PHP_VERSION_ID < 80300
# error "the aop extension requires PHP 8.3 or newer"
#endif

static void (*aop_prev_execute_ex)(zend_execute_data *execute_data) = NULL;
static void (*aop_prev_execute_internal)(zend_execute_data *execute_data, zval *return_value) = NULL;

/* {{{ join point identification */
/*
 * A fake closure ($obj->method(...), Closure::fromCallable()) runs a copy of the method; advise it as
 * the method itself. Returns NULL when the copy cannot be matched to a declared function.
 */
static zend_function *aop_resolve_fake_closure(zend_function *func)
{
	HashTable *table = func->common.scope ? &func->common.scope->function_table : EG(function_table);
	zend_function *real;

	if (!func->common.function_name) {
		return NULL;
	}
	real = zend_hash_find_ptr_lc(table, func->common.function_name);
	if (!real || real->type != func->type) {
		return NULL;
	}
	if (func->type == ZEND_USER_FUNCTION
			? real->op_array.opcodes != func->op_array.opcodes
			: real->internal_function.handler != func->internal_function.handler) {
		return NULL;
	}
	return real;
}

/*
 * The function to advise for frame ex, or NULL when the frame is never intercepted (contract §1.4).
 * Fills the runtime class (or called scope; NULL for functions) and $this.
 */
static zend_always_inline zend_function *aop_join_point(zend_execute_data *ex,
		zend_class_entry **called_out, zend_object **this_out)
{
	zend_function *func = ex->func;
	uint32_t fn_flags = func->common.fn_flags;
	zend_class_entry *called = NULL;
	zend_object *this_obj = NULL;

	if (fn_flags & (ZEND_ACC_RETURN_REFERENCE | ZEND_ACC_CALL_VIA_TRAMPOLINE)) {
		return NULL;
	}
	if (fn_flags & ZEND_ACC_CLOSURE) {
		if (!(fn_flags & ZEND_ACC_FAKE_CLOSURE)) {
			return NULL;
		}
		func = aop_resolve_fake_closure(func);
		if (!func) {
			return NULL;
		}
	}
	if (!func->common.function_name) {
		return NULL; /* top-level, include or eval code */
	}

	if (func->common.scope) {
		if (Z_TYPE(ex->This) == IS_OBJECT) {
			this_obj = Z_OBJ(ex->This);
			called = this_obj->ce;
		} else {
			called = Z_CE(ex->This) ? Z_CE(ex->This) : func->common.scope;
		}
		if (aop_class_in_aop_ns(func->common.scope) || aop_class_in_aop_ns(called)) {
			return NULL;
		}
	} else if (aop_name_in_aop_ns(func->common.function_name)) {
		return NULL;
	}

	if (aop_caller_in_aop_ns(ex)) {
		return NULL;
	}

	*called_out = called;
	*this_out = this_obj;
	return func;
}
/* }}} */

/* {{{ arguments (contract §3) */
static void aop_add_extra_named(zend_execute_data *ex, zval *args)
{
	if (ZEND_CALL_INFO(ex) & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS) {
		zend_string *name;
		zval *val;
		ZEND_HASH_FOREACH_STR_KEY_VAL(ex->extra_named_params, name, val) {
			if (name) {
				Z_TRY_ADDREF_P(val);
				zend_hash_update(Z_ARRVAL_P(args), name, val);
			}
		} ZEND_HASH_FOREACH_END();
	}
}

/*
 * Raw arguments of a user frame before its RECV opcodes ran. i_init_func_execute_data() left the
 * declared arguments in the first CV slots and moved the extra positional ones behind the CVs and TMPs
 * (zend_copy_extra_args()). Arguments skipped by named calls were already filled with their defaults
 * by zend_handle_undef_args(); an UNDEF slot is skipped and later ones are keyed by parameter name.
 */
static void aop_collect_user_args(zend_execute_data *ex, zval *args)
{
	zend_op_array *op_array = &ex->func->op_array;
	uint32_t num_args = ZEND_CALL_NUM_ARGS(ex);
	uint32_t declared = MIN(num_args, op_array->num_args);
	bool gap = false;
	uint32_t i;

	array_init_size(args, num_args);
	for (i = 0; i < declared; i++) {
		zval *arg = ZEND_CALL_ARG(ex, i + 1);
		if (Z_ISUNDEF_P(arg)) {
			gap = true;
			continue;
		}
		Z_TRY_ADDREF_P(arg);
		if (gap) {
			zend_hash_update(Z_ARRVAL_P(args), op_array->vars[i], arg);
		} else {
			zend_hash_next_index_insert_new(Z_ARRVAL_P(args), arg);
		}
	}
	if (num_args > op_array->num_args) {
		zval *extra = ZEND_CALL_VAR_NUM(ex, op_array->last_var + op_array->T);
		for (i = op_array->num_args; i < num_args; i++, extra++) {
			Z_TRY_ADDREF_P(extra);
			zend_hash_next_index_insert(Z_ARRVAL_P(args), extra);
		}
	}
	aop_add_extra_named(ex, args);
}

/* Internal functions receive every argument in consecutive slots. */
static void aop_collect_internal_args(zend_execute_data *ex, zval *args)
{
	zend_function *func = ex->func;
	uint32_t num_args = ZEND_CALL_NUM_ARGS(ex);
	bool gap = false;
	uint32_t i;

	array_init_size(args, num_args);
	for (i = 0; i < num_args; i++) {
		zval *arg = ZEND_CALL_ARG(ex, i + 1);
		if (Z_ISUNDEF_P(arg)) {
			gap = true;
			continue;
		}
		Z_TRY_ADDREF_P(arg);
		if (gap && i < func->common.num_args) {
			const char *name = func->internal_function.arg_info[i].name;
			zend_hash_str_update(Z_ARRVAL_P(args), name, strlen(name), arg);
		} else {
			zend_hash_next_index_insert(Z_ARRVAL_P(args), arg);
		}
	}
	aop_add_extra_named(ex, args);
}
/* }}} */

/* {{{ frame teardown */
/*
 * Leaves a user frame that never executed, exactly like the ZEND_CALL_TOP function branch of
 * zend_leave_helper (every frame passed to zend_execute_ex() is a TOP frame). The caller
 * (ZEND_DO_FCALL or zend_call_function()) releases $this and frees the frame.
 */
static void aop_leave_user_frame(zend_execute_data *ex)
{
	uint32_t call_info;

	EG(current_execute_data) = ex->prev_execute_data;
	zend_free_compiled_variables(ex);
	call_info = ZEND_CALL_INFO(ex);
	if (UNEXPECTED(call_info & (ZEND_CALL_HAS_SYMBOL_TABLE | ZEND_CALL_FREE_EXTRA_ARGS | ZEND_CALL_HAS_EXTRA_NAMED_PARAMS))) {
		if (UNEXPECTED(call_info & ZEND_CALL_HAS_SYMBOL_TABLE)) {
			zend_clean_and_cache_symbol_table(ex->symbol_table);
		}
		zend_vm_stack_free_extra_args_ex(call_info, ex);
		if (UNEXPECTED(call_info & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS)) {
			zend_free_extra_named_params(ex->extra_named_params);
		}
	}
	if (UNEXPECTED(call_info & ZEND_CALL_CLOSURE)) {
		OBJ_RELEASE(ZEND_CLOSURE_OBJECT(ex->func));
	}
}

/* Stores the result (UNDEF after an exception), notifies observers and leaves the frame. */
static void aop_finish_user_frame(zend_execute_data *ex, zval *retval)
{
	if (EG(exception)) {
		zval_ptr_dtor(retval);
		ZVAL_UNDEF(retval);
	}
	if (ZEND_OBSERVER_ENABLED) {
		/* ZEND_DO_FCALL / zend_call_function() ran ZEND_OBSERVER_FCALL_BEGIN for this frame. */
		zend_observer_fcall_end(ex, Z_ISUNDEF_P(retval) ? NULL : retval);
	}
	if (ex->return_value) {
		ZVAL_COPY_VALUE(ex->return_value, retval);
	} else {
		zval_ptr_dtor(retval);
	}
	aop_leave_user_frame(ex);
}
/* }}} */

/* {{{ hooks */
static void aop_execute_ex(zend_execute_data *ex)
{
	zend_function *func = ex->func;
	zend_function *target;
	zend_class_entry *called = NULL;
	zend_object *this_obj = NULL;
	zend_long flags;
	zval args, retval;

	if (UNEXPECTED(AOP_G(bypass) != NULL) && AOP_G(bypass) == func) {
		AOP_G(bypass) = NULL;
		goto pass;
	}
	if (EXPECTED(!AOP_G(registered)) || AOP_G(in_matcher) || EG(exception)) {
		goto pass;
	}
	/* Generator resumption and top-level/include/eval code are no join points. */
	if (ZEND_CALL_INFO(ex) & (ZEND_CALL_GENERATOR | ZEND_CALL_CODE)) {
		goto pass;
	}
	target = aop_join_point(ex, &called, &this_obj);
	if (!target) {
		goto pass;
	}

	flags = aop_decide(target, called, aop_caller_frame(ex));
	if (UNEXPECTED(EG(exception))) {
		/* The matcher warning was turned into an exception (error handler): the call fails with it. */
		ZVAL_UNDEF(&retval);
		aop_finish_user_frame(ex, &retval);
		return;
	}
	if (!(flags & AOP_INTERCEPT) || !AOP_G(registered)) {
		goto pass;
	}

	AOP_CHECK(EG(current_execute_data) == ex);
	aop_collect_user_args(ex, &args);
	aop_dispatch(ex, target, called, this_obj, &args, &retval);
	AOP_CHECK(EG(current_execute_data) == ex);
	aop_finish_user_frame(ex, &retval);
	return;

pass:
	aop_prev_execute_ex(ex);
}

static zend_always_inline void aop_call_internal(zend_execute_data *ex, zval *return_value)
{
	if (aop_prev_execute_internal) {
		aop_prev_execute_internal(ex, return_value);
	} else {
		execute_internal(ex, return_value);
	}
}

static void aop_execute_internal(zend_execute_data *ex, zval *return_value)
{
	zend_function *func = ex->func;
	zend_function *target;
	zend_class_entry *called = NULL;
	zend_object *this_obj = NULL;
	zend_long flags;
	zval args, retval;

	if (UNEXPECTED(AOP_G(bypass) != NULL) && AOP_G(bypass) == func) {
		AOP_G(bypass) = NULL;
		goto pass;
	}
	if (EXPECTED(!AOP_G(registered)) || AOP_G(in_matcher) || EG(exception)) {
		goto pass;
	}
	target = aop_join_point(ex, &called, &this_obj);
	if (!target) {
		goto pass;
	}

	flags = aop_decide(target, called, aop_caller_frame(ex));
	if (UNEXPECTED(EG(exception))) {
		return;
	}
	if (!(flags & AOP_INTERCEPT) || !AOP_G(registered)) {
		goto pass;
	}

	AOP_CHECK(EG(current_execute_data) == ex);
	aop_collect_internal_args(ex, &args);
	aop_dispatch(ex, target, called, this_obj, &args, &retval);
	AOP_CHECK(EG(current_execute_data) == ex);
	if (EG(exception)) {
		return;
	}
	zval_ptr_dtor(return_value);
	ZVAL_COPY_VALUE(return_value, &retval);
	return;

pass:
	aop_call_internal(ex, return_value);
}

void aop_hook_install(void)
{
	aop_prev_execute_ex = zend_execute_ex;
	zend_execute_ex = aop_execute_ex;
	aop_prev_execute_internal = zend_execute_internal;
	zend_execute_internal = aop_execute_internal;
}

void aop_hook_uninstall(void)
{
	zend_execute_ex = aop_prev_execute_ex;
	zend_execute_internal = aop_prev_execute_internal;
}
/* }}} */
