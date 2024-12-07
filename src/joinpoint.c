#include "php.h"
#include "joinpoint.h"

// Declare the class entry variable defined in the header
zend_class_entry *joinpoint_ce;

// Arginfo for JoinPoint::__construct
ZEND_BEGIN_ARG_INFO_EX(arginfo_joinpoint_construct, 0, 0, 0)
ZEND_END_ARG_INFO()

// Arginfo for JoinPoint::getMethodName
ZEND_BEGIN_ARG_INFO_EX(arginfo_joinpoint_get_method_name, 0, 0, 0)
ZEND_END_ARG_INFO()

// Method: JoinPoint::__construct
PHP_METHOD(JoinPoint, __construct)
{
    // No initialization needed yet
}

// Method: JoinPoint::getMethodName
PHP_METHOD(JoinPoint, getMethodName)
{
    if (EG(current_execute_data) && EG(current_execute_data)->prev_execute_data && EG(current_execute_data)->prev_execute_data->func) {
        zend_function *prev_func = EG(current_execute_data)->prev_execute_data->func;
        if (prev_func->common.function_name) {
            RETURN_STR_COPY(prev_func->common.function_name);
        }
    }

    RETURN_STRING("unknown");
}

// Register JoinPoint methods
static const zend_function_entry joinpoint_methods[] = {
    PHP_ME(JoinPoint, __construct, arginfo_joinpoint_construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(JoinPoint, getMethodName, arginfo_joinpoint_get_method_name, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

// Define the init function that registers the JoinPoint class
void joinpoint_init()
{
    zend_class_entry ce;
    INIT_CLASS_ENTRY(ce, "JoinPoint", joinpoint_methods);
    joinpoint_ce = zend_register_internal_class(&ce);
}
