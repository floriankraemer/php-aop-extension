#include "php.h"

ZEND_BEGIN_ARG_INFO_EX(arginfo_confirm_aop_compiled, 0, 0, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(confirm_aop_compiled)
{
    const char *msg = "AOP extension is compiled and working!";
    RETURN_STRING(msg);
}

const zend_function_entry aop_functions[] = {
    PHP_FE(confirm_aop_compiled, arginfo_confirm_aop_compiled)
    PHP_FE_END
};

zend_module_entry aop_module_entry = {
    STANDARD_MODULE_HEADER,
    "aop",
    aop_functions,
    NULL, NULL, NULL, NULL, NULL,
    NO_VERSION_YET,
    STANDARD_MODULE_PROPERTIES
};

ZEND_GET_MODULE(aop)
