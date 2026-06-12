<?php

namespace Laravel\Nightwatch\Sensors;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Laravel\Nightwatch\Clock;
use Laravel\Nightwatch\Records\Transaction;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Laravel\Nightwatch\Types\Str;
use RuntimeException;

use function hash;
use function round;

/**
 * @internal
 */
final class TransactionSensor
{
    /**
     * Microtime each transaction level began at, per connection. Lets the
     * terminal events report a duration even when levels are unwound several
     * at a time (e.g. `DB::rollBack(0)`).
     *
     * @var array<string, array<int, float>>
     */
    private array $beginTimes = [];

    public function __construct(
        private RequestState|CommandState $executionState,
        private Clock $clock,
    ) {
        //
    }

    /**
     * @return array{0: Transaction, 1: callable(): array<mixed>}
     */
    public function __invoke(TransactionBeginning|TransactionCommitting|TransactionCommitted|TransactionRolledBack $event): array
    {
        $now = $this->clock->microtime();

        /** @var string */
        $connection = $event->connectionName ?? '';

        $currentLevel = $event->connection->transactionLevel();

        [$type, $level, $startedAt] = match ($event::class) {
            TransactionBeginning::class => ['begin', $currentLevel, $this->rememberBegin($connection, $currentLevel, $now)],
            TransactionCommitting::class => ['committing', $currentLevel, $now],
            TransactionCommitted::class => ['committed', $currentLevel + 1, $this->forgetBegins($connection, $currentLevel + 1) ?? $now],
            TransactionRolledBack::class => ['rolled_back', $currentLevel + 1, $this->forgetBegins($connection, $currentLevel + 1) ?? $now],
            default => throw new RuntimeException('Unexpected event type ['.$event::class.']'),
        };

        return [
            $record = new Transaction(
                connection: $connection,
                type: $type,
                level: $level,
                duration: (int) round(($now - $startedAt) * 1_000_000),
            ),
            function () use ($startedAt, $record) {
                return [
                    'v' => 1,
                    't' => 'transaction',
                    'timestamp' => $startedAt,
                    'deploy' => $this->executionState->deploy,
                    'server' => $this->executionState->server,
                    '_group' => hash('xxh128', $record->connection),
                    'trace_id' => $this->executionState->trace,
                    'execution_source' => $this->executionState->source,
                    'execution_id' => $this->executionState->id(),
                    'execution_preview' => $this->executionState->executionPreview(),
                    'execution_stage' => $this->executionState->stage,
                    'user' => $this->executionState->user->id(),
                    // --- //
                    'connection' => Str::tinyText($record->connection),
                    'type' => $record->type,
                    'level' => $record->level,
                    'duration' => $record->duration,
                ];
            },
        ];
    }

    private function rememberBegin(string $connection, int $level, float $now): float
    {
        $this->beginTimes[$connection][$level] = $now;

        return $now;
    }

    /**
     * Forget every level at or above the one being closed and return when the
     * closed level began, or `null` when the begin event was never seen.
     */
    private function forgetBegins(string $connection, int $closedLevel): ?float
    {
        $startedAt = $this->beginTimes[$connection][$closedLevel] ?? null;

        foreach ($this->beginTimes[$connection] ?? [] as $level => $time) {
            if ($level >= $closedLevel) {
                unset($this->beginTimes[$connection][$level]);
            }
        }

        return $startedAt;
    }
}
