/*
 * aop extension: module definition, INI and the AOP\Engine class.
 *
 * The interception engine lives in aop_hook.c (VM hooks) and aop_engine.c (decisions, descriptors,
 * dispatch, AOP\OriginalCall). The behaviour is specified in docs/internals/contract.md.
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "zend_system_id.h"
#include "SAPI.h"
#include "zend_exceptions.h"
#include "ext/spl/spl_exceptions.h"
#include "php_aop.h"
#include "aop_arginfo.h"
#include "aop_g.h" /* stream G */

ZEND_DECLARE_MODULE_GLOBALS(aop)

zend_class_entry *aop_engine_ce;
zend_class_entry *aop_original_call_ce;
/* Process wide: set by the post-startup callback, never changes afterwards (aop.enable is PHP_INI_SYSTEM). */
bool aop_hooks_installed = false;
aop_interception_setting_t aop_interception_setting = AOP_SETTING_WOVEN;
aop_mode_t aop_interception_mode = AOP_MODE_NONE;

static PHP_INI_MH(OnUpdateInterception)
{
	(void) entry; (void) mh_arg1; (void) mh_arg2; (void) mh_arg3; (void) stage;

	if (zend_string_equals_literal_ci(new_value, "woven")) {
		aop_interception_setting = AOP_SETTING_WOVEN;
	} else if (zend_string_equals_literal_ci(new_value, "hook")) {
		aop_interception_setting = AOP_SETTING_HOOK;
	} else if (zend_string_equals_literal_ci(new_value, "auto")) {
		aop_interception_setting = AOP_SETTING_AUTO;
	} else {
		zend_error(E_WARNING, "aop.interception: invalid value \"%s\", expected woven, hook or auto",
			ZSTR_VAL(new_value));
		return FAILURE;
	}
	return SUCCESS;
}

PHP_INI_BEGIN()
	PHP_INI_ENTRY("aop.interception", "woven", PHP_INI_SYSTEM, OnUpdateInterception)
	STD_PHP_INI_BOOLEAN("aop.enable", "1", PHP_INI_SYSTEM, OnUpdateBool, enable, zend_aop_globals, aop_globals)
	STD_PHP_INI_BOOLEAN("aop.disable_inlining", "1", PHP_INI_SYSTEM, OnUpdateBool, disable_inlining, zend_aop_globals, aop_globals)
	STD_PHP_INI_BOOLEAN("aop.weave_autoload", "0", PHP_INI_ALL, OnUpdateBool, weave_autoload, zend_aop_globals, aop_globals)
PHP_INI_END()

/* {{{ AOP\Engine */
ZEND_METHOD(AOP_Engine, __construct)
{
	(void) return_value;
	ZEND_PARSE_PARAMETERS_NONE();
	zend_throw_error(NULL, "Cannot instantiate %s", ZSTR_VAL(aop_engine_ce->name));
}

ZEND_METHOD(AOP_Engine, register)
{
	zend_fcall_info fci_m, fci_d;
	zend_fcall_info_cache fcc_m, fcc_d;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_FUNC(fci_m, fcc_m)
		Z_PARAM_FUNC(fci_d, fcc_d)
	ZEND_PARSE_PARAMETERS_END();

	if (!aop_hooks_installed) {
		zend_throw_exception(spl_ce_RuntimeException, AOP_G(enable)
			? "AOP\\Engine::register(): interception is not installed (the extension did not finish starting up)"
			: "AOP\\Engine::register(): interception is disabled (aop.enable=0)", 0);
		RETURN_THROWS();
	}

	aop_engine_unregister();
	zend_fcc_dup(&AOP_G(matcher), &fcc_m);
	zend_fcc_dup(&AOP_G(dispatcher), &fcc_d);
	AOP_G(registered) = true;
}

ZEND_METHOD(AOP_Engine, unregister)
{
	(void) return_value;
	ZEND_PARSE_PARAMETERS_NONE();
	aop_engine_unregister();
}

ZEND_METHOD(AOP_Engine, isRegistered)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_BOOL(AOP_G(registered));
}

ZEND_METHOD(AOP_Engine, reset)
{
	(void) return_value;
	ZEND_PARSE_PARAMETERS_NONE();
	aop_engine_reset();
}

ZEND_METHOD(AOP_Engine, setIntroductions)
{
	zval *rules;
	(void) return_value;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(rules)
	ZEND_PARSE_PARAMETERS_END();

	/* stream G: compiled for the zend_ast_process hook, woven into classes compiled afterwards. */
	aop_ast_set_rules(Z_ARRVAL_P(rules));
	if (EG(exception)) {
		RETURN_THROWS();
	}
	zval_ptr_dtor(&AOP_G(introductions));
	ZVAL_COPY(&AOP_G(introductions), rules);
}

ZEND_METHOD(AOP_Engine, fieldInterceptionAvailable)
{
	ZEND_PARSE_PARAMETERS_NONE();
	/* stream G: the ZEND_NEW handler belongs to the hook backend; the JIT cannot honour it (aop_weave.c). */
	RETURN_BOOL(aop_interception_mode == AOP_MODE_HOOK);
}
/* }}} */

static PHP_GINIT_FUNCTION(aop)
{
#if defined(COMPILE_DL_AOP) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	memset(aop_globals, 0, sizeof(*aop_globals));
	ZVAL_UNDEF(&aop_globals->introductions);
}

/*
 * opcache keys cached bytecode (shared memory and opcache.file_cache) by zend_system_id, which hashes the VM
 * hooks and user opcode handlers present when it is finalized. That happens before zend_post_startup_cb, where
 * the backend is installed, so aop contributes what decides the backend: aop.enable, aop.interception, the SAPI
 * and, because `auto` depends on it, the raw opcache settings of the configuration. The resolved mode is a
 * function of exactly these values, so equal ids always mean equal backends (hook bytecode holds user opcode
 * dispatches that crash a process without the handlers). Raw values, not defaults, are hashed: differently
 * spelled but equivalent configurations only cost a recompile.
 */
static void aop_add_system_entropy(void)
{
	static const char *const keys[] = {
		"aop.enable", "aop.interception", "aop.disable_inlining", "opcache.enable", "opcache.enable_cli", "opcache.jit", "opcache.jit_buffer_size"
	};
	char buf[512];
	size_t len = 0, i;

	len += snprintf(buf + len, sizeof(buf) - len, "sapi=%s;", sapi_module.name);
	for (i = 0; i < sizeof(keys) / sizeof(keys[0]) && len < sizeof(buf); i++) {
		char *value = NULL;

		if (cfg_get_string(keys[i], &value) != SUCCESS || !value) {
			value = "";
		}
		len += snprintf(buf + len, sizeof(buf) - len, "%s=%s;", keys[i], value);
	}
	if (len >= sizeof(buf)) {
		len = sizeof(buf) - 1;
	}
	zend_add_system_entropy("aop", "interception", buf, len);
}

PHP_MINIT_FUNCTION(aop)
{
	REGISTER_INI_ENTRIES();

	aop_engine_ce = register_class_AOP_Engine();
	aop_original_call_ce = register_class_AOP_OriginalCall();
	aop_engine_minit();

	if (type != MODULE_TEMPORARY) {
		aop_add_system_entropy();
	}
	if (AOP_G(enable)) {
		/* The op_array handler serves aop.disable_inlining and the woven backend's constructor prologue. */
		aop_compile_hooks_install();
		if (type == MODULE_TEMPORARY) {
			/* dl(): startup is over, zend_post_startup_cb never fires. Nothing was compiled by this extension yet. */
			aop_install_backend();
		} else {
			/* The backend is chosen and installed there: aop.interception=auto needs opcache's INI entries. */
			aop_post_startup_install();
		}
	}
	aop_ast_minit(AOP_G(enable)); /* stream G */

	return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(aop)
{
	if (aop_hooks_installed) {
		if (aop_interception_mode == AOP_MODE_HOOK) {
			/* stream G */
			aop_catch_mshutdown();
			aop_fields_mshutdown();
		}
		aop_hook_uninstall();
		aop_hooks_installed = false;
		aop_interception_mode = AOP_MODE_NONE;
	}
	aop_ast_mshutdown(); /* stream G */
	UNREGISTER_INI_ENTRIES();

	return SUCCESS;
}

PHP_RINIT_FUNCTION(aop)
{
	(void) type; (void) module_number;
#if defined(COMPILE_DL_AOP) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	aop_engine_rinit();
	/* stream G */
	aop_fields_rinit();
	aop_ast_rinit();

	return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(aop)
{
	(void) type; (void) module_number;
	aop_ast_rshutdown(); /* stream G */
	aop_engine_rshutdown();

	return SUCCESS;
}

/* stream G: wrapped object handlers must outlive the objects, which are freed after RSHUTDOWN. */
static ZEND_MODULE_POST_ZEND_DEACTIVATE_D(aop)
{
	aop_fields_post_deactivate();

	return SUCCESS;
}

static const char *aop_backend_description(void)
{
	bool is_auto = aop_interception_setting == AOP_SETTING_AUTO;

	switch (aop_interception_mode) {
		case AOP_MODE_HOOK:
			return is_auto ? "hook (auto: opcache JIT not configured)" : "hook";
		case AOP_MODE_WOVEN:
			return is_auto ? "woven (auto: opcache JIT configured)" : "woven";
		default:
			return "none (aop.enable=0)";
	}
}

PHP_MINFO_FUNCTION(aop)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "aop support", "enabled");
	php_info_print_table_row(2, "Version", PHP_AOP_VERSION);
	php_info_print_table_row(2, "Interception hooks", aop_hooks_installed ? "active" : "inactive (aop.enable=0)");
	php_info_print_table_row(2, "Interception backend", aop_backend_description());
	php_info_print_table_row(2, "opcache JIT", !aop_hooks_installed
		? "not affected by aop (aop.enable=0)"
		: (aop_interception_mode == AOP_MODE_WOVEN
			? "compatible (aop.interception=woven)"
			: (aop_jit_requested()
				? "disabled: aop.interception=hook overrides zend_execute_ex() (use aop.interception=woven to keep the JIT)"
				: "not configured; it cannot run while aop.interception=hook overrides zend_execute_ex()")));
	php_info_print_table_row(2, "opcache function inlining", !aop_hooks_installed
		? "not affected by aop (aop.enable=0)"
		: (AOP_G(disable_inlining)
			? "prevented for user functions (aop.disable_inlining=1)"
			: "allowed (aop.disable_inlining=0): calls to functions that return a constant may not be intercepted"));
	php_info_print_table_row(2, "Field interception", aop_interception_mode == AOP_MODE_HOOK
		? "available"
		: (aop_hooks_installed ? "not available (aop.interception=woven)" : "not available"));
	php_info_print_table_end();

	DISPLAY_INI_ENTRIES();
}

zend_module_entry aop_module_entry = {
	STANDARD_MODULE_HEADER,
	"aop",
	ext_functions,
	PHP_MINIT(aop),
	PHP_MSHUTDOWN(aop),
	PHP_RINIT(aop),
	PHP_RSHUTDOWN(aop),
	PHP_MINFO(aop),
	PHP_AOP_VERSION,
	PHP_MODULE_GLOBALS(aop),
	PHP_GINIT(aop),
	NULL,
	ZEND_MODULE_POST_ZEND_DEACTIVATE_N(aop), /* stream G */
	STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_AOP
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(aop)
#endif
