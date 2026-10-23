/*
 * aop extension, stream G: field join points (field-get, field-set) and `initialization` for classes
 * without a constructor, both driven by a ZEND_NEW user opcode handler.
 *
 * On `new C`, when a dispatcher is registered, the class probe (kind field-get, name '*') is asked once
 * per class. On a non-zero answer the new object gets a per-class copy of its handlers whose
 * read_property / write_property / get_property_ptr_ptr / clone_obj ask the matcher per (class,
 * property, kind) and dispatch with OriginalCall semantics (contract §1.3).
 *
 * Runtime cache: the VM's property fast paths (ZEND_FETCH_OBJ_*, ZEND_ASSIGN_OBJ, zend_fetch_property_address)
 * only bypass the handlers when slot 0 of the opline's cache equals the object's class. The wrappers
 * run the original handlers with a private cache and publish only slot 2 (the typed property info,
 * which the VM reads after get_property_ptr_ptr/read_property for typed-property checks), with slot 0
 * cleared. So no opline is ever primed for a wrapped object by the wrapper itself.
 *
 * Verified against PHP-8.3.6: ZEND_NEW, ZEND_FETCH_OBJ_R/IS/W/RW, ZEND_ASSIGN_OBJ, ZEND_ASSIGN_OBJ_OP,
 * ZEND_PRE/POST_INC/DEC_OBJ, ZEND_USER_OPCODE (Zend/zend_vm_def.h); zend_fetch_property_address,
 * zend_assign_op_overloaded_property, zend_pre/post_incdec_overloaded_property (Zend/zend_execute.c);
 * zend_std_read/write_property, zend_std_get_property_ptr_ptr, zend_get_property_info,
 * zend_get_property_offset (Zend/zend_object_handlers.c); zend_objects_clone_obj (Zend/zend_objects.c).
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"
#include "zend_execute.h"
#include "zend_objects.h"
#include "zend_objects_API.h"
#include "zend_object_handlers.h"
#include "php_aop.h"
#include "aop_g.h"

#if PHP_VERSION_ID >= 80400
/* PHP 8.4 adds property hooks and lazy objects, which have their own paths through these handlers
 * (zend_std_read_property calls hooks, lazy objects initialize in them). The wrappers delegate to the
 * original handlers, so hooks run as part of the original access; lazy objects are created without
 * ZEND_NEW and are therefore never wrapped. Not verified against 8.4 sources beyond that. */
#endif

static user_opcode_handler_t aop_prev_new_handler = NULL;
static zend_string *aop_str_construct = NULL;

static const char aop_kind_get[] = "field-get";
static const char aop_kind_set[] = "field-set";
static const char aop_kind_init[] = "initialization";
static const char aop_tag_probe[] = "field-probe";

typedef struct {
	zend_object_handlers h;            /* first member: zend_object.handlers points here */
	const zend_object_handlers *orig;
} aop_field_handlers;

static zend_object_handlers *aop_field_handlers_for(zend_class_entry *ce);
static zval *aop_field_read(zend_object *zobj, zend_string *name, int type, void **cache_slot, zval *rv);

static zend_always_inline const zend_object_handlers *aop_field_orig(const zend_object *obj)
{
	return ((const aop_field_handlers *) obj->handlers)->orig;
}

/* The original handlers of a wrapped object (an OriginalCall only ever holds wrapped ones; anything else is used as is). */
static zend_always_inline const zend_object_handlers *aop_unwrapped_handlers(const zend_object *obj)
{
	return obj->handlers->read_property == aop_field_read ? aop_field_orig(obj) : obj->handlers;
}

/* {{{ helpers */
/* The user frame whose code accesses the property (the descriptor's caller), or NULL for internal code. */
static zend_execute_data *aop_access_frame(void)
{
	zend_execute_data *ex = EG(current_execute_data);

	while (ex && !ex->func) {
		ex = ex->prev_execute_data;
	}
	return ex && ZEND_USER_CODE(ex->func->type) ? ex : NULL;
}

static zend_always_inline zend_class_entry *aop_access_scope(void)
{
	return EG(fake_scope) ? EG(fake_scope) : zend_get_executed_scope();
}

static zend_always_inline bool aop_fields_active(void)
{
	return AOP_G(registered) && !AOP_G(in_matcher) && !EG(exception) && !aop_current_code_in_aop_ns();
}

/*
 * Describes an access to name on zobj from the current scope. False when it is no join point: the
 * property is declared but not accessible from here (the original handler raises the error or calls
 * __get/__set), or the name is invalid. Undeclared names (dynamic properties, names served by
 * __get/__set) are join points with scope null.
 */
static bool aop_field_jp(aop_jp *jp, zend_object *zobj, zend_string *name, const char *kind, zend_property_info **info_out)
{
	zend_property_info *info = zend_get_property_info(zobj->ce, name, 1);

	if (info == ZEND_WRONG_PROPERTY_INFO) {
		return false;
	}
	if (info && (info->flags & ZEND_ACC_STATIC)) {
		info = NULL; /* accessed as an instance property: a dynamic one for the handlers */
	}
	memset(jp, 0, sizeof(*jp));
	jp->kind = kind;
	jp->class_ = zobj->ce;
	jp->scope = info ? info->ce : NULL;
	jp->name = name;
	jp->internal = info ? info->ce->type == ZEND_INTERNAL_CLASS : false;
	jp->id1 = info;
	*info_out = info;
	return true;
}

/* Mirrors the readonly checks of zend_std_write_property: true when this write fails anyway. */
static bool aop_readonly_violation(zend_object *zobj, zend_property_info *info, zend_string *name)
{
	zval *slot;
	zend_class_entry *scope;

	if (!info || !(info->flags & ZEND_ACC_READONLY)) {
		return false;
	}
	slot = OBJ_PROP(zobj, info->offset);
	if (Z_TYPE_P(slot) != IS_UNDEF) {
		return !(Z_PROP_FLAG_P(slot) & IS_PROP_REINITABLE);
	}
	scope = aop_access_scope();
	if (info->ce == scope) {
		return false;
	}
	if (scope && instanceof_function(zobj->ce, scope)) {
		zend_property_info *own = zend_hash_find_ptr(&scope->properties_info, name);
		if (own && own->ce == scope) {
			return false;
		}
	}
	return true;
}

/* A write fetch ($o->ro[] = 1, &$o->ro) of a readonly property fails unless it holds an object or may be re-initialized (__clone). */
static bool aop_readonly_fetch_fails(zend_object *zobj, zend_property_info *info)
{
	zval *slot;

	if (!info || !(info->flags & ZEND_ACC_READONLY)) {
		return false;
	}
	slot = OBJ_PROP(zobj, info->offset);
	return Z_TYPE_P(slot) == IS_UNDEF
		|| (Z_TYPE_P(slot) != IS_OBJECT && !(Z_PROP_FLAG_P(slot) & IS_PROP_REINITABLE));
}

/* The original handlers run with a private cache; only slot 2 (typed property info) is published. */
#define AOP_SLOT_DECL(cache_slot) \
	void *aop_tmp_slot[3] = {NULL, NULL, NULL}; \
	void **aop_slot = (cache_slot) ? aop_tmp_slot : NULL
#define AOP_SLOT_PUBLISH(cache_slot) do { \
		if (cache_slot) { \
			(cache_slot)[0] = NULL; \
			(cache_slot)[1] = NULL; \
			(cache_slot)[2] = aop_tmp_slot[2]; \
		} \
	} while (0)
/* }}} */

/* {{{ OriginalCall for fields */
static void aop_field_original_get(zend_object *obj, zend_string *name, zend_class_entry *scope,
		zend_long tag, HashTable *args, zval *return_value)
{
	zend_class_entry *old_scope = EG(fake_scope);
	zval rv, *p;

	(void) args;
	/* A NULL scope leaves the executed scope, AOP\OriginalCall, which sees public members only. */
	EG(fake_scope) = scope;
	ZVAL_UNDEF(&rv);
	p = aop_unwrapped_handlers(obj)->read_property(obj, name, (int) tag, NULL, &rv);
	EG(fake_scope) = old_scope;

	if (EG(exception)) {
		if (p == &rv) {
			zval_ptr_dtor(&rv);
		}
		return;
	}
	if (p == &rv) {
		ZVAL_COPY_VALUE(return_value, &rv);
		if (Z_ISREF_P(return_value)) {
			zend_unwrap_reference(return_value);
		}
	} else {
		ZVAL_COPY_DEREF(return_value, p);
	}
}

static void aop_field_original_set(zend_object *obj, zend_string *name, zend_class_entry *scope,
		zend_long tag, HashTable *args, zval *return_value)
{
	zend_class_entry *old_scope = EG(fake_scope);
	zval *value = NULL, tmp;

	(void) return_value;
	if (zend_hash_num_elements(args) > 0) {
		value = zend_hash_index_find(args, 0);
		if (!value) {
			ZEND_HASH_FOREACH_VAL(args, value) {
				break;
			} ZEND_HASH_FOREACH_END();
		}
	}
	if (!value) {
		zend_argument_count_error("%s::__invoke() expects [$value] for a field-set join point",
			ZSTR_VAL(aop_original_call_ce->name));
		return;
	}
	ZVAL_COPY_DEREF(&tmp, value);
	EG(fake_scope) = scope;
	if (tag) {
		/* The access was in a strict_types file. The write below runs from this internal frame, which
		 * the engine treats as coercive, so check the type strictly first (same TypeError as the VM's). */
		zend_property_info *info = zend_get_property_info(obj->ce, name, 1);
		if (info && info != ZEND_WRONG_PROPERTY_INFO && ZEND_TYPE_IS_SET(info->type)
				&& !(info->flags & ZEND_ACC_STATIC) && !zend_verify_property_type(info, &tmp, true)) {
			EG(fake_scope) = old_scope;
			zval_ptr_dtor(&tmp);
			return;
		}
	}
	aop_unwrapped_handlers(obj)->write_property(obj, name, &tmp, NULL);
	EG(fake_scope) = old_scope;
	zval_ptr_dtor(&tmp);
}
/* }}} */

/* {{{ wrapped handlers */
static zval *aop_field_read(zend_object *zobj, zend_string *name, int type, void **cache_slot, zval *rv)
{
	const zend_object_handlers *orig = aop_field_orig(zobj);
	zend_property_info *info;
	aop_jp jp;
	zval *ret;

	/* W/RW/UNSET reads only follow a get_property_ptr_ptr() that returned NULL, which already dispatched. */
	if ((type == BP_VAR_R || type == BP_VAR_IS) && aop_fields_active()
			&& aop_field_jp(&jp, zobj, name, aop_kind_get, &info)) {
		zend_execute_data *frame = aop_access_frame();
		zend_long flags = aop_decide_jp(&jp, frame);

		if (UNEXPECTED(EG(exception))) {
			return &EG(uninitialized_zval);
		}
		if ((flags & AOP_INTERCEPT) && AOP_G(registered)) {
			zval args, original, result;

			array_init(&args);
			aop_original_call_new_native(&original, aop_field_original_get, zobj, name, aop_access_scope(), type);
			aop_dispatch_jp(&jp, frame, zobj, NULL, NULL, &args, &original, &result);
			if (Z_ISUNDEF(result)) {
				return &EG(uninitialized_zval);
			}
			ZVAL_COPY_VALUE(rv, &result);
			return rv;
		}
	}

	{
		AOP_SLOT_DECL(cache_slot);
		ret = orig->read_property(zobj, name, type, aop_slot, rv);
		AOP_SLOT_PUBLISH(cache_slot);
	}
	return ret;
}

static zval *aop_field_write(zend_object *zobj, zend_string *name, zval *value, void **cache_slot)
{
	const zend_object_handlers *orig = aop_field_orig(zobj);
	zend_property_info *info;
	aop_jp jp;
	zval *ret;

	if (aop_fields_active() && aop_field_jp(&jp, zobj, name, aop_kind_set, &info)
			&& !aop_readonly_violation(zobj, info, name)) {
		zend_execute_data *frame = aop_access_frame();
		zend_long flags = aop_decide_jp(&jp, frame);

		if (UNEXPECTED(EG(exception))) {
			return &EG(error_zval);
		}
		if ((flags & AOP_INTERCEPT) && AOP_G(registered)) {
			zval args, val, original, result;

			ZVAL_COPY_DEREF(&val, value);
			array_init_size(&args, 1);
			add_next_index_zval(&args, &val); /* args owns val */
			aop_original_call_new_native(&original, aop_field_original_set, zobj, name, aop_access_scope(),
				frame && (frame->func->common.fn_flags & ZEND_ACC_STRICT_TYPES) ? 1 : 0);
			aop_dispatch_jp(&jp, frame, zobj, "value", &val, &args, &original, &result);
			if (Z_ISUNDEF(result)) {
				return &EG(error_zval);
			}
			zval_ptr_dtor(&result);
			return value;
		}
	}

	{
		AOP_SLOT_DECL(cache_slot);
		ret = orig->write_property(zobj, name, value, aop_slot);
		AOP_SLOT_PUBLISH(cache_slot);
	}
	return ret;
}

/* Opcodes that use get_property_ptr_ptr() for a read-modify-write and fall back to read_property() +
 * write_property() when it returns NULL. */
static zend_always_inline bool aop_is_compound_opcode(uint8_t opcode)
{
	switch (opcode) {
		case ZEND_ASSIGN_OBJ_OP:
		case ZEND_PRE_INC_OBJ:
		case ZEND_PRE_DEC_OBJ:
		case ZEND_POST_INC_OBJ:
		case ZEND_POST_DEC_OBJ:
			return true;
		default:
			return false;
	}
}

static zval *aop_field_ptr_ptr(zend_object *zobj, zend_string *name, int type, void **cache_slot)
{
	const zend_object_handlers *orig = aop_field_orig(zobj);
	zend_property_info *info;
	aop_jp jp;
	zval *ret;

	if (aop_fields_active() && aop_field_jp(&jp, zobj, name, aop_kind_get, &info)) {
		zend_execute_data *frame = aop_access_frame();
		zend_long flags;

		if (frame && frame->opline && aop_is_compound_opcode(frame->opline->opcode)) {
			/* $o->x += 1, $o->x++, ...: returning NULL makes the VM read and write through the
			 * handlers, which gives a get and a set join point. */
			flags = aop_decide_jp(&jp, frame);
			if (UNEXPECTED(EG(exception))) {
				return &EG(error_zval);
			}
			if (!(flags & AOP_INTERCEPT) && !aop_readonly_violation(zobj, info, name)) {
				jp.kind = aop_kind_set;
				flags = aop_decide_jp(&jp, frame);
				if (UNEXPECTED(EG(exception))) {
					return &EG(error_zval);
				}
			}
			if ((flags & AOP_INTERCEPT) && AOP_G(registered)) {
				return NULL;
			}
		} else {
			/* $o->x[] = 1, $r = &$o->x, foreach ($o->x as &$v), f($o->x) by reference, $o->x->y = 1:
			 * a get-only join point; the access then works on the property itself. */
			flags = aop_readonly_fetch_fails(zobj, info) ? 0 : aop_decide_jp(&jp, frame);
			if (UNEXPECTED(EG(exception))) {
				return &EG(error_zval);
			}
			if ((flags & AOP_INTERCEPT) && AOP_G(registered)) {
				zval args, original, result;

				array_init(&args);
				aop_original_call_new_native(&original, aop_field_original_get, zobj, name, aop_access_scope(), BP_VAR_IS);
				aop_dispatch_jp(&jp, frame, zobj, NULL, NULL, &args, &original, &result);
				if (Z_ISUNDEF(result)) {
					return &EG(error_zval);
				}
				zval_ptr_dtor(&result);
			}
		}
	}

	{
		AOP_SLOT_DECL(cache_slot);
		ret = orig->get_property_ptr_ptr(zobj, name, type, aop_slot);
		AOP_SLOT_PUBLISH(cache_slot);
	}
	return ret;
}

static zend_object *aop_field_clone(zend_object *old)
{
	const zend_object_handlers *orig = aop_field_orig(old);
	zend_object *new_obj;

	if (orig->clone_obj == zend_objects_clone_obj) {
		/* zend_objects_clone_obj() with the wrapped handlers installed before __clone() runs. */
		new_obj = zend_objects_new(old->ce);
		new_obj->handlers = old->handlers;
		if (new_obj->ce->default_properties_count) {
			zval *p = new_obj->properties_table;
			zval *end = p + new_obj->ce->default_properties_count;
			do {
				ZVAL_UNDEF(p);
				p++;
			} while (p != end);
		}
		zend_objects_clone_members(new_obj, old);
		return new_obj;
	}
	new_obj = orig->clone_obj(old);
	if (new_obj && new_obj->handlers == orig) {
		new_obj->handlers = old->handlers;
	}
	return new_obj;
}

/* The wrapped copy of std_object_handlers for objects of ce (created on first use, freed in post_deactivate). */
static zend_object_handlers *aop_field_handlers_for(zend_class_entry *ce)
{
	aop_field_handlers *fh;

	if (!AOP_G(field_handlers)) {
		ALLOC_HASHTABLE(AOP_G(field_handlers));
		zend_hash_init(AOP_G(field_handlers), 8, NULL, NULL, 0);
	}
	fh = zend_hash_index_find_ptr(AOP_G(field_handlers), (zend_ulong) (uintptr_t) ce);
	if (!fh) {
		fh = emalloc(sizeof(*fh));
		memcpy(&fh->h, &std_object_handlers, sizeof(zend_object_handlers));
		fh->orig = &std_object_handlers;
		fh->h.read_property = aop_field_read;
		fh->h.write_property = aop_field_write;
		fh->h.get_property_ptr_ptr = aop_field_ptr_ptr;
		fh->h.clone_obj = aop_field_clone;
		zend_hash_index_add_new_ptr(AOP_G(field_handlers), (zend_ulong) (uintptr_t) ce, fh);
	}
	return &fh->h;
}
/* }}} */

/* {{{ ZEND_NEW */
static zend_always_inline bool aop_class_probe(zend_class_entry *ce)
{
	aop_jp jp;

	memset(&jp, 0, sizeof(jp));
	jp.kind = aop_kind_get;
	jp.tag = aop_tag_probe;
	jp.class_ = ce;
	jp.name = ZSTR_CHAR('*');
	jp.internal = ce->type == ZEND_INTERNAL_CLASS;
	jp.no_caller = true;
	return aop_decide_jp(&jp, NULL) != 0;
}

static void aop_init_jp(aop_jp *jp, zend_class_entry *ce)
{
	memset(jp, 0, sizeof(*jp));
	jp->kind = aop_kind_init;
	jp->class_ = ce;
	jp->scope = ce;
	jp->name = aop_str_construct;
	jp->internal = ce->type == ZEND_INTERNAL_CLASS;
}

/* Releases a new object that never became fully constructed (like a failed constructor). */
static void aop_new_discard(zval *result)
{
	zend_object_store_ctor_failed(Z_OBJ_P(result));
	zval_ptr_dtor(result);
	ZVAL_UNDEF(result);
}

/*
 * ZEND_NEW. When the object needs wrapped handlers or an initialization join point, the handler is
 * replicated here (it is a short sequence of public API calls in 8.3: class fetch, object_init_ex(),
 * get_constructor(), push the constructor frame); otherwise the original handler runs.
 */
static int aop_new_handler(zend_execute_data *execute_data)
{
	const zend_op *opline = EX(opline);
	zend_class_entry *ce;
	zend_function *constructor;
	zend_execute_data *call;
	zval *result;
	bool wrap, init = false;
	aop_jp jp;

	if (aop_prev_new_handler) {
		int ret = aop_prev_new_handler(execute_data);
		if (ret != ZEND_USER_OPCODE_DISPATCH) {
			return ret;
		}
	}
	if (EXPECTED(!AOP_G(registered)) || AOP_G(in_matcher) || EG(exception)) {
		return ZEND_USER_OPCODE_DISPATCH;
	}

	result = EX_VAR(opline->result.var);
	if (opline->op1_type == IS_CONST) {
		ce = CACHED_PTR(opline->op2.num);
		if (UNEXPECTED(ce == NULL)) {
			ce = zend_fetch_class_by_name(Z_STR_P(RT_CONSTANT(opline, opline->op1)),
				Z_STR_P(RT_CONSTANT(opline, opline->op1) + 1),
				ZEND_FETCH_CLASS_DEFAULT | ZEND_FETCH_CLASS_EXCEPTION);
			if (UNEXPECTED(ce == NULL)) {
				/* zend_throw_exception_internal() already pointed EX(opline) at the exception handler. */
				ZVAL_UNDEF(result);
				return ZEND_USER_OPCODE_CONTINUE;
			}
			CACHE_PTR(opline->op2.num, ce);
		}
	} else if (opline->op1_type == IS_UNUSED) {
		ce = zend_fetch_class(NULL, opline->op1.num);
		if (UNEXPECTED(ce == NULL)) {
			ZVAL_UNDEF(result);
			return ZEND_USER_OPCODE_CONTINUE;
		}
	} else {
		ce = Z_CE_P(EX_VAR(opline->op1.var));
	}

	if (ce->ce_flags & (ZEND_ACC_INTERFACE | ZEND_ACC_TRAIT | ZEND_ACC_IMPLICIT_ABSTRACT_CLASS
			| ZEND_ACC_EXPLICIT_ABSTRACT_CLASS | ZEND_ACC_ENUM)) {
		return ZEND_USER_OPCODE_DISPATCH; /* the original handler raises the error */
	}
	if (aop_class_in_aop_ns(ce) || aop_current_code_in_aop_ns()) {
		return ZEND_USER_OPCODE_DISPATCH;
	}

	/* Only objects with the standard handlers are wrapped (not exceptions, closures, most internal classes). */
	wrap = ce->default_object_handlers == &std_object_handlers && aop_class_probe(ce);
	if (UNEXPECTED(EG(exception))) {
		ZVAL_UNDEF(result);
		return ZEND_USER_OPCODE_CONTINUE;
	}
	if (!ce->constructor) {
		aop_init_jp(&jp, ce);
		init = (aop_decide_jp(&jp, execute_data) & AOP_INTERCEPT) != 0;
		if (UNEXPECTED(EG(exception))) {
			ZVAL_UNDEF(result);
			return ZEND_USER_OPCODE_CONTINUE;
		}
	}
	if ((!wrap && !init) || !AOP_G(registered)) {
		return ZEND_USER_OPCODE_DISPATCH;
	}

	if (UNEXPECTED(object_init_ex(result, ce) != SUCCESS)) {
		ZVAL_UNDEF(result);
		return ZEND_USER_OPCODE_CONTINUE;
	}
	if (wrap && Z_OBJ_P(result)->handlers == &std_object_handlers) {
		Z_OBJ_P(result)->handlers = aop_field_handlers_for(ce);
	}

	constructor = Z_OBJ_HT_P(result)->get_constructor(Z_OBJ_P(result));
	if (constructor == NULL) {
		if (UNEXPECTED(EG(exception))) {
			aop_new_discard(result);
			return ZEND_USER_OPCODE_CONTINUE;
		}
		if (init) {
			/* initialization of a ctor-less class: before-style, OriginalCall is a no-op. */
			zval args, original, retval;

			array_init(&args);
			aop_original_call_new_native(&original, NULL, Z_OBJ_P(result), NULL, NULL, 0);
			aop_dispatch_jp(&jp, execute_data, Z_OBJ_P(result), NULL, NULL, &args, &original, &retval);
			if (Z_ISUNDEF(retval)) {
				aop_new_discard(result);
				return ZEND_USER_OPCODE_CONTINUE;
			}
			zval_ptr_dtor(&retval);
		}

		/* As the original: without arguments, skip over the DO_FCALL. */
		if (EXPECTED(opline->extended_value == 0 && (opline + 1)->opcode == ZEND_DO_FCALL)) {
			EX(opline) = opline + 2;
			return ZEND_USER_OPCODE_CONTINUE;
		}
		call = zend_vm_stack_push_call_frame(ZEND_CALL_FUNCTION, (zend_function *) &zend_pass_function,
			opline->extended_value, NULL);
	} else {
		if (EXPECTED(constructor->type == ZEND_USER_FUNCTION) && UNEXPECTED(!RUN_TIME_CACHE(&constructor->op_array))) {
			zend_init_func_run_time_cache(&constructor->op_array);
		}
		call = zend_vm_stack_push_call_frame(ZEND_CALL_FUNCTION | ZEND_CALL_RELEASE_THIS | ZEND_CALL_HAS_THIS,
			constructor, opline->extended_value, Z_OBJ_P(result));
		Z_ADDREF_P(result);
	}

	call->prev_execute_data = EX(call);
	EX(call) = call;
	EX(opline) = opline + 1;
	return ZEND_USER_OPCODE_CONTINUE;
}
/* }}} */

/* {{{ lifecycle */
void aop_fields_minit(void)
{
	aop_str_construct = zend_string_init_interned("__construct", sizeof("__construct") - 1, 1);
	aop_prev_new_handler = zend_get_user_opcode_handler(ZEND_NEW);
	zend_set_user_opcode_handler(ZEND_NEW, aop_new_handler);
}

void aop_fields_mshutdown(void)
{
	zend_set_user_opcode_handler(ZEND_NEW, aop_prev_new_handler);
}

void aop_fields_rinit(void)
{
	AOP_G(field_handlers) = NULL;
}

/* After zend_deactivate(): every object (and so every user of the wrapped handlers) is gone. */
void aop_fields_post_deactivate(void)
{
	if (AOP_G(field_handlers)) {
		void *fh;
		ZEND_HASH_FOREACH_PTR(AOP_G(field_handlers), fh) {
			efree(fh);
		} ZEND_HASH_FOREACH_END();
		zend_hash_destroy(AOP_G(field_handlers));
		FREE_HASHTABLE(AOP_G(field_handlers));
		AOP_G(field_handlers) = NULL;
	}
}
/* }}} */
