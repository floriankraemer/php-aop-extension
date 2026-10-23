/* Skeleton module; stream A replaces this with the engine. */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"
#include "php_aop.h"

PHP_MINFO_FUNCTION(aop)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "aop support", "enabled");
	php_info_print_table_row(2, "Version", PHP_AOP_VERSION);
	php_info_print_table_end();
}

zend_module_entry aop_module_entry = {
	STANDARD_MODULE_HEADER,
	"aop",
	NULL,
	NULL,
	NULL,
	NULL,
	NULL,
	PHP_MINFO(aop),
	PHP_AOP_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_AOP
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(aop)
#endif
