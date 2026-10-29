/*
 * aop extension: matcher decisions and their cache, join point descriptors (contract §2),
 * dispatch to the PHP runtime, and the AOP\OriginalCall class (contract §1.3).
 *
 * Nothing in here depends on the layout of VM frames beyond the public zend_execute_data
 * fields; the version specific frame handling is in aop_hook.c.
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "zend_weakrefs.h"
#include "php_aop.h"

/* {{{ namespace and frame helpers */
/* Runtime class of the frame's $this, else its called scope (NULL for plain functions and top-level code). */
zend_class_entry *aop_frame_class(const zend_execute_data *ex)
{
	if (Z_TYPE(ex->This) == IS_OBJECT) {
		return Z_OBJCE(ex->This);
	}
	return Z_CE(ex->This);
}

/* The nearest frame below ex that runs a function (user or internal), or NULL. */
zend_execute_data *aop_caller_frame(zend_execute_data *ex)
{
	zend_execute_data *prev = ex->prev_execute_data;

	while (prev && !prev->func) {
		prev = prev->prev_execute_data;
	}
	return prev;
}

static zend_always_inline bool aop_function_in_aop_ns(const zend_function *fn)
{
	return fn->common.scope
		? aop_class_in_aop_ns(fn->common.scope)
		: aop_name_in_aop_ns(fn->common.function_name);
}

/*
 * True when the code that called ex belongs to the runtime (namespace AOP\). Internal frames in between
 * (call_user_func, array_map, ReflectionMethod::invoke, ...) are looked through, so a call the runtime
 * makes through an internal function is still the runtime's.
 */
bool aop_caller_in_aop_ns(zend_execute_data *ex)
{
	zend_execute_data *frame;

	for (frame = ex->prev_execute_data; frame; frame = frame->prev_execute_data) {
		zend_function *fn = frame->func;
		if (!fn) {
			continue;
		}
		if (aop_function_in_aop_ns(fn)) {
			return true;
		}
		if (ZEND_USER_CODE(fn->type)) {
			return false;
		}
	}
	return false;
}

static void aop_unwrap_reference(zval *zv)
{
	if (Z_ISREF_P(zv)) {
		zval tmp;
		ZVAL_COPY(&tmp, Z_REFVAL_P(zv));
		zval_ptr_dtor(zv);
		ZVAL_COPY_VALUE(zv, &tmp);
	}
}

static zend_always_inline bool aop_is_constructor(const zend_function *func)
{
	return func->common.scope != NULL
		&& func->common.function_name != NULL
		&& zend_string_equals_literal_ci(func->common.function_name, "__construct");
}
/* }}} */

/* {{{ descriptors */
typedef enum {
	AOP_DESC_MATCH,        /* matcher, caller => null */
	AOP_DESC_MATCH_CALLER, /* matcher, caller without line/object */
	AOP_DESC_FULL          /* dispatcher */
} aop_desc_mode;

static zend_string *aop_k_kind;
static zend_string *aop_k_class;
static zend_string *aop_k_scope;
static zend_string *aop_k_name;
static zend_string *aop_k_static;
static zend_string *aop_k_internal;
static zend_string *aop_k_caller;
static zend_string *aop_k_outermost;
static zend_string *aop_k_function;
static zend_string *aop_k_file;
static zend_string *aop_k_line;
static zend_string *aop_k_object;
static zend_string *aop_v_ctor, *aop_v_method, *aop_v_function;

static zend_always_inline void aop_put(zval *arr, zend_string *key, zval *zv)
{
	zend_hash_add_new(Z_ARRVAL_P(arr), key, zv);
}

static zend_always_inline void aop_put_str(zval *arr, zend_string *key, zend_string *v)
{
	zval zv;
	ZVAL_STR(&zv, v);
	aop_put(arr, key, &zv);
}

static zend_always_inline void aop_put_null(zval *arr, zend_string *key)
{
	zval zv;
	ZVAL_NULL(&zv);
	aop_put(arr, key, &zv);
}

static zend_always_inline void aop_put_bool(zval *arr, zend_string *key, bool v)
{
	zval zv;
	ZVAL_BOOL(&zv, v);
	aop_put(arr, key, &zv);
}

static zend_always_inline void aop_put_long(zval *arr, zend_string *key, zend_long v)
{
	zval zv;
	ZVAL_LONG(&zv, v);
	aop_put(arr, key, &zv);
}

static void aop_add_class_name(zval *arr, zend_string *key, const zend_class_entry *ce)
{
	if (ce) {
		aop_put_str(arr, key, zend_string_copy(ce->name));
	} else {
		aop_put_null(arr, key);
	}
}

static void aop_caller_array(zval *out, zend_execute_data *caller, bool full)
{
	zend_function *fn = caller->func;

	array_init_size(out, full ? 6 : 4);
	aop_add_class_name(out, aop_k_class, aop_frame_class(caller));
	aop_add_class_name(out, aop_k_scope, fn->common.scope);
	if (fn->common.function_name) {
		aop_put_str(out, aop_k_function, zend_string_copy(fn->common.function_name));
	} else {
		aop_put_null(out, aop_k_function);
	}
	if (ZEND_USER_CODE(fn->type) && fn->op_array.filename) {
		aop_put_str(out, aop_k_file, zend_string_copy(fn->op_array.filename));
	} else {
		aop_put_null(out, aop_k_file);
	}
	if (full) {
		if (ZEND_USER_CODE(fn->type) && caller->opline) {
			aop_put_long(out, aop_k_line, caller->opline->lineno);
		} else {
			aop_put_null(out, aop_k_line);
		}
		if (Z_TYPE(caller->This) == IS_OBJECT) {
			zval obj;
			ZVAL_OBJ_COPY(&obj, Z_OBJ(caller->This));
			aop_put(out, aop_k_object, &obj);
		} else {
			aop_put_null(out, aop_k_object);
		}
	}
}

/*
 * The join point descriptor of contract §2 for an execution of func.
 * caller is the frame returned by aop_caller_frame() (only used for the CALLER and FULL modes);
 * it is reported as null unless it runs user code.
 */
static void aop_descriptor(zval *d, zend_function *func, zend_class_entry *called,
		zend_execute_data *caller, aop_desc_mode mode, zend_object *this_obj)
{
	bool is_ctor = aop_is_constructor(func);
	zend_string *kind = is_ctor ? aop_v_ctor : (func->common.scope ? aop_v_method : aop_v_function);

	array_init_size(d, 8);
	{ zval kv; ZVAL_INTERNED_STR(&kv, kind); aop_put(d, aop_k_kind, &kv); }
	aop_add_class_name(d, aop_k_class, func->common.scope ? called : NULL);
	aop_add_class_name(d, aop_k_scope, func->common.scope);
	aop_put_str(d, aop_k_name, zend_string_copy(func->common.function_name));
	aop_put_bool(d, aop_k_static, (func->common.fn_flags & ZEND_ACC_STATIC) != 0);
	aop_put_bool(d, aop_k_internal, func->type == ZEND_INTERNAL_FUNCTION);

	if (mode != AOP_DESC_MATCH && caller && ZEND_USER_CODE(caller->func->type)) {
		zval c;
		aop_caller_array(&c, caller, mode == AOP_DESC_FULL);
		aop_put(d, aop_k_caller, &c);
	} else {
		aop_put_null(d, aop_k_caller);
	}

	if (mode == AOP_DESC_FULL && is_ctor) {
		/* Not outermost when called from a constructor running on the same object (parent::__construct()). */
		bool outermost = true;
		if (caller && this_obj
				&& aop_is_constructor(caller->func)
				&& Z_TYPE(caller->This) == IS_OBJECT
				&& Z_OBJ(caller->This) == this_obj) {
			outermost = false;
		}
		aop_put_bool(d, aop_k_outermost, outermost);
	}
}
/* }}} */

/* {{{ matcher and decision cache */
typedef struct {
	const void *ce;
	const void *func;
} aop_key;

typedef struct {
	const void *ce;
	const void *func;
	const void *caller_class;
	const void *caller_scope;
	/* opcodes of the calling function (shared by every closure object of one declaration);
	 * NULL for top-level/include/eval code, which is identified by its file name appended to the key. */
	const void *caller_code;
} aop_caller_key;

/*
 * Reports the pending matcher exception as a warning and clears it. target names the join point
 * ("Class::method()", "field-get Class::$prop", ...), consequence what happens instead.
 */
static void aop_report_matcher_exception_ex(const char *target, const char *consequence)
{
	zend_object *ex = EG(exception);
	zval tmp, *msg;
	zend_string *message;

	GC_ADDREF(ex);
	zend_clear_exception();
	msg = zend_read_property_ex(zend_get_exception_base(ex), ex, ZSTR_KNOWN(ZEND_STR_MESSAGE), 1, &tmp);
	message = zval_get_string(msg);
	zend_error(E_WARNING, "AOP\\Engine: the matcher threw %s (%s) for %s; %s",
		ZSTR_VAL(ex->ce->name), ZSTR_VAL(message), target, consequence);
	zend_string_release(message);
	OBJ_RELEASE(ex);
}

static void aop_report_matcher_exception(zend_function *func, zend_class_entry *called)
{
	zend_string *target = zend_strpprintf(0, "%s%s%s()",
		func->common.scope && called ? ZSTR_VAL(called->name) : "",
		func->common.scope ? "::" : "",
		ZSTR_VAL(func->common.function_name));
	aop_report_matcher_exception_ex(ZSTR_VAL(target), "the call is not intercepted");
	zend_string_release(target);
}

/* Calls the matcher with interception suspended. Returns false, with the exception still pending, when it threw. */
static bool aop_invoke_matcher(zval *descriptor, zend_long *result)
{
	zend_fcall_info_cache fcc;
	zval retval;

	/* Local copy: the matcher may unregister or re-register while it runs. */
	fcc = AOP_G(matcher);
	if (fcc.object) {
		GC_ADDREF(fcc.object);
	}
	if (fcc.closure) {
		GC_ADDREF(fcc.closure);
	}

	AOP_G(in_matcher)++;
	zend_call_known_fcc(&fcc, &retval, 1, descriptor, NULL);
	AOP_G(in_matcher)--;

	if (fcc.closure) {
		OBJ_RELEASE(fcc.closure);
	}
	if (fcc.object) {
		OBJ_RELEASE(fcc.object);
	}

	if (UNEXPECTED(EG(exception))) {
		zval_ptr_dtor(&retval);
		return false;
	}

	*result = zval_get_long(&retval);
	zval_ptr_dtor(&retval);
	return true;
}

/* Calls the matcher with interception suspended. Returns false (decision 0, not cached) when it threw. */
static bool aop_call_matcher(zval *descriptor, zend_long *result, zend_function *func, zend_class_entry *called)
{
	if (!AOP_G(registered)) {
		return false;
	}
	if (!aop_invoke_matcher(descriptor, result)) {
		aop_report_matcher_exception(func, called);
		return false;
	}
	return true;
}

static zend_string *aop_caller_key_new(zend_function *func, zend_class_entry *called, zend_execute_data *caller)
{
	zend_function *cfn = caller->func;
	zend_string *file = NULL;
	aop_caller_key k;
	zend_string *key;

	memset(&k, 0, sizeof(k));
	k.ce = called;
	k.func = func;
	k.caller_class = aop_frame_class(caller);
	k.caller_scope = cfn->common.scope;
	if (cfn->common.function_name) {
		k.caller_code = cfn->op_array.opcodes;
	} else {
		file = cfn->op_array.filename;
	}

	key = zend_string_alloc(sizeof(k) + (file ? ZSTR_LEN(file) : 0), 0);
	memcpy(ZSTR_VAL(key), &k, sizeof(k));
	if (file) {
		memcpy(ZSTR_VAL(key) + sizeof(k), ZSTR_VAL(file), ZSTR_LEN(file));
	}
	ZSTR_VAL(key)[ZSTR_LEN(key)] = '\0';
	return key;
}

/*
 * The decision for an execution of func on class called (NULL for functions) called from caller
 * (the frame returned by aop_caller_frame()), following the protocol of contract §1.1.
 */
zend_long aop_decide(zend_function *func, zend_class_entry *called, zend_execute_data *caller)
{
	aop_key key;
	aop_fast_decision *slot = aop_fast_decision_slot(func, called);
	zval *cached, v;
	zend_long flags;

	key.ce = called;
	key.func = func;
	if (EXPECTED(slot->func == func && slot->called == called)) {
		flags = slot->flags;
	} else if ((cached = zend_hash_str_find(&AOP_G(decisions), (const char *) &key, sizeof(key)))) {
		flags = Z_LVAL_P(cached);
		slot->func = func;
		slot->called = called;
		slot->flags = flags;
	} else {
		zval d;
		bool ok;

		aop_descriptor(&d, func, called, NULL, AOP_DESC_MATCH, NULL);
		ok = aop_call_matcher(&d, &flags, func, called);
		zval_ptr_dtor(&d);
		if (!ok) {
			return 0;
		}
		ZVAL_LONG(&v, flags);
		zend_hash_str_update(&AOP_G(decisions), (const char *) &key, sizeof(key), &v);
		slot->func = func;
		slot->called = called;
		slot->flags = flags;
	}

	if (EXPECTED(!(flags & AOP_CALLER_SENSITIVE))) {
		return flags;
	}

	/* An internal or missing caller is described as caller => null, which is what the first question asked. */
	if (!caller || !ZEND_USER_CODE(caller->func->type)) {
		return flags & ~AOP_CALLER_SENSITIVE;
	}

	{
		zend_string *ckey = aop_caller_key_new(func, called, caller);
		zval d;
		bool ok;

		cached = zend_hash_find(&AOP_G(caller_decisions), ckey);
		if (cached) {
			zend_string_release_ex(ckey, 0);
			return Z_LVAL_P(cached);
		}

		aop_descriptor(&d, func, called, caller, AOP_DESC_MATCH_CALLER, NULL);
		ok = aop_call_matcher(&d, &flags, func, called);
		zval_ptr_dtor(&d);
		if (!ok) {
			zend_string_release_ex(ckey, 0);
			return 0;
		}
		flags &= ~AOP_CALLER_SENSITIVE;
		ZVAL_LONG(&v, flags);
		zend_hash_update(&AOP_G(caller_decisions), ckey, &v);
		zend_string_release_ex(ckey, 0);
		return flags;
	}
}
/* }}} */

static void aop_bypass_generator(zend_object *generator);

/* {{{ AOP\OriginalCall */
typedef struct {
	zend_function *func;
	zend_class_entry *called_scope;
	zend_object *this_obj;
	/* stream G: join points that are no calls (fields, handlers, ctor-less initialization) */
	bool is_native;
	aop_original_native_fn native;
	zend_string *native_name;
	zend_class_entry *native_scope;
	zend_long native_tag;
	zend_object std;
} aop_original_call;

static zend_object_handlers aop_original_call_handlers;

static zend_always_inline aop_original_call *aop_original_call_from_obj(zend_object *obj)
{
	return (aop_original_call *) ((char *) obj - XtOffsetOf(aop_original_call, std));
}

static zend_object *aop_original_call_create(zend_class_entry *ce)
{
	aop_original_call *oc = zend_object_alloc(sizeof(aop_original_call), ce);

	oc->func = NULL;
	oc->called_scope = NULL;
	oc->this_obj = NULL;
	oc->is_native = false;
	oc->native = NULL;
	oc->native_name = NULL;
	oc->native_scope = NULL;
	oc->native_tag = 0;
	zend_object_std_init(&oc->std, ce);
	object_properties_init(&oc->std, ce);
	oc->std.handlers = &aop_original_call_handlers;
	return &oc->std;
}

static void aop_original_call_free(zend_object *obj)
{
	aop_original_call *oc = aop_original_call_from_obj(obj);

	if (oc->this_obj) {
		zend_object *this_obj = oc->this_obj;
		oc->this_obj = NULL;
		OBJ_RELEASE(this_obj);
	}
	if (oc->native_name) {
		zend_string_release(oc->native_name);
		oc->native_name = NULL;
	}
	zend_object_std_dtor(obj);
}

static HashTable *aop_original_call_get_gc(zend_object *obj, zval **table, int *n)
{
	aop_original_call *oc = aop_original_call_from_obj(obj);

	if (oc->this_obj) {
		zend_get_gc_buffer *buf = zend_get_gc_buffer_create();
		zend_get_gc_buffer_add_obj(buf, oc->this_obj);
		zend_get_gc_buffer_use(buf, table, n);
	} else {
		*table = NULL;
		*n = 0;
	}
	return obj->properties;
}

static zend_function *aop_original_call_get_constructor(zend_object *obj)
{
	zend_throw_error(NULL, "Cannot directly construct %s", ZSTR_VAL(obj->ce->name));
	return NULL;
}

/*
 * Creates the OriginalCall for one dispatch. The function needs no reference: user and internal
 * functions and methods live until the end of the request (closures and trampolines are never advised).
 */
static void aop_original_call_new(zval *zv, zend_function *func, zend_class_entry *called, zend_object *this_obj)
{
	aop_original_call *oc;

	object_init_ex(zv, aop_original_call_ce);
	oc = aop_original_call_from_obj(Z_OBJ_P(zv));
	oc->func = func;
	oc->called_scope = called;
	if (this_obj) {
		GC_ADDREF(this_obj);
		oc->this_obj = this_obj;
	}
}

/*
 * stream G: an OriginalCall for a join point that is no call. fn (NULL: a no-op returning null) receives
 * this_obj, name, scope and tag as given here; name is copied, this_obj referenced.
 */
void aop_original_call_new_native(zval *zv, aop_original_native_fn fn, zend_object *this_obj,
		zend_string *name, zend_class_entry *scope, zend_long tag)
{
	aop_original_call *oc;

	object_init_ex(zv, aop_original_call_ce);
	oc = aop_original_call_from_obj(Z_OBJ_P(zv));
	oc->is_native = true;
	oc->native = fn;
	oc->native_name = name ? zend_string_copy(name) : NULL;
	oc->native_scope = scope;
	oc->native_tag = tag;
	if (this_obj) {
		GC_ADDREF(this_obj);
		oc->this_obj = this_obj;
	}
}

ZEND_METHOD(AOP_OriginalCall, __construct)
{
	(void) return_value;
	ZEND_PARSE_PARAMETERS_NONE();
	zend_throw_error(NULL, "Cannot directly construct %s", ZSTR_VAL(aop_original_call_ce->name));
}

ZEND_METHOD(AOP_OriginalCall, __invoke)
{
	HashTable *args;
	aop_original_call *oc;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY_HT(args)
	ZEND_PARSE_PARAMETERS_END();

	oc = aop_original_call_from_obj(Z_OBJ_P(ZEND_THIS));
	if (oc->is_native) {
		if (oc->native) {
			oc->native(oc->this_obj, oc->native_name, oc->native_scope, oc->native_tag, args, return_value);
		}
		if (Z_ISUNDEF_P(return_value) || EG(exception)) {
			zval_ptr_dtor(return_value);
			ZVAL_NULL(return_value);
		}
		return;
	}
	if (UNEXPECTED(!oc->func)) {
		zend_throw_error(NULL, "%s is not initialized", ZSTR_VAL(aop_original_call_ce->name));
		RETURN_THROWS();
	}

	/*
	 * zend_call_function() takes int keys of named_params as positional arguments (in order) and string
	 * keys as named ones, and passes references through for by-reference parameters, which is exactly
	 * contract §1.3. Setting the function handler directly skips the visibility check.
	 */
	AOP_G(bypass) = oc->func;
	zend_call_known_function(oc->func, oc->this_obj, oc->called_scope, return_value, 0, NULL,
		zend_hash_num_elements(args) ? args : NULL);
	AOP_G(bypass) = NULL;
	if (aop_interception_mode == AOP_MODE_WOVEN && (oc->func->common.fn_flags & ZEND_ACC_GENERATOR)
			&& Z_TYPE_P(return_value) == IS_OBJECT) {
		aop_bypass_generator(Z_OBJ_P(return_value));
	}

	if (Z_ISUNDEF_P(return_value)) {
		ZVAL_NULL(return_value);
	} else {
		aop_unwrap_reference(return_value);
	}
}
/* }}} */

/* {{{ woven backend: generators created by OriginalCall */
/*
 * The woven prologue of a generator function runs on the first resume, long after OriginalCall::__invoke()
 * cleared the single-shot bypass. The generators it created are therefore remembered, by identity: the
 * weak keys drop an entry when its generator is destroyed unstarted (so a reused address never matches),
 * and AOP\Internal\intercept() consumes the entry of the generator it runs in.
 */
static void aop_bypass_generator(zend_object *generator)
{
	zval marker;

	if (!AOP_G(bypassed_generators)) {
		ALLOC_HASHTABLE(AOP_G(bypassed_generators));
		zend_hash_init(AOP_G(bypassed_generators), 8, NULL, NULL, 0);
	}
	ZVAL_TRUE(&marker);
	zend_weakrefs_hash_add(AOP_G(bypassed_generators), generator, &marker);
}

void aop_bypassed_generators_free(void)
{
	HashTable *ht = AOP_G(bypassed_generators);

	if (!ht) {
		return;
	}
	/* Unregister the weak keys before the table goes away. */
	while (zend_hash_num_elements(ht)) {
		zend_ulong key = 0;
		ZEND_HASH_FOREACH_NUM_KEY(ht, key) {
			break;
		} ZEND_HASH_FOREACH_END();
		zend_weakrefs_hash_del(ht, zend_weakref_key_to_object(key));
	}
	zend_hash_destroy(ht);
	FREE_HASHTABLE(ht);
	AOP_G(bypassed_generators) = NULL;
}
/* }}} */

/* {{{ dispatch */
/*
 * Calls the dispatcher with params (descriptor, $this, args, original), releases the params and
 * stores the result in retval (UNDEF when it threw).
 */
static void aop_call_dispatcher(zval *params, zval *retval)
{
	zend_fcall_info_cache fcc;
	zend_function *handler;

	fcc = AOP_G(dispatcher);
	if (fcc.object) {
		GC_ADDREF(fcc.object);
	}
	if (fcc.closure) {
		GC_ADDREF(fcc.closure);
	}

	/* A dispatcher that is itself a (non-closure) user method is never intercepted. */
	handler = fcc.function_handler;
	if (handler->type == ZEND_USER_FUNCTION
			&& !(handler->common.fn_flags & (ZEND_ACC_CLOSURE | ZEND_ACC_CALL_VIA_TRAMPOLINE))) {
		AOP_G(bypass) = handler;
	}
	zend_call_known_fcc(&fcc, retval, 4, params, NULL);
	AOP_G(bypass) = NULL;

	if (fcc.closure) {
		OBJ_RELEASE(fcc.closure);
	}
	if (fcc.object) {
		OBJ_RELEASE(fcc.object);
	}

	zval_ptr_dtor(&params[0]);
	zval_ptr_dtor(&params[1]);
	zval_ptr_dtor(&params[2]);
	zval_ptr_dtor(&params[3]);

	if (EG(exception)) {
		zval_ptr_dtor(retval);
		ZVAL_UNDEF(retval);
	} else {
		aop_unwrap_reference(retval);
	}
}

/*
 * Calls the dispatcher for an advised execution of func. ex is the advised frame (still current),
 * this_obj its $this (NULL for static methods and functions). Takes over args. retval receives the
 * dispatcher's result (UNDEF when it threw).
 */
void aop_dispatch(zend_execute_data *ex, zend_function *func, zend_class_entry *called,
		zend_object *this_obj, zval *args, zval *retval)
{
	zval params[4];

	/* ex is the current frame in the hook backend, the caller of AOP\Internal\intercept() in the woven one. */
	AOP_CHECK(AOP_G(in_matcher) == 0);

	aop_descriptor(&params[0], func, called, aop_caller_frame(ex), AOP_DESC_FULL, this_obj);
	if (this_obj) {
		ZVAL_OBJ_COPY(&params[1], this_obj);
	} else {
		ZVAL_NULL(&params[1]);
	}
	ZVAL_COPY_VALUE(&params[2], args);
	aop_original_call_new(&params[3], func, func->common.scope ? called : NULL, this_obj);

	aop_call_dispatcher(params, retval);
}
/* }}} */

/* {{{ stream G: generic join points (fields, handlers, ctor-less initialization) */
/*
 * Same decision protocol (contract §1.1) and dispatcher call as for executions, for join points that
 * are described by an aop_jp instead of a zend_function. Their decisions live in their own tables
 * (jp_decisions, jp_caller_decisions), which reset() and unregister() clear together with the others.
 */
typedef struct {
	const void *tag;
	const void *class_;
	const void *scope;
	const void *id1;
	const void *id2;
	size_t name_len;
	/* caller identity (second level only) */
	const void *caller_class;
	const void *caller_scope;
	const void *caller_code;
} aop_jp_key;

#define AOP_JP_KEY_BUF 192

/* Builds the cache key into buf (or a new allocation when it does not fit). Returns its length. */
static size_t aop_jp_key_build(const aop_jp *jp, zend_execute_data *caller, char *buf, char **out)
{
	aop_jp_key k;
	zend_string *file = NULL;
	size_t len;
	char *p;

	memset(&k, 0, sizeof(k));
	k.tag = jp->tag ? jp->tag : (const void *) jp->kind;
	k.class_ = jp->class_;
	k.scope = jp->scope;
	k.id1 = jp->id1;
	k.id2 = jp->id2;
	k.name_len = ZSTR_LEN(jp->name);
	if (caller) {
		zend_function *cfn = caller->func;
		k.caller_class = aop_frame_class(caller);
		k.caller_scope = cfn->common.scope;
		if (cfn->common.function_name) {
			k.caller_code = cfn->op_array.opcodes;
		} else {
			file = cfn->op_array.filename;
		}
	}

	len = sizeof(k) + ZSTR_LEN(jp->name) + (file ? ZSTR_LEN(file) : 0);
	p = len <= AOP_JP_KEY_BUF ? buf : emalloc(len);
	memcpy(p, &k, sizeof(k));
	memcpy(p + sizeof(k), ZSTR_VAL(jp->name), ZSTR_LEN(jp->name));
	if (file) {
		memcpy(p + sizeof(k) + ZSTR_LEN(jp->name), ZSTR_VAL(file), ZSTR_LEN(file));
	}
	*out = p;
	return len;
}

static void aop_jp_descriptor(zval *d, const aop_jp *jp, zend_execute_data *caller, aop_desc_mode mode)
{
	array_init_size(d, 9);
	{ zval kv; ZVAL_STRING(&kv, jp->kind); aop_put(d, aop_k_kind, &kv); }
	aop_add_class_name(d, aop_k_class, jp->class_);
	aop_add_class_name(d, aop_k_scope, jp->scope);
	aop_put_str(d, aop_k_name, zend_string_copy(jp->name));
	aop_put_bool(d, aop_k_static, jp->is_static);
	aop_put_bool(d, aop_k_internal, jp->internal);
	if (mode != AOP_DESC_MATCH && caller && caller->func && ZEND_USER_CODE(caller->func->type)) {
		zval c;
		aop_caller_array(&c, caller, mode == AOP_DESC_FULL);
		aop_put(d, aop_k_caller, &c);
	} else {
		aop_put_null(d, aop_k_caller);
	}
}

static bool aop_jp_call_matcher(zval *descriptor, zend_long *result, const aop_jp *jp)
{
	zend_string *target;

	if (!AOP_G(registered)) {
		return false;
	}
	if (aop_invoke_matcher(descriptor, result)) {
		return true;
	}
	if (strncmp(jp->kind, "field-", sizeof("field-") - 1) == 0) {
		target = zend_strpprintf(0, "%s %s::$%s", jp->kind,
			jp->class_ ? ZSTR_VAL(jp->class_->name) : "", ZSTR_VAL(jp->name));
	} else if (strcmp(jp->kind, "exception-handler") == 0) {
		target = zend_strpprintf(0, "%s catch(%s)", jp->kind, ZSTR_VAL(jp->name));
	} else {
		target = zend_strpprintf(0, "%s %s", jp->kind,
			jp->class_ ? ZSTR_VAL(jp->class_->name) : ZSTR_VAL(jp->name));
	}
	aop_report_matcher_exception_ex(ZSTR_VAL(target), "the join point is not intercepted");
	zend_string_release(target);
	return false;
}

/*
 * The decision for jp, accessed/entered from caller (the user frame whose code contains the join
 * point, or NULL). With jp->no_caller the CALLER_SENSITIVE bit is ignored (class probes).
 */
zend_long aop_decide_jp(const aop_jp *jp, zend_execute_data *caller)
{
	char buf[AOP_JP_KEY_BUF];
	char *key;
	size_t key_len;
	zval *cached, v, d;
	zend_long flags = 0;
	bool ok = true;

	if (!AOP_G(registered)) {
		return 0;
	}

	key_len = aop_jp_key_build(jp, NULL, buf, &key);
	cached = zend_hash_str_find(&AOP_G(jp_decisions), key, key_len);
	if (EXPECTED(cached)) {
		flags = Z_LVAL_P(cached);
	} else {
		aop_jp_descriptor(&d, jp, NULL, AOP_DESC_MATCH);
		ok = aop_jp_call_matcher(&d, &flags, jp);
		zval_ptr_dtor(&d);
		if (ok) {
			ZVAL_LONG(&v, flags);
			zend_hash_str_update(&AOP_G(jp_decisions), key, key_len, &v);
		}
	}
	if (key != buf) {
		efree(key);
	}
	if (!ok) {
		return 0;
	}

	if (EXPECTED(!(flags & AOP_CALLER_SENSITIVE))) {
		return flags;
	}
	if (jp->no_caller || !caller || !caller->func || !ZEND_USER_CODE(caller->func->type)) {
		return flags & ~AOP_CALLER_SENSITIVE;
	}

	key_len = aop_jp_key_build(jp, caller, buf, &key);
	cached = zend_hash_str_find(&AOP_G(jp_caller_decisions), key, key_len);
	if (cached) {
		flags = Z_LVAL_P(cached);
	} else {
		aop_jp_descriptor(&d, jp, caller, AOP_DESC_MATCH_CALLER);
		ok = aop_jp_call_matcher(&d, &flags, jp);
		zval_ptr_dtor(&d);
		if (ok) {
			flags &= ~AOP_CALLER_SENSITIVE;
			ZVAL_LONG(&v, flags);
			zend_hash_str_update(&AOP_G(jp_caller_decisions), key, key_len, &v);
		} else {
			flags = 0;
		}
	}
	if (key != buf) {
		efree(key);
	}
	return flags;
}

/*
 * Calls the dispatcher for jp. caller is the user frame containing the join point (reported as the
 * descriptor's caller), extra_key/extra an optional additional descriptor entry ("value",
 * "exception"). Takes over args and original. retval receives the result (UNDEF when it threw).
 */
void aop_dispatch_jp(const aop_jp *jp, zend_execute_data *caller, zend_object *this_obj,
		const char *extra_key, zval *extra, zval *args, zval *original, zval *retval)
{
	zval params[4];

	AOP_CHECK(AOP_G(in_matcher) == 0);

	aop_jp_descriptor(&params[0], jp, caller, AOP_DESC_FULL);
	if (extra_key) {
		Z_TRY_ADDREF_P(extra);
		add_assoc_zval(&params[0], extra_key, extra);
	}
	if (this_obj) {
		ZVAL_OBJ_COPY(&params[1], this_obj);
	} else {
		ZVAL_NULL(&params[1]);
	}
	ZVAL_COPY_VALUE(&params[2], args);
	ZVAL_COPY_VALUE(&params[3], original);

	aop_call_dispatcher(params, retval);
}

/* True when the code running now (the current frame, or for internal frames the nearest user frame below) is in AOP\. */
bool aop_current_code_in_aop_ns(void)
{
	zend_execute_data *ex = EG(current_execute_data);

	while (ex && !ex->func) {
		ex = ex->prev_execute_data;
	}
	if (!ex) {
		return false;
	}
	if (aop_function_in_aop_ns(ex->func)) {
		return true;
	}
	return !ZEND_USER_CODE(ex->func->type) && aop_caller_in_aop_ns(ex);
}
/* }}} */

/* {{{ lifecycle */
void aop_engine_minit(void)
{
	aop_k_kind = zend_string_init_interned(ZEND_STRL("kind"), 1);
	aop_k_class = zend_string_init_interned(ZEND_STRL("class"), 1);
	aop_k_scope = zend_string_init_interned(ZEND_STRL("scope"), 1);
	aop_k_name = zend_string_init_interned(ZEND_STRL("name"), 1);
	aop_k_static = zend_string_init_interned(ZEND_STRL("static"), 1);
	aop_k_internal = zend_string_init_interned(ZEND_STRL("internal"), 1);
	aop_k_caller = zend_string_init_interned(ZEND_STRL("caller"), 1);
	aop_k_outermost = zend_string_init_interned(ZEND_STRL("outermost"), 1);
	aop_k_function = zend_string_init_interned(ZEND_STRL("function"), 1);
	aop_k_file = zend_string_init_interned(ZEND_STRL("file"), 1);
	aop_k_line = zend_string_init_interned(ZEND_STRL("line"), 1);
	aop_k_object = zend_string_init_interned(ZEND_STRL("object"), 1);
	aop_v_ctor = zend_string_init_interned(ZEND_STRL("constructor-execution"), 1);
	aop_v_method = zend_string_init_interned(ZEND_STRL("method-execution"), 1);
	aop_v_function = zend_string_init_interned(ZEND_STRL("function-execution"), 1);
	aop_engine_ce->ce_flags |= ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;

	aop_original_call_ce->ce_flags |= ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
	aop_original_call_ce->create_object = aop_original_call_create;

	memcpy(&aop_original_call_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
	aop_original_call_handlers.offset = XtOffsetOf(aop_original_call, std);
	aop_original_call_handlers.free_obj = aop_original_call_free;
	aop_original_call_handlers.get_gc = aop_original_call_get_gc;
	aop_original_call_handlers.get_constructor = aop_original_call_get_constructor;
	aop_original_call_handlers.clone_obj = NULL;
}

void aop_engine_rinit(void)
{
	AOP_G(registered) = false;
	AOP_G(in_matcher) = 0;
	AOP_G(bypass) = NULL;
	zend_hash_init(&AOP_G(decisions), 64, NULL, NULL, 0);
	memset(AOP_G(fast_decisions), 0, sizeof(AOP_G(fast_decisions)));
	zend_hash_init(&AOP_G(caller_decisions), 16, NULL, NULL, 0);
	aop_woven_rinit();
	/* stream G */
	zend_hash_init(&AOP_G(jp_decisions), 16, NULL, NULL, 0);
	zend_hash_init(&AOP_G(jp_caller_decisions), 8, NULL, NULL, 0);
	ZVAL_UNDEF(&AOP_G(introductions));
}

void aop_engine_reset(void)
{
	zend_hash_clean(&AOP_G(decisions));
	memset(AOP_G(fast_decisions), 0, sizeof(AOP_G(fast_decisions)));
	zend_hash_clean(&AOP_G(caller_decisions));
	/* stream G */
	zend_hash_clean(&AOP_G(jp_decisions));
	zend_hash_clean(&AOP_G(jp_caller_decisions));
}

void aop_engine_unregister(void)
{
	if (AOP_G(registered)) {
		AOP_G(registered) = false;
		zend_fcc_dtor(&AOP_G(matcher));
		zend_fcc_dtor(&AOP_G(dispatcher));
	}
	aop_engine_reset();
}

void aop_engine_rshutdown(void)
{
	aop_woven_rshutdown();
	aop_engine_unregister();
	zend_hash_destroy(&AOP_G(decisions));
	zend_hash_destroy(&AOP_G(caller_decisions));
	/* stream G */
	zend_hash_destroy(&AOP_G(jp_decisions));
	zend_hash_destroy(&AOP_G(jp_caller_decisions));
	zval_ptr_dtor(&AOP_G(introductions));
	ZVAL_UNDEF(&AOP_G(introductions));
}
/* }}} */
