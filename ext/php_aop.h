#ifndef PHP_AOP_H
#define PHP_AOP_H

extern zend_module_entry aop_module_entry;
#define phpext_aop_ptr &aop_module_entry

#define PHP_AOP_VERSION "0.1.0-dev"

#if defined(ZTS) && defined(COMPILE_DL_AOP)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

/* Decision bits returned by the matcher (AOP\Engine::* constants). */
#define AOP_ADVISE           1
#define AOP_TRACK            2
#define AOP_CALLER_SENSITIVE 4
#define AOP_INTERCEPT        (AOP_ADVISE | AOP_TRACK)

ZEND_BEGIN_MODULE_GLOBALS(aop)
	/* INI aop.enable (PHP_INI_SYSTEM). */
	bool enable;
	/* INI aop.disable_inlining (PHP_INI_SYSTEM): keep opcache from inlining user functions. */
	bool disable_inlining;
	/* INI aop.weave_autoload (PHP_INI_ALL): intercept calls made while a class is being autoloaded. */
	bool weave_autoload;
	/* Woven backend: the advice result AOP\Internal\intercept() hands to AOP\Internal\result(); UNDEF when none. */
	zval woven_result;
	/* Woven backend: generators OriginalCall created and that have not started yet (weak keys), see aop_engine.c. */
	HashTable *bypassed_generators;
	/* A matcher/dispatcher pair is registered. */
	bool registered;
	/* > 0 while the matcher runs: every call passes through (re-entrancy guard). */
	uint32_t in_matcher;
	/* The function OriginalCall::__invoke() is about to run: its next frame passes through once. */
	zend_function *bypass;
	zend_fcall_info_cache matcher;
	zend_fcall_info_cache dispatcher;
	/* (runtime class, function) -> flags */
	HashTable decisions;
	/* (runtime class, function, caller identity) -> flags, for CALLER_SENSITIVE answers */
	HashTable caller_decisions;
	/* Engine::setIntroductions() rules (stream G). */
	zval introductions;
	/* {{{ stream G (aop_engine.c generic join points, aop_fields.c, aop_ast.c) */
	/* (tag, class, scope, ids, name) -> flags; and the same plus caller identity */
	HashTable jp_decisions;
	HashTable jp_caller_decisions;
	/* class entry -> per-class wrapped object handlers (freed after the objects, in post_deactivate) */
	HashTable *field_handlers;
	/* compiled introduction rules (aop_ast.c) */
	void *intro_rules;
	uint32_t intro_rule_count;
	/* object -> array(impl class (lower case) => defaultImpl instance), weak keys */
	HashTable *intro_delegates;
	/* }}} */
ZEND_END_MODULE_GLOBALS(aop)

ZEND_EXTERN_MODULE_GLOBALS(aop)
#define AOP_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(aop, v)

/* Optional internal consistency checks, independent of ZEND_DEBUG: build with CFLAGS=-DAOP_DEBUG. */
#ifdef AOP_DEBUG
# define AOP_CHECK(cond) do { \
		if (UNEXPECTED(!(cond))) { \
			zend_error_noreturn(E_CORE_ERROR, "aop: internal check failed: %s (%s:%d)", #cond, __FILE__, __LINE__); \
		} \
	} while (0)
#else
# define AOP_CHECK(cond) ((void) 0)
#endif

/* aop.c */
extern zend_class_entry *aop_engine_ce;
extern zend_class_entry *aop_original_call_ce;
/* Process wide: interception is installed (either backend). Set in the post-startup callback. */
extern bool aop_hooks_installed;

/* INI aop.interception, and the backend it resolved to once opcache's INI exists (aop_hook.c). */
typedef enum {
	AOP_SETTING_WOVEN,
	AOP_SETTING_HOOK,
	AOP_SETTING_AUTO
} aop_interception_setting_t;

typedef enum {
	AOP_MODE_NONE,  /* aop.enable=0 */
	AOP_MODE_HOOK,  /* zend_execute_ex / user opcode handlers: all features, no JIT */
	AOP_MODE_WOVEN  /* compile-time prologues: JIT compatible, no field join points */
} aop_mode_t;

extern aop_interception_setting_t aop_interception_setting;
extern aop_mode_t aop_interception_mode;

/* aop_engine.c: decisions, descriptors, dispatch, OriginalCall */
void aop_engine_minit(void);
void aop_engine_rinit(void);
void aop_engine_rshutdown(void);
void aop_engine_unregister(void);
void aop_engine_reset(void);
bool aop_name_in_aop_ns(const zend_string *name);
bool aop_class_in_aop_ns(const zend_class_entry *ce);
zend_class_entry *aop_frame_class(const zend_execute_data *ex);
zend_execute_data *aop_caller_frame(zend_execute_data *ex);
bool aop_caller_in_aop_ns(zend_execute_data *ex);
zend_long aop_decide(zend_function *func, zend_class_entry *called, zend_execute_data *caller);
void aop_dispatch(zend_execute_data *ex, zend_function *func, zend_class_entry *called,
		zend_object *this_obj, zval *args, zval *retval);
void aop_bypassed_generators_free(void);

/* {{{ stream G: generic join points (aop_engine.c) */
typedef struct {
	const char *kind;          /* descriptor kind (static string) */
	const void *tag;           /* cache identity; NULL: kind */
	zend_class_entry *class_;  /* descriptor "class" */
	zend_class_entry *scope;   /* descriptor "scope" */
	zend_string *name;         /* descriptor "name" */
	bool is_static;
	bool internal;
	bool no_caller;            /* ignore CALLER_SENSITIVE (class probes) */
	const void *id1;           /* further cache identity */
	const void *id2;
} aop_jp;

typedef void (*aop_original_native_fn)(zend_object *this_obj, zend_string *name, zend_class_entry *scope,
		zend_long tag, HashTable *args, zval *return_value);

zend_long aop_decide_jp(const aop_jp *jp, zend_execute_data *caller);
void aop_dispatch_jp(const aop_jp *jp, zend_execute_data *caller, zend_object *this_obj,
		const char *extra_key, zval *extra, zval *args, zval *original, zval *retval);
void aop_original_call_new_native(zval *zv, aop_original_native_fn fn, zend_object *this_obj,
		zend_string *name, zend_class_entry *scope, zend_long tag);
bool aop_current_code_in_aop_ns(void);
/* }}} */

/* aop_hook.c: the zend_execute_ex / zend_execute_internal hooks (engine-version specific code) */
void aop_hook_uninstall(void);
void aop_compile_hooks_install(void);
void aop_post_startup_install(void);
void aop_install_backend(void);
bool aop_jit_requested(void);
void aop_woven_rinit(void);
void aop_woven_rshutdown(void);

/* The woven backend's runtime half (AOP\Internal\intercept(), AOP\Internal\result()); declared by aop_arginfo.h too. */
ZEND_FUNCTION(AOP_Internal_intercept);
ZEND_FUNCTION(AOP_Internal_result);
ZEND_FUNCTION(AOP_Internal_caught);

#endif /* PHP_AOP_H */
