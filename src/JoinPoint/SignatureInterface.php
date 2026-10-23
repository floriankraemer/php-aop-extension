<?php

declare(strict_types=1);

namespace AOP\JoinPoint;

interface SignatureInterface
{
    /**
     * Method, constructor or function name as declared (namespaced for functions); property name for fields;
     * the caught type for exception handlers.
     */
    public function getName(): string;

    /**
     * The class declaring the member; null for functions.
     */
    public function getDeclaringTypeName(): ?string;

    /**
     * Examples: `UserService::find(..)`, `fmt(..)`, `UserService::$count`, `catch(RuntimeException)`.
     */
    public function toShortString(): string;

    /**
     * Like toShortString(), fully qualified and with parameter types where they exist.
     */
    public function toLongString(): string;
}
