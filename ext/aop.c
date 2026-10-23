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
#include "zend_exceptions.h"
#include "ext/spl/spl_exceptions.h"
#include "php_aop.h"
#include "aop_arginfo.h"
#include "aop_g.h" /* stream G */

ZEND_DECLARE_MODULE_GLOBALS(aop)

zend_class_entry *aop_engine_ce;
zend_class_entry *aop_original_call_ce;
/* Process wide: set in MINIT, never changes afterwards (aop.enable is PHP_INI_SYSTEM). */
bool aop_hooks_installed = false;

PHP_INI_BEGIN()
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
		zend_throw_exception(spl_ce_RuntimeException,
			"AOP\\Engine::register(): interception is disabled (aop.enable=0)", 0);
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
	/* stream G: the ZEND_NEW handler is installed together with the other hooks. */
	RETURN_BOOL(aop_hooks_installed);
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

PHP_MINIT_FUNCTION(aop)
{
	REGISTER_INI_ENTRIES();

	aop_engine_ce = register_class_AOP_Engine();
	aop_original_call_ce = register_class_AOP_OriginalCall();
	aop_engine_minit();

	if (AOP_G(enable)) {
		aop_hook_install();
		aop_hooks_installed = true;
		if (AOP_G(disable_inlining)) {
			aop_compile_hooks_install();
		}
		aop_jit_notice_install();
		/* stream G */
		aop_fields_minit();
		aop_catch_minit();
	}
	aop_ast_minit(aop_hooks_installed); /* stream G */

	return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(aop)
{
	if (aop_hooks_installed) {
		/* stream G */
		aop_catch_mshutdown();
		aop_fields_mshutdown();
		aop_hook_uninstall();
		aop_hooks_installed = false;
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

PHP_MINFO_FUNCTION(aop)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "aop support", "enabled");
	php_info_print_table_row(2, "Version", PHP_AOP_VERSION);
	php_info_print_table_row(2, "Interception hooks", aop_hooks_installed ? "active" : "inactive (aop.enable=0)");
	php_info_print_table_row(2, "opcache JIT", !aop_hooks_installed
		? "not affected by aop (aop.enable=0)"
		: (aop_jit_requested()
			? "disabled: aop overrides zend_execute_ex() (set aop.enable=0 to use the JIT)"
			: "not configured; it cannot run while aop overrides zend_execute_ex()"));
	php_info_print_table_row(2, "opcache function inlining", !aop_hooks_installed
		? "not affected by aop (aop.enable=0)"
		: (AOP_G(disable_inlining)
			? "prevented for user functions (aop.disable_inlining=1)"
			: "allowed (aop.disable_inlining=0): calls to functions that return a constant may not be intercepted"));
	php_info_print_table_row(2, "Field interception", aop_hooks_installed ? "available" : "not available");
	php_info_print_table_end();

	DISPLAY_INI_ENTRIES();
}

zend_module_entry aop_module_entry = {
	STANDARD_MODULE_HEADER,
	"aop",
	NULL,
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
