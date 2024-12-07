#ifndef JOINPOINT_H
#define JOINPOINT_H

#include "php.h"

extern zend_class_entry *joinpoint_ce;

// This function will be implemented in joinpoint.c to initialize the JoinPoint class
void joinpoint_init();

#endif /* JOINPOINT_H */