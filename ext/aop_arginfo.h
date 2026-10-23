/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 2c9a5198e1f96ee674c6763de0ce4c7e4d186f33 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_AOP_Internal_intercept, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_AOP_Internal_result, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_AOP_Internal_caught, 0, 2, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, exception, Throwable, 0)
	ZEND_ARG_TYPE_INFO(0, classes, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_AOP_Engine___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_AOP_Engine_register, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, matcher, IS_CALLABLE, 0)
	ZEND_ARG_TYPE_INFO(0, dispatcher, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_AOP_Engine_unregister, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_AOP_Engine_isRegistered arginfo_AOP_Internal_intercept

#define arginfo_class_AOP_Engine_reset arginfo_class_AOP_Engine_unregister

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_AOP_Engine_setIntroductions, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, rules, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_AOP_Engine_fieldInterceptionAvailable arginfo_AOP_Internal_intercept

#define arginfo_class_AOP_OriginalCall___construct arginfo_class_AOP_Engine___construct

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_AOP_OriginalCall___invoke, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, args, IS_ARRAY, 0)
ZEND_END_ARG_INFO()


ZEND_FUNCTION(AOP_Internal_intercept);
ZEND_FUNCTION(AOP_Internal_result);
ZEND_FUNCTION(AOP_Internal_caught);
ZEND_METHOD(AOP_Engine, __construct);
ZEND_METHOD(AOP_Engine, register);
ZEND_METHOD(AOP_Engine, unregister);
ZEND_METHOD(AOP_Engine, isRegistered);
ZEND_METHOD(AOP_Engine, reset);
ZEND_METHOD(AOP_Engine, setIntroductions);
ZEND_METHOD(AOP_Engine, fieldInterceptionAvailable);
ZEND_METHOD(AOP_OriginalCall, __construct);
ZEND_METHOD(AOP_OriginalCall, __invoke);


static const zend_function_entry ext_functions[] = {
	ZEND_NS_FALIAS("AOP\\Internal", intercept, AOP_Internal_intercept, arginfo_AOP_Internal_intercept)
	ZEND_NS_FALIAS("AOP\\Internal", result, AOP_Internal_result, arginfo_AOP_Internal_result)
	ZEND_NS_FALIAS("AOP\\Internal", caught, AOP_Internal_caught, arginfo_AOP_Internal_caught)
	ZEND_FE_END
};


static const zend_function_entry class_AOP_Engine_methods[] = {
	ZEND_ME(AOP_Engine, __construct, arginfo_class_AOP_Engine___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(AOP_Engine, register, arginfo_class_AOP_Engine_register, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(AOP_Engine, unregister, arginfo_class_AOP_Engine_unregister, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(AOP_Engine, isRegistered, arginfo_class_AOP_Engine_isRegistered, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(AOP_Engine, reset, arginfo_class_AOP_Engine_reset, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(AOP_Engine, setIntroductions, arginfo_class_AOP_Engine_setIntroductions, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(AOP_Engine, fieldInterceptionAvailable, arginfo_class_AOP_Engine_fieldInterceptionAvailable, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_FE_END
};


static const zend_function_entry class_AOP_OriginalCall_methods[] = {
	ZEND_ME(AOP_OriginalCall, __construct, arginfo_class_AOP_OriginalCall___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(AOP_OriginalCall, __invoke, arginfo_class_AOP_OriginalCall___invoke, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_AOP_Engine(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "AOP", "Engine", class_AOP_Engine_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;

	zval const_ADVISE_value;
	ZVAL_LONG(&const_ADVISE_value, 1);
	zend_string *const_ADVISE_name = zend_string_init_interned("ADVISE", sizeof("ADVISE") - 1, 1);
	zend_declare_typed_class_constant(class_entry, const_ADVISE_name, &const_ADVISE_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(const_ADVISE_name);

	zval const_TRACK_value;
	ZVAL_LONG(&const_TRACK_value, 2);
	zend_string *const_TRACK_name = zend_string_init_interned("TRACK", sizeof("TRACK") - 1, 1);
	zend_declare_typed_class_constant(class_entry, const_TRACK_name, &const_TRACK_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(const_TRACK_name);

	zval const_CALLER_SENSITIVE_value;
	ZVAL_LONG(&const_CALLER_SENSITIVE_value, 4);
	zend_string *const_CALLER_SENSITIVE_name = zend_string_init_interned("CALLER_SENSITIVE", sizeof("CALLER_SENSITIVE") - 1, 1);
	zend_declare_typed_class_constant(class_entry, const_CALLER_SENSITIVE_name, &const_CALLER_SENSITIVE_value, ZEND_ACC_PUBLIC, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(const_CALLER_SENSITIVE_name);

	return class_entry;
}

static zend_class_entry *register_class_AOP_OriginalCall(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "AOP", "OriginalCall", class_AOP_OriginalCall_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL;

	return class_entry;
}
