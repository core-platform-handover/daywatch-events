<?php

namespace Laravel\Nightwatch\Sensors;

use Illuminate\Database\Events\QueryExecuted;
use Laravel\Nightwatch\Clock;
use Laravel\Nightwatch\Compatibility;
use Laravel\Nightwatch\Location;
use Laravel\Nightwatch\QueryConnectionType;
use Laravel\Nightwatch\Records\Query;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Laravel\Nightwatch\Types\Str;
use Throwable;

use function hash;
use function in_array;
use function json_encode;
use function method_exists;
use function preg_replace;
use function round;
use function str_contains;

/**
 * @internal
 */
final class QuerySensor
{
    public function __construct(
        private RequestState|CommandState $executionState,
        private Clock $clock,
        private Location $location,
        private bool $captureBindings = false,
    ) {
        //
    }

    /**
     * @param  list<array{ file?: string, line?: int }>  $trace
     * @return array{0: Query, 1: callable(): array<mixed>}
     */
    public function __invoke(QueryExecuted $event, array $trace): array
    {
        $durationInMicroseconds = (int) round($event->time * 1000);

        [$file, $line] = $this->location->forQueryTrace($trace);

        $connectionType = Compatibility::$queryConnectionTypeCapturable && $event->readWriteType !== null
            ? QueryConnectionType::from($event->readWriteType)
            : QueryConnectionType::Unknown;

        return [
            $record = new Query(
                sql: $event->sql,
                bindings: $this->captureBindings ? $this->preparedBindings($event) : null,
                rawSql: $this->captureBindings ? $this->rawSql($event) : '',
                file: $file ?? '',
                line: $line ?? 0,
                duration: $durationInMicroseconds,
                connection: $event->connectionName ?? '', // @phpstan-ignore nullCoalesce.property
                connectionType: $connectionType,
            ),
            function () use ($event, $record) {
                $this->executionState->queries++;

                return [
                    'v' => 1,
                    't' => 'query',
                    'timestamp' => $this->clock->microtime() - ($event->time / 1000),
                    'deploy' => $this->executionState->deploy,
                    'server' => $this->executionState->server,
                    '_group' => $this->hash($event, $record),
                    'trace_id' => $this->executionState->trace,
                    'execution_source' => $this->executionState->source,
                    'execution_id' => $this->executionState->id(),
                    'execution_preview' => $this->executionState->executionPreview(),
                    'execution_stage' => $this->executionState->stage,
                    'user' => $this->executionState->user->id(),
                    'sql' => Str::mediumText($record->sql),
                    'bindings' => $this->serializedBindings($record),
                    'raw_sql' => Str::mediumText($record->rawSql),
                    'file' => Str::tinyText($record->file),
                    'line' => $record->line,
                    'duration' => $record->duration,
                    'connection' => Str::tinyText($record->connection),
                    'connection_type' => $record->connectionType === QueryConnectionType::Unknown ? '' : $record->connectionType->value,
                ];
            },
        ];
    }

    /**
     * @return list<mixed>
     */
    private function preparedBindings(QueryExecuted $event): array
    {
        try {
            return $event->connection->prepareBindings($event->bindings);
        } catch (Throwable) {
            return $event->bindings;
        }
    }

    private function rawSql(QueryExecuted $event): string
    {
        try {
            if (method_exists($event, 'toRawSql')) {
                return $event->toRawSql();
            }

            $grammar = $event->connection->getQueryGrammar();

            if (method_exists($grammar, 'substituteBindingsIntoRawSql')) {
                return $grammar->substituteBindingsIntoRawSql(
                    $event->sql, $event->connection->prepareBindings($event->bindings)
                );
            }
        } catch (Throwable) {
            //
        }

        return '';
    }

    private function serializedBindings(Query $record): string
    {
        if ($record->bindings === null) {
            return '';
        }

        try {
            return Str::mediumText((string) json_encode($record->bindings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (Throwable) {
            return '["_nightwatch_error: Failed to serialize bindings"]';
        }
    }

    private function hash(QueryExecuted $event, Query $record): string
    {
        if (! in_array($event->connection->getDriverName(), ['mariadb', 'mysql', 'pgsql', 'sqlite', 'sqlsrv', 'singlestore'], true)) {
            return hash('xxh128', "{$record->connection},{$record->sql}");
        }

        $sql = preg_replace('/in \([\d?\s,]+\)/', 'in (...?)', $record->sql) ?? $record->sql;

        if (str_contains($sql, 'insert')) {
            $sql = preg_replace('/values [(?,\s)]+/', 'values ...', $sql) ?? $sql;
        }

        return hash('xxh128', "{$record->connection},{$sql}");
    }
}
