dnl config.m4 for aop

PHP_ARG_ENABLE(aop, whether to enable aop support,
[  --enable-aop     Enable aop support])

if test "$PHP_aop" != "no"; then
  PHP_NEW_EXTENSION(aop, aop.c, $ext_shared)
fi