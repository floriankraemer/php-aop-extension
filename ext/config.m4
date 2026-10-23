dnl config.m4 for the aop extension

PHP_ARG_ENABLE([aop],
  [whether to enable aop support],
  [AS_HELP_STRING([--enable-aop], [Enable native aspect-oriented programming support])],
  [no])

if test "$PHP_AOP" != "no"; then
  dnl Every stream appends its own translation units here.
  PHP_NEW_EXTENSION(aop, aop.c aop_engine.c aop_hook.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1)
fi
