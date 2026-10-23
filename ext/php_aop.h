#ifndef PHP_AOP_H
#define PHP_AOP_H

extern zend_module_entry aop_module_entry;
#define phpext_aop_ptr &aop_module_entry

#define PHP_AOP_VERSION "0.1.0-dev"

#if defined(ZTS) && defined(COMPILE_DL_AOP)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif /* PHP_AOP_H */
