<?php

namespace Laravel\Nightwatch\Records;

use Laravel\Nightwatch\QueryConnectionType;

final class Query
{
    /**
     * @param  list<mixed>|null  $bindings  `null` when binding capture is disabled.
     */
    public function __construct(
        public string $sql,
        public ?array $bindings,
        public string $rawSql,
        public readonly string $file,
        public readonly int $line,
        public readonly int $duration,
        public readonly string $connection,
        public readonly QueryConnectionType $connectionType,
    ) {
        //
    }
}
