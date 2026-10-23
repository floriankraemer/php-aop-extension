<?php

declare(strict_types=1);

namespace AOP\Advice\Api;

/**
 * Advice that runs when the advised method throws (Spring's ThrowsAdvice). A marker: it declares no method.
 *
 * The implementation declares public handler methods whose name starts with `afterThrowing`, in one of two shapes:
 *
 *     public function afterThrowing(RuntimeException $e): void
 *     public function afterThrowingIo(JoinPointInterface $joinPoint, IOException $e): void
 *
 * (PHP cannot overload `afterThrowing`, so every method named `afterThrowing*` counts.) The exception parameter
 * may be a class, a union of classes, or omitted type for any Throwable. When the advised method throws, the handler
 * for the most specific type wins: the closest class in the exception's ancestry, then the interfaces it implements.
 * Handlers cannot swallow the exception; it is rethrown after the handler ran (a handler that throws replaces it).
 */
interface ThrowsAdviceInterface extends AfterAdviceInterface
{
}
