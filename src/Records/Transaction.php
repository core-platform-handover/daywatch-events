<?php

namespace Laravel\Nightwatch\Records;

final class Transaction
{
    /**
     * @param  'begin'|'committing'|'committed'|'rolled_back'  $type
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $type,
        public readonly int $level,
        public readonly int $duration,
    ) {
        //
    }
}
