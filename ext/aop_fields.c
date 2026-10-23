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
 * ZEND_NEW is unchanged in 8.4/8.5 (only the order of an exception check after get_constructor()).
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
#include "aop_compat.h"
#if AOP_HAVE_LAZY_OBJECTS
# include "zend_lazy_objects.h"
#endif

/*
 * PHP 8.4/8.5 (verified against php-8.4.27 and php-8.5.12, whose property handlers are identical but for one
 * line): zend_std_read_property/write_property/get_property_ptr_ptr with property hooks, asymmetric
 * visibility (readonly is implicitly protected(set)) and lazy objects; zend_objects_clone_obj /
 * zend_lazy_object_clone; 8.5 zend_objects_clone_obj_with (clone with), which writes through
 * handlers->write_property, so "clone with" assignments are field-set join points.
 *
 * Hooked properties: an access from outside the property's own hooks is one field-get / field-set join point
 * around the whole hooked access; the hook body runs inside OriginalCall (the original handler calls it, and
 * aop_execute_ex() passes hook bodies through). Accesses to the backing value from inside the property's own
 * hooks (zend_std_*'s zend_should_call_hook() == false, also through parent::$p::get()) are no join points.
 * Write fetches ($o->p[] = 1, $o->p->q = 1, &$o->p) of a hooked property: get_property_ptr_ptr returns NULL
 * and the VM reads with BP_VAR_W, so that read is the field-get join point (value hooks: the dispatcher's
 * result is used as the value, and as without advice a non-object result is "Indirect modification"); a
 * by-reference get hook (&get) has no join point on write fetches (its reference cannot pass through
 * OriginalCall). The runtime cache: hooked properties use slot 1 for the offset/"simple get/read/write" bits
 * and slot 2 for the property info, as plain ones; slot 0 stays cleared, so the VM's hook fast paths
 * (ZEND_FETCH_OBJ_R simple get, ZEND_ASSIGN_OBJ simple write) never apply to wrapped objects.
 *
 * Lazy objects: created without ZEND_NEW (ReflectionClass::newLazyGhost/newLazyProxy), so not wrapped; a
 * wrapped object reset with resetAsLazyGhost()/resetAsLazyProxy() stays wrapped. Asking the matcher or
 * dispatching never initializes it; the original handler does (when the dispatcher proceeds). A proxy forwards
 * to its real instance with zend_std_* directly, so the instance's own wrapper never sees those accesses.
 */

static user_opcode_handler_t aop_prev_new_handler = NULL;
static zend_string *aop_str_construct = NULL;
/* &zend_pass_function as a zend_function (set in MINIT): pushing the dummy constructor frame through a
 * pointer the compiler cannot trace keeps GCC's -Warray-bounds quiet (zend_pass_function is only a
 * zend_internal_function, which is all the frame code reads of it). */
static zend_function *aop_pass_function = NULL;

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

/* EG(fake_scope) is const since 8.5; the descriptor and OriginalCall take a plain pointer and never write through it. */
static zend_always_inline zend_class_entry *aop_access_scope(void)
{
	return EG(fake_scope) ? (zend_class_entry *) EG(fake_scope) : zend_get_executed_scope();
}

/* The object whose slots hold the property values: obj itself, the real instance of an initialized lazy
 * proxy, or NULL when they are not known yet (an uninitialized lazy object, which initializes in the original). */
static zend_object *aop_slot_owner(zend_object *obj)
{
#if AOP_HAVE_LAZY_OBJECTS
	if (UNEXPECTED(zend_object_is_lazy(obj))) {
		zend_object *instance;

		if (!zend_lazy_object_initialized(obj)) {
			return NULL;
		}
		instance = zend_lazy_object_init(obj); /* initialized proxy: returns the instance, no side effects */
		return instance && instance->ce == obj->ce ? instance : NULL;
	}
#endif
	return obj;
}

#if AOP_HAVE_PROPERTY_HOOKS
/* Mirrors zend_should_call_hook() == false: an access to the backing value of info from inside one of its own
 * hooks (or a parent::$p::get()/set() trampoline) on the same object. */
static bool aop_is_backing_access(zend_object *zobj, const zend_property_info *info)
{
	zend_execute_data *ex = EG(current_execute_data);
	const zend_property_info *hook_info;
	zend_object *self;

	if (!info || !info->hooks || !ex || !ex->func || !(hook_info = ex->func->common.prop_info)) {
		return false;
	}
	if (hook_info->prototype != info->prototype || Z_TYPE(ex->This) != IS_OBJECT) {
		return false;
	}
	self = Z_OBJ(ex->This);
	if (self == zobj) {
		return true;
	}
	return zend_object_is_lazy_proxy(self) && zend_lazy_object_initialized(self)
		&& zend_lazy_object_init(self) == zobj;
}

/* A hooked access that fails anyway: reading a virtual property without get hook, writing a virtual property
 * without set hook, or writing without set access (private(set)/protected(set)). */
static bool aop_hooked_access_fails(const zend_property_info *info, bool write)
{
	if (!write) {
		return (info->flags & ZEND_ACC_VIRTUAL) && !info->hooks[ZEND_PROPERTY_HOOK_GET];
	}
	if ((info->flags & ZEND_ACC_VIRTUAL) && !info->hooks[ZEND_PROPERTY_HOOK_SET]) {
		return true;
	}
	return (info->flags & ZEND_ACC_PPP_SET_MASK) && !zend_asymmetric_property_has_set_access(info);
}
#endif

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
#if AOP_HAVE_PROPERTY_HOOKS
	if (info && info->hooks && (aop_is_backing_access(zobj, info)
			|| (kind == aop_kind_get && aop_hooked_access_fails(info, false)))) {
		return false;
	}
#endif
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

/* Mirrors the readonly (8.4+: and asymmetric visibility, hooked) checks of zend_std_write_property: true when
 * this write fails anyway. */
static bool aop_write_fails(zend_object *zobj, zend_property_info *info, zend_string *name)
{
	zend_object *owner;
	zval *slot;

	if (!info) {
		return false;
	}
#if PHP_VERSION_ID >= 80400
	if (info->hooks) {
		return aop_hooked_access_fails(info, true);
	}
	if (!(info->flags & (ZEND_ACC_READONLY | ZEND_ACC_PPP_SET_MASK)) || !(owner = aop_slot_owner(zobj))) {
		return false;
	}
	slot = OBJ_PROP(owner, info->offset);
	if (Z_TYPE_P(slot) == IS_UNDEF && !(Z_PROP_FLAG_P(slot) & IS_PROP_UNINIT) && owner->ce->__set
			&& !((*zend_get_property_guard(owner, name)) & ZEND_GUARD_PROPERTY_SET)) {
		return false; /* an unset() property: the write goes to __set() */
	}
	if ((info->flags & ZEND_ACC_READONLY) && Z_TYPE_P(slot) != IS_UNDEF && !(Z_PROP_FLAG_P(slot) & IS_PROP_REINITABLE)) {
		return true;
	}
	/* Public readonly properties are implicitly protected(set). */
	return (info->flags & ZEND_ACC_PPP_SET_MASK) && !zend_asymmetric_property_has_set_access(info);
#else
	zend_class_entry *scope;

	if (!(info->flags & ZEND_ACC_READONLY) || !(owner = aop_slot_owner(zobj))) {
		return false;
	}
	slot = OBJ_PROP(owner, info->offset);
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
#endif
}

/* A write fetch ($o->ro[] = 1, &$o->ro) of a readonly property fails unless it holds an object (8.3: or may be
 * re-initialized, in __clone); 8.4+ the same for a property without set access. */
static bool aop_write_fetch_fails(zend_object *zobj, zend_property_info *info)
{
	zend_object *owner;
	zval *slot;

	if (!info) {
		return false;
	}
#if PHP_VERSION_ID >= 80400
	if (info->hooks || !(info->flags & (ZEND_ACC_READONLY | ZEND_ACC_PPP_SET_MASK))
			|| (!(info->flags & ZEND_ACC_READONLY) && zend_asymmetric_property_has_set_access(info))
			|| !(owner = aop_slot_owner(zobj))) {
		return false;
	}
	slot = OBJ_PROP(owner, info->offset);
	return Z_TYPE_P(slot) != IS_OBJECT;
#else
	if (!(info->flags & ZEND_ACC_READONLY) || !(owner = aop_slot_owner(zobj))) {
		return false;
	}
	slot = OBJ_PROP(owner, info->offset);
	return Z_TYPE_P(slot) == IS_UNDEF
		|| (Z_TYPE_P(slot) != IS_OBJECT && !(Z_PROP_FLAG_P(slot) & IS_PROP_REINITABLE));
#endif
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
	AOP_FAKE_SCOPE_T *old_scope = EG(fake_scope);
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
	AOP_FAKE_SCOPE_T *old_scope = EG(fake_scope);
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
#if AOP_HAVE_PROPERTY_HOOKS
		zend_property_info hook_param;

		if (info && info != ZEND_WRONG_PROPERTY_INFO && info->hooks && info->hooks[ZEND_PROPERTY_HOOK_SET]) {
			/* The set hook's $value parameter is checked instead (its type may be wider than the
			 * property's). Same rules as the hook's RECV in strict mode; the message names the property. */
			hook_param = *info;
			hook_param.type = info->hooks[ZEND_PROPERTY_HOOK_SET]->common.arg_info[0].type;
			info = &hook_param;
		}
#endif
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
/* A write fetch (BP_VAR_W/RW/UNSET read) of a hooked property, which get_property_ptr_ptr() leaves to
 * read_property(); not for by-reference get hooks, whose reference cannot pass through OriginalCall. */
static zend_always_inline bool aop_hooked_write_fetch(const zend_property_info *info)
{
#if AOP_HAVE_PROPERTY_HOOKS
	const zend_function *get;

	if (!AOP_PROP_IS_HOOKED(info)) {
		return false;
	}
	get = info->hooks[ZEND_PROPERTY_HOOK_GET];
	return !get || !(get->common.fn_flags & ZEND_ACC_RETURN_REFERENCE);
#else
	(void) info;
	return false;
#endif
}

static zval *aop_field_read(zend_object *zobj, zend_string *name, int type, void **cache_slot, zval *rv)
{
	const zend_object_handlers *orig = aop_field_orig(zobj);
	zend_property_info *info;
	aop_jp jp;
	zval *ret;

	/* W/RW/UNSET reads only follow a get_property_ptr_ptr() that returned NULL, which already dispatched,
	 * except for hooked properties (see the top of the file). */
	if (aop_fields_active() && aop_field_jp(&jp, zobj, name, aop_kind_get, &info)
			&& (type == BP_VAR_R || type == BP_VAR_IS || aop_hooked_write_fetch(info))) {
		zend_execute_data *frame = aop_access_frame();
		zend_long flags = aop_decide_jp(&jp, frame);
		bool fetch = type != BP_VAR_R && type != BP_VAR_IS;

		if (UNEXPECTED(EG(exception))) {
			return fetch ? &EG(error_zval) : &EG(uninitialized_zval);
		}
		if ((flags & AOP_INTERCEPT) && AOP_G(registered)) {
			zval args, original, result;

			array_init(&args);
			aop_original_call_new_native(&original, aop_field_original_get, zobj, name, aop_access_scope(),
				fetch ? BP_VAR_R : type);
			aop_dispatch_jp(&jp, frame, zobj, NULL, NULL, &args, &original, &result);
			if (Z_ISUNDEF(result)) {
				return fetch ? &EG(error_zval) : &EG(uninitialized_zval);
			}
			ZVAL_COPY_VALUE(rv, &result);
			if (fetch && Z_TYPE_P(rv) != IS_OBJECT) {
				/* As zend_std_read_property() for a hooked property fetched for writing. */
				zend_throw_error(NULL, "Indirect modification of %s::$%s is not allowed",
					ZSTR_VAL(zobj->ce->name), ZSTR_VAL(name));
			}
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
			&& !aop_write_fails(zobj, info, name)) {
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
			if (!(flags & AOP_INTERCEPT) && !aop_write_fails(zobj, info, name)) {
				jp.kind = aop_kind_set;
				flags = aop_decide_jp(&jp, frame);
				if (UNEXPECTED(EG(exception))) {
					return &EG(error_zval);
				}
			}
			if ((flags & AOP_INTERCEPT) && AOP_G(registered)) {
				return NULL;
			}
		} else if (!AOP_PROP_IS_HOOKED(info)) {
			/* $o->x[] = 1, $r = &$o->x, foreach ($o->x as &$v), f($o->x) by reference, $o->x->y = 1:
			 * a get-only join point; the access then works on the property itself. (A hooked property
			 * returns NULL below and is read by read_property() with BP_VAR_W, which dispatches.) */
			flags = aop_write_fetch_fails(zobj, info) ? 0 : aop_decide_jp(&jp, frame);
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

#if AOP_HAVE_LAZY_OBJECTS
	if (orig->clone_obj == zend_objects_clone_obj && UNEXPECTED(zend_object_is_lazy(old))) {
		/* As zend_lazy_object_clone(): an uninitialized ghost is initialized first, then cloned like any
		 * object (below); a proxy is cloned by the engine (proxy and instance), then wrapped. */
		if (!zend_object_is_lazy_proxy(old)) {
			if (!zend_lazy_object_init(old)) {
				zval zv;
				/* Clone handlers must return an object; it is discarded because of the exception. */
				object_init_ex(&zv, old->ce);
				GC_ADD_FLAGS(Z_OBJ(zv), IS_OBJ_DESTRUCTOR_CALLED);
				return Z_OBJ(zv);
			}
		} else {
			new_obj = orig->clone_obj(old);
			if (new_obj && new_obj->handlers == orig) {
				new_obj->handlers = old->handlers;
			}
			return new_obj;
		}
	}
#endif
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
		call = zend_vm_stack_push_call_frame(ZEND_CALL_FUNCTION, aop_pass_function,
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
	aop_pass_function = (zend_function *) &zend_pass_function;
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
