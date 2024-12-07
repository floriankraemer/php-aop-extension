#include "php.h"

// Global to hold the original execute_ex
static void (*original_execute_ex)(zend_execute_data *execute_data);

// Define Class Entries
zend_class_entry *pointcut_ce;
zend_class_entry *advice_ce;
zend_class_entry *aspect_ce;
zend_class_entry *joinpoint_ce;

// Arginfo for JoinPoint::__construct
ZEND_BEGIN_ARG_INFO_EX(arginfo_joinpoint_construct, 0, 0, 0)
ZEND_END_ARG_INFO()

// Arginfo for JoinPoint::getMethodName
ZEND_BEGIN_ARG_INFO_EX(arginfo_joinpoint_get_method_name, 0, 0, 0)
ZEND_END_ARG_INFO()

// Arginfo for JoinPoint::getClassName
ZEND_BEGIN_ARG_INFO_EX(arginfo_joinpoint_get_class_name, 0, 0, 0)
ZEND_END_ARG_INFO()

// Arginfo for JoinPoint::getObject
ZEND_BEGIN_ARG_INFO_EX(arginfo_joinpoint_get_object, 0, 0, 0)
ZEND_END_ARG_INFO()

// Arginfo for Pointcut::__construct
ZEND_BEGIN_ARG_INFO_EX(arginfo_pointcut_construct, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, expression, IS_STRING, 0)
ZEND_END_ARG_INFO()

// Arginfo for Pointcut::setExpression
ZEND_BEGIN_ARG_INFO_EX(arginfo_pointcut_set_expression, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, expression, IS_STRING, 0)
ZEND_END_ARG_INFO()

// Arginfo for Pointcut::matches
ZEND_BEGIN_ARG_INFO_EX(arginfo_pointcut_matches, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, method_name, IS_STRING, 0)
ZEND_END_ARG_INFO()

// Arginfo for Aspect::__construct
ZEND_BEGIN_ARG_INFO_EX(arginfo_aspect_construct, 0, 0, 0)
ZEND_END_ARG_INFO()

// Arginfo for Aspect::addAdvice
ZEND_BEGIN_ARG_INFO_EX(arginfo_aspect_add_advice, 0, 0, 2)
    ZEND_ARG_OBJ_INFO(0, pointcut, Pointcut, 0)
    ZEND_ARG_OBJ_INFO(0, advice, Advice, 0)
ZEND_END_ARG_INFO()

// Arginfo for Advice::__construct
ZEND_BEGIN_ARG_INFO_EX(arginfo_advice_construct, 0, 0, 1)
    ZEND_ARG_CALLABLE_INFO(0, callback, 0)
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

// Method: JoinPoint::getClassName
PHP_METHOD(JoinPoint, getClassName)
{
    zend_execute_data *call = EG(current_execute_data)->prev_execute_data;
    if (call && call->func && call->func->common.scope) {
        RETURN_STR_COPY(call->func->common.scope->name);
    }

    RETURN_STRING("global");
}

// Method: JoinPoint::getObject
PHP_METHOD(JoinPoint, getObject)
{
    zend_execute_data *call = EG(current_execute_data)->prev_execute_data;
    if (call && Z_TYPE(call->This) == IS_OBJECT) {
        // Return the object
        RETURN_ZVAL(&call->This, 1, 0);
    }

    RETURN_NULL();
}

// Method: Pointcut::__construct
PHP_METHOD(Pointcut, __construct)
{
    zend_string *expression;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(expression)
    ZEND_PARSE_PARAMETERS_END();

    zend_update_property_str(pointcut_ce, Z_OBJ_P(getThis()), "expression", sizeof("expression") - 1, expression);
}

// Method: Pointcut::setExpression
PHP_METHOD(Pointcut, setExpression)
{
    zend_string *expression;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(expression)
    ZEND_PARSE_PARAMETERS_END();

    zend_update_property_str(pointcut_ce, Z_OBJ_P(getThis()), "expression", sizeof("expression") - 1, expression);
}

// Method: Pointcut::matches
PHP_METHOD(Pointcut, matches)
{
    php_printf("[AOP] Entering Pointcut Matches\n");
    zend_string *method_name;
    zval *expression_property;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(method_name)
    ZEND_PARSE_PARAMETERS_END();

    expression_property = zend_read_property(pointcut_ce, Z_OBJ_P(getThis()), "expression", sizeof("expression") - 1, 0, NULL);

    if (Z_TYPE_P(expression_property) == IS_STRING) {
        const char *expression = ZSTR_VAL(Z_STR_P(expression_property));
        const char *method = ZSTR_VAL(method_name);

        if (strstr(expression, "*->") && strstr(expression, method)) {
            php_printf("[AOP] Matched: %s\n", ZSTR_VAL(method_name));
            RETURN_TRUE;
        }
    }

    php_printf("[AOP] NOT Matched: %s\n", ZSTR_VAL(method_name));
    RETURN_FALSE;
}

// Method: Aspect::__construct
PHP_METHOD(Aspect, __construct)
{
    zend_update_property_null(aspect_ce, Z_OBJ_P(getThis()), "adviceMap", sizeof("adviceMap") - 1);
}

// Method: Aspect::addAdvice
PHP_METHOD(Aspect, addAdvice)
{
    zval *pointcut, *advice, *adviceMap;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_OBJECT_OF_CLASS(pointcut, pointcut_ce)
        Z_PARAM_OBJECT_OF_CLASS(advice, advice_ce)
    ZEND_PARSE_PARAMETERS_END();

    adviceMap = zend_read_property(aspect_ce, Z_OBJ_P(getThis()), "adviceMap", sizeof("adviceMap") - 1, 0, NULL);

    if (Z_TYPE_P(adviceMap) != IS_ARRAY) {
        zval newMap;
        array_init(&newMap);
        zend_update_property(aspect_ce, Z_OBJ_P(getThis()), "adviceMap", sizeof("adviceMap") - 1, &newMap);
        zval_ptr_dtor(&newMap);
        adviceMap = zend_read_property(aspect_ce, Z_OBJ_P(getThis()), "adviceMap", sizeof("adviceMap") - 1, 0, NULL);
    }

    add_next_index_zval(adviceMap, advice);
    Z_TRY_ADDREF_P(advice);
}

// Method: Advice::__construct
PHP_METHOD(Advice, __construct)
{
    zval *callback;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(callback)
    ZEND_PARSE_PARAMETERS_END();

    if (!zend_is_callable(callback, 0, NULL)) {
        zend_throw_error(NULL, "Provided argument is not callable", 0);
        RETURN_THROWS();
    }

    zend_update_property(advice_ce, Z_OBJ_P(getThis()), "callback", sizeof("callback") - 1, callback);
    RETURN_TRUE;
}

// Register JoinPoint methods
const zend_function_entry joinpoint_methods[] = {
    PHP_ME(JoinPoint, __construct, arginfo_joinpoint_construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(JoinPoint, getMethodName, arginfo_joinpoint_get_method_name, ZEND_ACC_PUBLIC)
    PHP_ME(JoinPoint, getClassName, arginfo_joinpoint_get_class_name, ZEND_ACC_PUBLIC)
    PHP_ME(JoinPoint, getObject, arginfo_joinpoint_get_object, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

// Register Pointcut, Advice, Aspect methods as before
const zend_function_entry pointcut_methods[] = {
    PHP_ME(Pointcut, __construct, arginfo_pointcut_construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(Pointcut, setExpression, arginfo_pointcut_set_expression, ZEND_ACC_PUBLIC)
    PHP_ME(Pointcut, matches, arginfo_pointcut_matches, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

const zend_function_entry advice_methods[] = {
    PHP_ME(Advice, __construct, arginfo_advice_construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_FE_END
};

const zend_function_entry aspect_methods[] = {
    PHP_ME(Aspect, __construct, arginfo_aspect_construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(Aspect, addAdvice, arginfo_aspect_add_advice, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

static zval create_joinpoint(zend_execute_data *execute_data)
{
    zval joinpoint_obj;
    zval args_array;
    zend_function *func = execute_data->func;

    object_init_ex(&joinpoint_obj, joinpoint_ce);

    // Retrieve method name
    const char *method_name = "(unknown)";
    if (func && func->common.function_name) {
        method_name = ZSTR_VAL(func->common.function_name);
    }

    // Retrieve class name if available
    const char *class_name = "(none)";
    if (func && func->common.scope) {
        class_name = ZSTR_VAL(func->common.scope->name);
    }

    // Collect arguments into an array
    uint32_t num_args = ZEND_CALL_NUM_ARGS(execute_data);
    array_init(&args_array);
    for (uint32_t i = 0; i < num_args; i++) {
        zval *arg = ZEND_CALL_ARG(execute_data, i + 1);
        Z_TRY_ADDREF_P(arg);
        add_next_index_zval(&args_array, arg);
    }

    // Set properties on the JoinPoint object
	zend_update_property_string(joinpoint_ce, Z_OBJ(joinpoint_obj), ZEND_STRL("methodName"), method_name);
	zend_update_property_string(joinpoint_ce, Z_OBJ(joinpoint_obj), ZEND_STRL("className"), class_name);
    zend_update_property(joinpoint_ce, Z_OBJ(joinpoint_obj), ZEND_STRL("arguments"), &args_array);

    zval_ptr_dtor(&args_array); // Ownership transferred to the joinpoint object

    return joinpoint_obj;
}

// Function to intercept method calls
void aop_execute_ex(zend_execute_data *execute_data)
{
    zend_function *func = execute_data->func;
    if (func && func->common.function_name) {
        const char *method_name = ZSTR_VAL(func->common.function_name);
        zval joinpoint_obj = create_joinpoint(execute_data);

        // Retrieve class name if available
        const char *class_name = "(none)";
        if (func->common.scope) {
            class_name = ZSTR_VAL(func->common.scope->name);
        }

        php_printf("[AOP] Intercepting method: %s::%s\n", class_name, method_name);
        php_printf("[AOP] Input arguments:\n");

        // Get the Arguments
        uint32_t num_args = ZEND_CALL_NUM_ARGS(execute_data);
        for (uint32_t i = 0; i < num_args; i++) {
            zval *arg = ZEND_CALL_ARG(execute_data, i + 1);
            php_printf("  Arg %u: ", i + 1);
            if (arg) {
                zend_print_zval(arg, 0);
            } else {
                php_printf("(null)");
            }
            php_printf("\n");
        }

        php_printf("[AOP] Calling BEFORE aspects for method: %s::%s\n", class_name, method_name);
        // Todo: Implement aspect calling

        if (original_execute_ex) {
            original_execute_ex(execute_data);
        }

        // Execute the method and get the return value
        php_printf("[AOP] Output: ");
        if (execute_data->return_value) {
            zend_print_zval(execute_data->return_value, 0);
            php_printf("\n");
        } else {
            php_printf("(null)\n");
        }

        php_printf("[AOP] Calling AFTER aspects for method: %s::%s\n", class_name, method_name);
        // Todo: Implement aspect calling

        php_printf("[AOP] Method call completed: %s::%s\n", class_name, method_name);
    } else {
        if (original_execute_ex) {
            original_execute_ex(execute_data);
        }
    }
}

// Module init
PHP_MINIT_FUNCTION(aop)
{
    zend_class_entry ce;

    INIT_CLASS_ENTRY(ce, "Pointcut", pointcut_methods);
    pointcut_ce = zend_register_internal_class(&ce);
    zend_declare_property_null(pointcut_ce, "expression", sizeof("expression") - 1, ZEND_ACC_PRIVATE);

    INIT_CLASS_ENTRY(ce, "Advice", advice_methods);
    advice_ce = zend_register_internal_class(&ce);
    zend_declare_property_null(advice_ce, "callback", sizeof("callback") - 1, ZEND_ACC_PRIVATE);

    INIT_CLASS_ENTRY(ce, "Aspect", aspect_methods);
    aspect_ce = zend_register_internal_class(&ce);
    zend_declare_property_null(aspect_ce, "adviceMap", sizeof("adviceMap") - 1, ZEND_ACC_PRIVATE);

    INIT_CLASS_ENTRY(ce, "JoinPoint", joinpoint_methods);
    joinpoint_ce = zend_register_internal_class(&ce);
 	zend_declare_property_null(joinpoint_ce, "methodName", sizeof("methodName") - 1, ZEND_ACC_PRIVATE);
	zend_declare_property_null(joinpoint_ce, "className", sizeof("className") - 1, ZEND_ACC_PRIVATE);
	zend_declare_property_null(joinpoint_ce, "arguments", sizeof("arguments") - 1, ZEND_ACC_PRIVATE);

    original_execute_ex = zend_execute_ex;
    zend_execute_ex = aop_execute_ex;

    return SUCCESS;
}

// Module shutdown
PHP_MSHUTDOWN_FUNCTION(aop)
{
    zend_execute_ex = original_execute_ex;
    return SUCCESS;
}

// Module entry definition
zend_module_entry aop_module_entry = {
    STANDARD_MODULE_HEADER,
    "aop",
    NULL,
    PHP_MINIT(aop),
    PHP_MSHUTDOWN(aop),
    NULL,
    NULL,
    NULL,
    NO_VERSION_YET,
    STANDARD_MODULE_PROPERTIES
};

ZEND_GET_MODULE(aop)
