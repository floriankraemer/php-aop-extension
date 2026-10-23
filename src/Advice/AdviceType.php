<?php

declare(strict_types=1);

namespace AOP\Advice;

use AOP\Attribute\After;
use AOP\Attribute\AfterReturning;
use AOP\Attribute\AfterThrowing;
use AOP\Attribute\Around;
use AOP\Attribute\Before;

enum AdviceType: string
{
    case AROUND = 'around';
    case BEFORE = 'before';
    case AFTER = 'after';
    case AFTER_RETURNING = 'after_returning';
    case AFTER_THROWING = 'after_throwing';

    /**
     * Lower values run first on the way in and last on the way out.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::AROUND => 0,
            self::BEFORE => 1,
            self::AFTER => 2,
            self::AFTER_RETURNING => 3,
            self::AFTER_THROWING => 4,
        };
    }

    /**
     * @return class-string
     */
    public function attributeClass(): string
    {
        return match ($this) {
            self::AROUND => Around::class,
            self::BEFORE => Before::class,
            self::AFTER => After::class,
            self::AFTER_RETURNING => AfterReturning::class,
            self::AFTER_THROWING => AfterThrowing::class,
        };
    }
}
