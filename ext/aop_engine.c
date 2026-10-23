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
#include "php_aop.h"

/* {{{ namespace and frame helpers */
bool aop_name_in_aop_ns(const zend_string *name)
{
	return name != NULL
		&& ZSTR_LEN(name) > sizeof("AOP\\") - 1
		&& strncasecmp(ZSTR_VAL(name), "AOP\\", sizeof("AOP\\") - 1) == 0;
}

bool aop_class_in_aop_ns(const zend_class_entry *ce)
{
	return ce != NULL && aop_name_in_aop_ns(ce->name);
}

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

static void aop_add_class_name(zval *arr, const char *key, size_t key_len, const zend_class_entry *ce)
{
	if (ce) {
		add_assoc_str_ex(arr, key, key_len, zend_string_copy(ce->name));
	} else {
		add_assoc_null_ex(arr, key, key_len);
	}
}

static void aop_caller_array(zval *out, zend_execute_data *caller, bool full)
{
	zend_function *fn = caller->func;

	array_init_size(out, full ? 6 : 4);
	aop_add_class_name(out, ZEND_STRL("class"), aop_frame_class(caller));
	aop_add_class_name(out, ZEND_STRL("scope"), fn->common.scope);
	if (fn->common.function_name) {
		add_assoc_str_ex(out, ZEND_STRL("function"), zend_string_copy(fn->common.function_name));
	} else {
		add_assoc_null_ex(out, ZEND_STRL("function"));
	}
	if (ZEND_USER_CODE(fn->type) && fn->op_array.filename) {
		add_assoc_str_ex(out, ZEND_STRL("file"), zend_string_copy(fn->op_array.filename));
	} else {
		add_assoc_null_ex(out, ZEND_STRL("file"));
	}
	if (full) {
		if (ZEND_USER_CODE(fn->type) && caller->opline) {
			add_assoc_long_ex(out, ZEND_STRL("line"), caller->opline->lineno);
		} else {
			add_assoc_null_ex(out, ZEND_STRL("line"));
		}
		if (Z_TYPE(caller->This) == IS_OBJECT) {
			zval obj;
			ZVAL_OBJ_COPY(&obj, Z_OBJ(caller->This));
			add_assoc_zval_ex(out, ZEND_STRL("object"), &obj);
		} else {
			add_assoc_null_ex(out, ZEND_STRL("object"));
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
	const char *kind = is_ctor ? "constructor-execution"
		: (func->common.scope ? "method-execution" : "function-execution");

	array_init_size(d, 8);
	add_assoc_string_ex(d, ZEND_STRL("kind"), kind);
	aop_add_class_name(d, ZEND_STRL("class"), func->common.scope ? called : NULL);
	aop_add_class_name(d, ZEND_STRL("scope"), func->common.scope);
	add_assoc_str_ex(d, ZEND_STRL("name"), zend_string_copy(func->common.function_name));
	add_assoc_bool_ex(d, ZEND_STRL("static"), (func->common.fn_flags & ZEND_ACC_STATIC) != 0);
	add_assoc_bool_ex(d, ZEND_STRL("internal"), func->type == ZEND_INTERNAL_FUNCTION);

	if (mode != AOP_DESC_MATCH && caller && ZEND_USER_CODE(caller->func->type)) {
		zval c;
		aop_caller_array(&c, caller, mode == AOP_DESC_FULL);
		add_assoc_zval_ex(d, ZEND_STRL("caller"), &c);
	} else {
		add_assoc_null_ex(d, ZEND_STRL("caller"));
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
		add_assoc_bool_ex(d, ZEND_STRL("outermost"), outermost);
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

static void aop_report_matcher_exception(zend_function *func, zend_class_entry *called)
{
	zend_object *ex = EG(exception);
	zval tmp, *msg;
	zend_string *message;

	GC_ADDREF(ex);
	zend_clear_exception();
	msg = zend_read_property_ex(zend_get_exception_base(ex), ex, ZSTR_KNOWN(ZEND_STR_MESSAGE), 1, &tmp);
	message = zval_get_string(msg);
	zend_error(E_WARNING, "AOP\\Engine: the matcher threw %s (%s) for %s%s%s(); the call is not intercepted",
		ZSTR_VAL(ex->ce->name), ZSTR_VAL(message),
		func->common.scope && called ? ZSTR_VAL(called->name) : "",
		func->common.scope ? "::" : "",
		ZSTR_VAL(func->common.function_name));
	zend_string_release(message);
	OBJ_RELEASE(ex);
}

/* Calls the matcher with interception suspended. Returns false (decision 0, not cached) when it threw. */
static bool aop_call_matcher(zval *descriptor, zend_long *result, zend_function *func, zend_class_entry *called)
{
	zend_fcall_info_cache fcc;
	zval retval;

	if (!AOP_G(registered)) {
		return false;
	}

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
		aop_report_matcher_exception(func, called);
		return false;
	}

	*result = zval_get_long(&retval);
	zval_ptr_dtor(&retval);
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
	zval *cached, v;
	zend_long flags;

	key.ce = called;
	key.func = func;
	cached = zend_hash_str_find(&AOP_G(decisions), (const char *) &key, sizeof(key));
	if (EXPECTED(cached)) {
		flags = Z_LVAL_P(cached);
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

/* {{{ AOP\OriginalCall */
typedef struct {
	zend_function *func;
	zend_class_entry *called_scope;
	zend_object *this_obj;
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

ZEND_METHOD(AOP_OriginalCall, __construct)
{
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

	if (Z_ISUNDEF_P(return_value)) {
		ZVAL_NULL(return_value);
	} else {
		aop_unwrap_reference(return_value);
	}
}
/* }}} */

/* {{{ dispatch */
/*
 * Calls the dispatcher for an advised execution of func. ex is the advised frame (still current),
 * this_obj its $this (NULL for static methods and functions). Takes over args. retval receives the
 * dispatcher's result (UNDEF when it threw).
 */
void aop_dispatch(zend_execute_data *ex, zend_function *func, zend_class_entry *called,
		zend_object *this_obj, zval *args, zval *retval)
{
	zval params[4];
	zend_fcall_info_cache fcc;
	zend_function *handler;

	AOP_CHECK(EG(current_execute_data) == ex);
	AOP_CHECK(AOP_G(in_matcher) == 0);

	aop_descriptor(&params[0], func, called, aop_caller_frame(ex), AOP_DESC_FULL, this_obj);
	if (this_obj) {
		ZVAL_OBJ_COPY(&params[1], this_obj);
	} else {
		ZVAL_NULL(&params[1]);
	}
	ZVAL_COPY_VALUE(&params[2], args);
	aop_original_call_new(&params[3], func, func->common.scope ? called : NULL, this_obj);

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
/* }}} */

/* {{{ lifecycle */
void aop_engine_minit(void)
{
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
	zend_hash_init(&AOP_G(caller_decisions), 16, NULL, NULL, 0);
	ZVAL_UNDEF(&AOP_G(introductions));
}

void aop_engine_reset(void)
{
	zend_hash_clean(&AOP_G(decisions));
	zend_hash_clean(&AOP_G(caller_decisions));
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
	aop_engine_unregister();
	zend_hash_destroy(&AOP_G(decisions));
	zend_hash_destroy(&AOP_G(caller_decisions));
	zval_ptr_dtor(&AOP_G(introductions));
	ZVAL_UNDEF(&AOP_G(introductions));
}
/* }}} */
