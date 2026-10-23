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
extern bool aop_hooks_installed;

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

/* aop_hook.c: the zend_execute_ex / zend_execute_internal hooks (engine-version specific code) */
void aop_hook_install(void);
void aop_hook_uninstall(void);

#endif /* PHP_AOP_H */
