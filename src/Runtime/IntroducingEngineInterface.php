<?php

declare(strict_types=1);

namespace AOP\Runtime;

/**
 * An engine bridge that can also receive `#[DeclareParents]` rules (contract section 6). The Weaver calls it on
 * `enable()` and whenever the rules change, if the bridge implements it.
 */
interface IntroducingEngineInterface extends EngineBridgeInterface
{
    /**
     * @param list<array{typePattern: string, interface: class-string, defaultImpl: class-string}> $rules
     */
    public function setIntroductions(array $rules): void;
}
