/*
 * aop extension: the zend_execute_ex / zend_execute_internal hooks.
 *
 * This is the only file that relies on the VM's frame layout and teardown sequence, so it is where
 * PHP version differences go. Verified against PHP-8.3.6, 8.4.27 and 8.5.12 (see aop_compat.h):
 *   - i_init_func_execute_data() / zend_copy_extra_args()  (Zend/zend_execute.c)
 *   - ZEND_DO_FCALL and the ZEND_CALL_TOP branch of zend_leave_helper  (Zend/zend_vm_def.h)
 *   - zend_call_function()  (Zend/zend_execute_API.c)
 * It also holds the compile-time side: the zend_extension op_array handler behind aop.disable_inlining, the
 * post-startup installation of the backend chosen by aop.interception, and the JIT startup notice.
 *
 * Two backends share the decision and dispatch code (aop_advise_frame()):
 *   hook    zend_execute_ex sees every user call; needs the VM hook, so opcache disables its JIT.
 *   woven   a prologue woven into each function (aop_weave.c) calls AOP\Internal\intercept() from the function's
 *           own frame; only zend_execute_internal is hooked, which the JIT honours.
 * Additionally verified for the woven backend against PHP-8.3.6, 8.4.27 and 8.5.12:
 *   - ZEND_GENERATOR_CREATE sets the generator frame's return_value to the zend_generator object, and the
 *     generator body (so the prologue) runs on its first resume  (Zend/zend_vm_def.h, zend_generators.c)
 *   - ZEND_RECV_VARIADIC copies the extra named parameters into the variadic array and leaves them in the frame
 *   - zend_verify_never_error(), get_function_or_method_name()
 *   - accel_post_startup() (ext/opcache/ZendAccelerator.c) calls the previous zend_post_startup_cb before
 *     zend_jit_check_support(), which refuses the JIT when zend_execute_ex was overridden
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_closures.h"
#include "zend_execute.h"
#include "zend_observer.h"
#include "zend_extensions.h"
#include "zend_interfaces.h"
#include "zend_weakrefs.h"
#include "SAPI.h"
#include "php_aop.h"
#include "aop_g.h"
#include "aop_compat.h"

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
	/* Class loading is not woven by default: the runtime itself autoloads its classes lazily, so advising
	 * the autoloader (and the internal functions it calls) would re-enter the dispatcher without end. */
	if (!AOP_G(weave_autoload) && EG(in_autoload) && zend_hash_num_elements(EG(in_autoload)) > 0) {
		return NULL;
	}
#ifdef ZEND_CHECK_STACK_LIMIT
	/* Let the engine raise its "Maximum call stack size reached" error instead of recursing in C. */
	if (UNEXPECTED(zend_call_stack_overflowed(EG(stack_limit)))) {
		return NULL;
	}
#endif
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
	if (aop_is_property_hook(func)) {
		return NULL; /* 8.4+ property hook body: a field access, not a method */
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

/*
 * A constructor's promoted parameters are assigned to their properties by opcodes right after the RECVs,
 * i.e. before the prologue runs. The original, run again by OriginalCall, would assign them a second time
 * (an Error for readonly ones), and a dispatcher that skips the original must leave the object as the
 * hook backend does: with the constructor not run. So put them back to their declared defaults
 * (uninitialized for typed ones) before dispatching.
 */
static void aop_reset_promoted_properties(zend_execute_data *ex)
{
	zend_function *func = ex->func;
	zend_class_entry *scope = func->common.scope;
	zend_object *obj;
	uint32_t i;

	if (!scope || !(func->common.fn_flags & ZEND_ACC_CTOR) || Z_TYPE(ex->This) != IS_OBJECT) {
		return;
	}
	obj = Z_OBJ(ex->This);
	for (i = 0; i < func->common.num_args; i++) {
		zend_property_info *info = zend_hash_find_ptr(&scope->properties_info, func->op_array.arg_info[i].name);
		zval *slot;

		if (!info || !(info->flags & ZEND_ACC_PROMOTED) || info->ce != scope) {
			continue;
		}
		slot = OBJ_PROP(obj, info->offset);
		if (Z_TYPE_P(slot) == IS_UNDEF) {
			continue;
		}
		if (Z_ISREF_P(slot) && ZEND_REF_HAS_TYPE_SOURCES(Z_REF_P(slot))) {
			ZEND_REF_DEL_TYPE_SOURCE(Z_REF_P(slot), info);
		}
		zval_ptr_dtor(slot);
		ZVAL_COPY_PROP(slot, &obj->ce->default_properties_table[OBJ_PROP_TO_NUM(info->offset)]);
	}
}

/*
 * Arguments of a woven frame, read by the prologue after the RECV opcodes ran: the declared parameters
 * hold the coerced values (and the defaults of omitted ones, which are left out here), extra positional
 * arguments sit behind the CVs and TMPs as before. A variadic parameter is already an array: its string keys
 * are the extra named arguments (RECV_VARIADIC moved them there), so extra_named_params is not read again.
 * Called once the call is going to be advised, which is why it also undoes the constructor promotion.
 */
static void aop_collect_woven_args(zend_execute_data *ex, zval *args)
{
	zend_op_array *op_array = &ex->func->op_array;
	uint32_t num_args = ZEND_CALL_NUM_ARGS(ex);
	uint32_t declared = MIN(num_args, op_array->num_args);
	uint32_t i;

	array_init_size(args, num_args);
	for (i = 0; i < declared; i++) {
		zval *arg = ZEND_CALL_VAR_NUM(ex, i);
		Z_TRY_ADDREF_P(arg);
		zend_hash_next_index_insert_new(Z_ARRVAL_P(args), arg);
	}
	if (op_array->fn_flags & ZEND_ACC_VARIADIC) {
		zval *rest = ZEND_CALL_VAR_NUM(ex, op_array->num_args);
		if (Z_TYPE_P(rest) == IS_ARRAY) {
			zend_string *name;
			zval *val;
			ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(rest), name, val) {
				Z_TRY_ADDREF_P(val);
				if (name) {
					zend_hash_update(Z_ARRVAL_P(args), name, val);
				} else {
					zend_hash_next_index_insert(Z_ARRVAL_P(args), val);
				}
			} ZEND_HASH_FOREACH_END();
		}
	} else if (num_args > op_array->num_args) {
		zval *extra = ZEND_CALL_VAR_NUM(ex, op_array->last_var + op_array->T);
		for (i = op_array->num_args; i < num_args; i++, extra++) {
			Z_TRY_ADDREF_P(extra);
			zend_hash_next_index_insert(Z_ARRVAL_P(args), extra);
		}
	}
	aop_reset_promoted_properties(ex);
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

/* {{{ shared decision and dispatch */
typedef void (*aop_collect_fn)(zend_execute_data *ex, zval *args);

typedef enum {
	AOP_PASS,    /* not intercepted: run the function */
	AOP_ADVISED, /* the dispatcher ran; retval holds its result (UNDEF when it threw) */
	AOP_ABORTED  /* the matcher failed with an exception: the call fails with it, retval is UNDEF */
} aop_outcome;

/*
 * The part of an interception both backends share (contract §1): bypass, guards, join point, decision,
 * arguments, dispatch. ex is the frame of the function (current in the hook backend, the caller of
 * intercept() in the woven one). skip_info lists the ZEND_CALL_* flags of frames that are no join points.
 */
static zend_always_inline aop_outcome aop_advise_frame(zend_execute_data *ex, uint32_t skip_info,
		aop_collect_fn collect, zval *retval)
{
	zend_function *target;
	zend_class_entry *called = NULL;
	zend_object *this_obj = NULL;
	zend_long flags;
	zval args;

	if (UNEXPECTED(AOP_G(bypass) != NULL) && AOP_G(bypass) == ex->func) {
		AOP_G(bypass) = NULL;
		return AOP_PASS;
	}
	if (EXPECTED(!AOP_G(registered)) || AOP_G(in_matcher) || EG(exception)) {
		return AOP_PASS;
	}
	if (ZEND_CALL_INFO(ex) & skip_info) {
		return AOP_PASS;
	}
	target = aop_join_point(ex, &called, &this_obj);
	if (!target) {
		return AOP_PASS;
	}

	flags = aop_decide(target, called, aop_caller_frame(ex));
	if (UNEXPECTED(EG(exception))) {
		ZVAL_UNDEF(retval);
		return AOP_ABORTED;
	}
	if (!(flags & AOP_INTERCEPT) || !AOP_G(registered)) {
		return AOP_PASS;
	}

	AOP_CHECK(collect == aop_collect_woven_args || EG(current_execute_data) == ex);
	collect(ex, &args);
	aop_dispatch(ex, target, called, this_obj, &args, retval);
	AOP_CHECK(collect == aop_collect_woven_args || EG(current_execute_data) == ex);
	return AOP_ADVISED;
}
/* }}} */

/* {{{ hook backend */
static void aop_execute_ex(zend_execute_data *ex)
{
	zval retval;

	/* Generator resumption and top-level/include/eval code are no join points. */
	switch (aop_advise_frame(ex, ZEND_CALL_GENERATOR | ZEND_CALL_CODE, aop_collect_user_args, &retval)) {
		case AOP_PASS:
			aop_prev_execute_ex(ex);
			return;
		case AOP_ABORTED:
			/* The matcher warning was turned into an exception (error handler): the call fails with it. */
			break;
		case AOP_ADVISED:
			break;
	}
	aop_finish_user_frame(ex, &retval);
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
	zval retval;
	zif_handler handler = ex->func->internal_function.handler;

	/* The woven backend's own calls: one pointer comparison instead of the join point checks. */
	if (handler == ZEND_FN(AOP_Internal_intercept) || handler == ZEND_FN(AOP_Internal_result)
			|| handler == ZEND_FN(AOP_Internal_caught)) {
		aop_call_internal(ex, return_value);
		return;
	}
	switch (aop_advise_frame(ex, 0, aop_collect_internal_args, &retval)) {
		case AOP_PASS:
			aop_call_internal(ex, return_value);
			return;
		case AOP_ABORTED:
			return;
		case AOP_ADVISED:
			break;
	}
	if (EG(exception)) {
		return;
	}
	zval_ptr_dtor(return_value);
	ZVAL_COPY_VALUE(return_value, &retval);
}

/* The hook backend is only active in MODE_HOOK; the internal hook serves both. */
static void aop_hook_install(bool execute_ex)
{
	if (execute_ex) {
		aop_prev_execute_ex = zend_execute_ex;
		zend_execute_ex = aop_execute_ex;
	}
	aop_prev_execute_internal = zend_execute_internal;
	zend_execute_internal = aop_execute_internal;
}

void aop_hook_uninstall(void)
{
	if (aop_interception_mode == AOP_MODE_HOOK) {
		zend_execute_ex = aop_prev_execute_ex;
	}
	zend_execute_internal = aop_prev_execute_internal;
}
/* }}} */

/* {{{ woven backend: AOP\Internal\intercept() / AOP\Internal\result() */
#ifdef ZEND_CHECK_STACK_LIMIT
/* The engine's own error (zend_call_stack_size_error() is not exported before 8.4). */
static ZEND_COLD void aop_stack_size_error(void)
{
	size_t max_stack_size = 0;

	if ((uintptr_t) EG(stack_base) > (uintptr_t) EG(stack_limit)) {
		max_stack_size = (uintptr_t) EG(stack_base) - (uintptr_t) EG(stack_limit) + EG(reserved_stack_size);
	}
	zend_throw_error(NULL, "Maximum call stack size of %zu bytes reached. Infinite recursion?", max_stack_size);
}
#endif

/*
 * Called by the prologue woven into a function, so the frame to look at is the caller's. Returns true when
 * the call was dispatched: the function then returns what result() hands over (or fails with the exception
 * the dispatcher threw, which the engine raises right after this call returns).
 */
ZEND_FUNCTION(AOP_Internal_intercept)
{
	zend_execute_data *ex = execute_data->prev_execute_data;
	zval retval;

	ZEND_PARSE_PARAMETERS_NONE();

	if (UNEXPECTED(!ex || !ex->func || !ZEND_USER_CODE(ex->func->type))) {
		RETURN_FALSE;
	}
	/* A generator that OriginalCall created starts lazily, so the single-shot bypass cannot mark it. */
	if (UNEXPECTED(AOP_G(bypassed_generators) != NULL) && (ZEND_CALL_INFO(ex) & ZEND_CALL_GENERATOR)
			&& zend_weakrefs_hash_del(AOP_G(bypassed_generators), (zend_object *) ex->return_value) == SUCCESS) {
		RETURN_FALSE;
	}
	switch (aop_advise_frame(ex, 0, aop_collect_woven_args, &retval)) {
		case AOP_PASS:
#ifdef ZEND_CHECK_STACK_LIMIT
			/*
			 * aop_join_point() refuses to dispatch near the end of the C stack. The hook backend then runs
			 * execute_ex(), whose entry raises the engine's error; here the function runs in the VM loop that
			 * is already active, so raise it now instead of recursing until the memory limit.
			 */
			if (UNEXPECTED(AOP_G(registered)) && UNEXPECTED(zend_call_stack_overflowed(EG(stack_limit)))) {
				aop_stack_size_error();
				RETURN_TRUE;
			}
#endif
			RETURN_FALSE;
		case AOP_ABORTED:
			RETURN_TRUE;
		case AOP_ADVISED:
			break;
	}
	if (!EG(exception)) {
		zval_ptr_dtor(&AOP_G(woven_result));
		ZVAL_COPY_VALUE(&AOP_G(woven_result), &retval);
	}
	RETURN_TRUE;
}

/*
 * The advice result for the function that called intercept(). It is typed like the function's own return
 * (the engine checks it after the prologue's return), with two exceptions: a never function must not
 * return, and the advice of a generator function must yield from something.
 */
ZEND_FUNCTION(AOP_Internal_result)
{
	zend_execute_data *ex = execute_data->prev_execute_data;
	zend_function *func = ex ? ex->func : NULL;

	ZEND_PARSE_PARAMETERS_NONE();

	if (Z_ISUNDEF(AOP_G(woven_result))) {
		RETURN_NULL();
	}
	ZVAL_COPY_VALUE(return_value, &AOP_G(woven_result));
	ZVAL_UNDEF(&AOP_G(woven_result));

	if (!func || !ZEND_USER_CODE(func->type)) {
		return;
	}
	if ((func->common.fn_flags & ZEND_ACC_HAS_RETURN_TYPE)
			&& ZEND_TYPE_CONTAINS_CODE(func->common.arg_info[-1].type, IS_NEVER)) {
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
		if (!EG(exception)) {
			zend_verify_never_error(func);
		}
		return;
	}
	if ((func->common.fn_flags & ZEND_ACC_GENERATOR)
			&& Z_TYPE_P(return_value) != IS_ARRAY
			&& !(Z_TYPE_P(return_value) == IS_OBJECT && instanceof_function(Z_OBJCE_P(return_value), zend_ce_traversable))) {
		zend_string *name = get_function_or_method_name(func);
		zend_type_error("%s(): the advice of a generator function must return an array or a Traversable, %s returned",
			ZSTR_VAL(name), zend_zval_type_name(return_value));
		zend_string_release(name);
		zval_ptr_dtor(return_value);
		ZVAL_NULL(return_value);
	}
}

void aop_woven_rinit(void)
{
	ZVAL_UNDEF(&AOP_G(woven_result));
	AOP_G(bypassed_generators) = NULL;
}

void aop_woven_rshutdown(void)
{
	zval_ptr_dtor(&AOP_G(woven_result));
	ZVAL_UNDEF(&AOP_G(woven_result));
	aop_bypassed_generators_free();
}
/* }}} */

/* {{{ compile time: opcache inlining (aop.disable_inlining) */
/*
 * Called by pass_two() for every op_array compiled with ZEND_COMPILE_HANDLE_OP_ARRAY, which is the default
 * and which opcache sets for the scripts it caches; it runs before opcache's optimizer. Every named
 * function and method gets AOP_ACC_NO_INLINE, so optimizer pass 16 never replaces a call by the constant
 * the callee returns. Flagging all of them (not just those whose compiled body is `return <const>;`) also
 * covers bodies that only become a constant during optimization (e.g. after constant propagation).
 */
static void aop_op_array_handler(zend_op_array *op_array)
{
	if (op_array->function_name
			&& !(op_array->fn_flags & ZEND_ACC_CLOSURE)
			&& !aop_is_property_hook((zend_function *) op_array)) {
		op_array->fn_flags |= AOP_ACC_NO_INLINE;
	}
}

static zend_extension aop_zend_extension = {
	.name = "aop",
	.version = PHP_AOP_VERSION,
	.author = "aop",
	.URL = NULL,
	.copyright = NULL,
	/* no startup: an extension without one is not listed by `php -v` */
	.op_array_handler = aop_op_array_handler,
	.api_no_check = NULL,
	.build_id_check = NULL,
	.handle = NULL,
	.resource_number = -1,
};

/* Registers the extension's zend_extension half (MINIT, before any script is compiled). */
void aop_compile_hooks_install(void)
{
	zend_register_extension(&aop_zend_extension, NULL);
}
/* }}} */

/* {{{ JIT */
/*
 * True when opcache would start the JIT for this SAPI if zend_execute_ex were not overridden: the same
 * condition as accel_post_startup() (JIT_G(enabled) && JIT_G(buffer_size)) for opcache.enable(_cli).
 * Reads the registered INI entries, so it is only meaningful after opcache's startup.
 */
bool aop_jit_requested(void)
{
	bool exists = false;
	const char *mode = zend_ini_string_ex(ZEND_STRL("opcache.jit"), 0, &exists);

	if (!exists || !mode || strcasecmp(mode, "disable") == 0) {
		return false;
	}
	if (zend_ini_long(ZEND_STRL("opcache.jit_buffer_size"), 0) <= 0) {
		return false;
	}
	if (!zend_ini_long(ZEND_STRL("opcache.enable"), 0)) {
		return false;
	}
	if (strcmp(sapi_module.name, "cli") == 0 || strcmp(sapi_module.name, "phpdbg") == 0) {
		return zend_ini_long(ZEND_STRL("opcache.enable_cli"), 0) != 0;
	}
	return true;
}

static zend_result (*aop_prev_post_startup)(void) = NULL;

/* The backend aop.interception selects. "auto" is the hook backend unless the JIT is configured. */
static aop_mode_t aop_resolve_mode(void)
{
	switch (aop_interception_setting) {
		case AOP_SETTING_HOOK:
			return AOP_MODE_HOOK;
		case AOP_SETTING_AUTO:
			return aop_jit_requested() ? AOP_MODE_WOVEN : AOP_MODE_HOOK;
		default:
			return AOP_MODE_WOVEN;
	}
}

/*
 * Installs the interception once opcache's INI entries exist, and before opcache decides about the JIT:
 * accel_post_startup() runs the callback chain first and only then zend_jit_check_support(), which turns the
 * JIT off for an overridden zend_execute_ex or any user opcode handler. No script is compiled or run between
 * MINIT and this callback, so nothing is missed by installing late.
 */
static void aop_install_backend(void)
{
	aop_interception_mode = aop_resolve_mode();
	aop_hook_install(aop_interception_mode == AOP_MODE_HOOK);
	if (aop_interception_mode == AOP_MODE_HOOK) {
		aop_fields_minit();
		aop_catch_minit();
	}
	aop_hooks_installed = true;
}

/*
 * PHP itself warns "JIT is incompatible with third party extensions that override zend_execute_ex()";
 * this notice names the extension and the way out.
 */
static zend_result aop_post_startup(void)
{
	aop_install_backend();
	if (aop_prev_post_startup) {
		zend_result (*cb)(void) = aop_prev_post_startup;
		aop_prev_post_startup = NULL;
		if (cb() != SUCCESS) {
			return FAILURE;
		}
	}
	if (aop_interception_mode == AOP_MODE_HOOK && aop_jit_requested()) {
		zend_error(E_NOTICE, "aop: the opcache JIT is disabled because aop.interception=hook overrides "
			"zend_execute_ex(); set aop.interception=woven to keep the JIT");
	}
	return SUCCESS;
}

void aop_post_startup_install(void)
{
	aop_prev_post_startup = zend_post_startup_cb;
	zend_post_startup_cb = aop_post_startup;
}
/* }}} */
