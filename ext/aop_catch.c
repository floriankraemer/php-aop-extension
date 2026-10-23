/*
 * aop extension, stream G: the exception-handler join point, through a ZEND_CATCH user opcode handler.
 *
 * Every catch clause compiles to one ZEND_CATCH per class (`catch (A | B $e)` gives two). The handler
 * replicates the matching of the original (zend_exception_restore(), the cached catch class, exact
 * class or instanceof_function()) and, for the clause that catches the in-flight exception, asks the
 * matcher per (enclosing code, runtime class, catch class) and dispatches a before-only join point
 * with the exception temporarily taken out of EG(exception). Then the original handler runs and
 * performs the catch as usual.
 *
 * An exception thrown by the dispatcher (or by an error handler during the matcher warning) replaces
 * the caught one, AspectJ style: it propagates as if thrown at the start of the catch block, so no
 * catch clause of the same try sees it, its finally block runs, and outer try blocks can catch it.
 * The caught exception is released.
 *
 * Verified against PHP-8.3.6: ZEND_CATCH, ZEND_USER_OPCODE, ZEND_HANDLE_EXCEPTION and
 * zend_dispatch_try_catch_finally_helper (Zend/zend_vm_def.h); zend_call_function()'s rethrow into the
 * calling user frame (Zend/zend_execute_API.c); zend_rethrow_exception() (Zend/zend_execute.c).
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"
#include "zend_execute.h"
#include "php_aop.h"
#include "aop_g.h"

static user_opcode_handler_t aop_prev_catch_handler = NULL;

static const char aop_kind_handler[] = "exception-handler";

static zend_always_inline int aop_catch_pass(zend_execute_data *execute_data)
{
	return aop_prev_catch_handler ? aop_prev_catch_handler(execute_data) : ZEND_USER_OPCODE_DISPATCH;
}

static int aop_catch_handler(zend_execute_data *execute_data)
{
	const zend_op *opline = EX(opline);
	zend_function *func = EX(func);
	zend_class_entry *catch_ce;
	zend_object *exception;
	zend_long flags;
	aop_jp jp;

	if (EXPECTED(!AOP_G(registered)) || AOP_G(in_matcher)) {
		return aop_catch_pass(execute_data);
	}

	zend_exception_restore();
	exception = EG(exception);
	if (!exception || zend_is_unwind_exit(exception) || zend_is_graceful_exit(exception)) {
		return aop_catch_pass(execute_data);
	}

	catch_ce = CACHED_PTR(opline->extended_value & ~ZEND_LAST_CATCH);
	if (UNEXPECTED(catch_ce == NULL)) {
		catch_ce = zend_fetch_class_by_name(Z_STR_P(RT_CONSTANT(opline, opline->op1)),
			Z_STR_P(RT_CONSTANT(opline, opline->op1) + 1),
			ZEND_FETCH_CLASS_NO_AUTOLOAD | ZEND_FETCH_CLASS_SILENT);
		CACHE_PTR(opline->extended_value & ~ZEND_LAST_CATCH, catch_ce);
	}
	if (!catch_ce || (exception->ce != catch_ce && !instanceof_function(exception->ce, catch_ce))) {
		return aop_catch_pass(execute_data);
	}

	/* The runtime's own catch blocks are never join points. */
	if (func->common.scope ? aop_class_in_aop_ns(func->common.scope)
			: (func->common.function_name && aop_name_in_aop_ns(func->common.function_name))) {
		return aop_catch_pass(execute_data);
	}
	if (aop_class_in_aop_ns(aop_frame_class(execute_data))) {
		return aop_catch_pass(execute_data);
	}

	memset(&jp, 0, sizeof(jp));
	jp.kind = aop_kind_handler;
	jp.class_ = func->common.scope ? aop_frame_class(execute_data) : NULL;
	jp.scope = func->common.scope;
	jp.name = catch_ce->name;
	jp.is_static = func->common.scope && (func->common.fn_flags & ZEND_ACC_STATIC);
	jp.id1 = func->op_array.opcodes;
	jp.id2 = catch_ce;

	/* The matcher and the dispatcher run with no exception pending. */
	EG(exception) = NULL;
	flags = aop_decide_jp(&jp, execute_data);
	if (!EG(exception) && (flags & AOP_INTERCEPT) && AOP_G(registered)) {
		zval args, original, retval, exzv;

		ZVAL_OBJ(&exzv, exception);
		array_init_size(&args, 1);
		Z_ADDREF(exzv);
		add_next_index_zval(&args, &exzv);
		aop_original_call_new_native(&original, NULL,
			Z_TYPE(EX(This)) == IS_OBJECT ? Z_OBJ(EX(This)) : NULL, NULL, NULL, 0);
		aop_dispatch_jp(&jp, execute_data, Z_TYPE(EX(This)) == IS_OBJECT ? Z_OBJ(EX(This)) : NULL,
			"exception", &exzv, &args, &original, &retval);
		zval_ptr_dtor(&retval);
	}

	if (UNEXPECTED(EG(exception))) {
		/*
		 * The new exception wins. zend_call_function() rethrew it into this frame, so EX(opline) is
		 * the exception handler and EG(opline_before_exception) this ZEND_CATCH: the VM unwinds as
		 * for an exception thrown inside the catch region.
		 */
		OBJ_RELEASE(exception);
		return ZEND_USER_OPCODE_CONTINUE;
	}

	EG(exception) = exception;
	return aop_catch_pass(execute_data);
}

void aop_catch_minit(void)
{
	aop_prev_catch_handler = zend_get_user_opcode_handler(ZEND_CATCH);
	zend_set_user_opcode_handler(ZEND_CATCH, aop_catch_handler);
}

void aop_catch_mshutdown(void)
{
	zend_set_user_opcode_handler(ZEND_CATCH, aop_prev_catch_handler);
}
