#include "php.h"

// Define Class Entries
zend_class_entry *pointcut_ce;
zend_class_entry *advice_ce;
zend_class_entry *aspect_ce;

// Arginfo for Aspect::__construct
ZEND_BEGIN_ARG_INFO_EX(arginfo_aspect_construct, 0, 0, 0)
ZEND_END_ARG_INFO()

// Arginfo for Aspect::addAdvice
ZEND_BEGIN_ARG_INFO_EX(arginfo_aspect_add_advice, 0, 0, 2)
    ZEND_ARG_OBJ_INFO(0, pointcut, Pointcut, 0) // Pointcut object
    ZEND_ARG_OBJ_INFO(0, advice, Advice, 0)    // Advice object
ZEND_END_ARG_INFO()

// Arginfo for Pointcut::__construct
ZEND_BEGIN_ARG_INFO_EX(arginfo_pointcut_construct, 0, 0, 0)
ZEND_END_ARG_INFO()

// Arginfo for Pointcut::matches
ZEND_BEGIN_ARG_INFO_EX(arginfo_pointcut_matches, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, method_name, IS_STRING, 0)
ZEND_END_ARG_INFO()

// Arginfo for Advice::__construct
ZEND_BEGIN_ARG_INFO_EX(arginfo_advice_construct, 0, 0, 1)
    ZEND_ARG_CALLABLE_INFO(0, callback, 0) // Expect a callable
ZEND_END_ARG_INFO()

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

    // Fetch adviceMap property
    adviceMap = zend_read_property(aspect_ce, Z_OBJ_P(getThis()), "adviceMap", sizeof("adviceMap") - 1, 0, NULL);

    // Initialize adviceMap if null
    if (Z_TYPE_P(adviceMap) != IS_ARRAY) {
        zval newMap;
        array_init(&newMap);
        zend_update_property(aspect_ce, Z_OBJ_P(getThis()), "adviceMap", sizeof("adviceMap") - 1, &newMap);
        zval_ptr_dtor(&newMap); // Decrement reference to prevent memory leaks
        adviceMap = zend_read_property(aspect_ce, Z_OBJ_P(getThis()), "adviceMap", sizeof("adviceMap") - 1, 0, NULL);
    }

    // Add advice to adviceMap
    add_next_index_zval(adviceMap, advice);
    Z_TRY_ADDREF_P(advice); // Ensure proper reference counting
}

// Method: Pointcut::__construct
PHP_METHOD(Pointcut, __construct)
{
    RETURN_TRUE;
}

// Method: Pointcut::matches
PHP_METHOD(Pointcut, matches)
{
    zend_string *method_name;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(method_name)
    ZEND_PARSE_PARAMETERS_END();

    php_printf("Matching method: %s\n", ZSTR_VAL(method_name));
    RETURN_TRUE;
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

// Method registration for Aspect class
const zend_function_entry aspect_methods[] = {
    PHP_ME(Aspect, __construct, arginfo_aspect_construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(Aspect, addAdvice, arginfo_aspect_add_advice, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

// Method registration for Pointcut class
const zend_function_entry pointcut_methods[] = {
    PHP_ME(Pointcut, __construct, arginfo_pointcut_construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(Pointcut, matches, arginfo_pointcut_matches, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

// Method registration for Advice class
const zend_function_entry advice_methods[] = {
    PHP_ME(Advice, __construct, arginfo_advice_construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_FE_END
};

// Module init to register classes
PHP_MINIT_FUNCTION(aop)
{
    zend_class_entry ce;

    // Register Pointcut class
    INIT_CLASS_ENTRY(ce, "Pointcut", pointcut_methods);
    pointcut_ce = zend_register_internal_class(&ce);

    // Register Advice class
    INIT_CLASS_ENTRY(ce, "Advice", advice_methods);
    advice_ce = zend_register_internal_class(&ce);
    zend_declare_property_null(advice_ce, "callback", sizeof("callback") - 1, ZEND_ACC_PRIVATE);

    // Register Aspect class
    INIT_CLASS_ENTRY(ce, "Aspect", aspect_methods);
    aspect_ce = zend_register_internal_class(&ce);
    zend_declare_property_null(aspect_ce, "adviceMap", sizeof("adviceMap") - 1, ZEND_ACC_PRIVATE);

    return SUCCESS;
}

// Module entry definition
zend_module_entry aop_module_entry = {
    STANDARD_MODULE_HEADER,
    "aop",
    NULL,
    PHP_MINIT(aop),
    NULL,
    NULL,
    NULL,
    NULL,
    NO_VERSION_YET,
    STANDARD_MODULE_PROPERTIES
};

ZEND_GET_MODULE(aop)
