<?php

namespace Tests\Feature\Sensors;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

use function hash;
use function in_array;
use function now;

class TransactionSensorTest extends TestCase
{
    protected function setUp(): void
    {
        $this->forceRequestExecutionState();

        parent::setUp();

        $this->setDeploy('v1.2.3');
        $this->setServerName('web-01');
        $this->setPeakMemory(1234);
        $this->setTraceId('00000000-0000-0000-0000-000000000000');
        $this->setExecutionId('00000000-0000-0000-0000-000000000001');
        $this->setExecutionStart(CarbonImmutable::parse('2000-01-01 01:02:03.456789'));
    }

    private function connectionName(): string
    {
        $this->assertTrue(in_array($connection = Config::get('database.default'), ['testing', 'sqlite'], true));

        return $connection;
    }

    public function test_it_can_ingest_begin_and_committed_transaction_events(): void
    {
        $ingest = $this->fakeIngest();
        Route::get('/users', function () {
            return DB::transaction(function () {
                $this->travelTo(now()->addMicroseconds(4321));

                return [];
            });
        });

        $response = $this->get('/users');

        $connection = $this->connectionName();

        $response->assertOk();
        $ingest->assertWrittenTimes(1);
        // The test itself runs inside the `RefreshDatabase` transaction, so
        // the transaction under observation begins at level 2.
        $ingest->assertLatestWrite('transaction:*', [
            [
                'v' => 1,
                't' => 'transaction',
                'timestamp' => 946688523.456789,
                'deploy' => 'v1.2.3',
                'server' => 'web-01',
                '_group' => hash('xxh128', $connection),
                'trace_id' => '00000000-0000-0000-0000-000000000000',
                'execution_source' => 'request',
                'execution_id' => '00000000-0000-0000-0000-000000000001',
                'execution_preview' => 'GET /users',
                'execution_stage' => 'action',
                'user' => '',
                'connection' => $connection,
                'type' => 'begin',
                'level' => 2,
                'duration' => 0,
            ],
            [
                'v' => 1,
                't' => 'transaction',
                'timestamp' => 946688523.456789,
                'deploy' => 'v1.2.3',
                'server' => 'web-01',
                '_group' => hash('xxh128', $connection),
                'trace_id' => '00000000-0000-0000-0000-000000000000',
                'execution_source' => 'request',
                'execution_id' => '00000000-0000-0000-0000-000000000001',
                'execution_preview' => 'GET /users',
                'execution_stage' => 'action',
                'user' => '',
                'connection' => $connection,
                'type' => 'committed',
                'level' => 2,
                'duration' => 4321,
            ],
        ]);
    }

    public function test_it_can_ingest_rolled_back_transaction_events(): void
    {
        $ingest = $this->fakeIngest();
        Route::get('/users', function () {
            try {
                DB::transaction(function (): void {
                    $this->travelTo(now()->addMicroseconds(4321));

                    throw new RuntimeException('Whoops!');
                });
            } catch (RuntimeException) {
                //
            }

            return [];
        });

        $response = $this->get('/users');

        $response->assertOk();
        $ingest->assertWrittenTimes(1);
        $ingest->assertLatestWrite('transaction:0.type', 'begin');
        $ingest->assertLatestWrite('transaction:1.type', 'rolled_back');
        $ingest->assertLatestWrite('transaction:1.level', 2);
        $ingest->assertLatestWrite('transaction:1.duration', 4321);
        $ingest->assertLatestWrite('transaction:1.timestamp', 946688523.456789);
    }

    public function test_it_can_ingest_nested_transaction_events(): void
    {
        $ingest = $this->fakeIngest();
        Route::get('/users', function () {
            return DB::transaction(function () {
                $this->travelTo(now()->addMicroseconds(1000));

                DB::transaction(function (): void {
                    $this->travelTo(now()->addMicroseconds(500));
                });

                $this->travelTo(now()->addMicroseconds(1000));

                return [];
            });
        });

        $response = $this->get('/users');

        $response->assertOk();
        $ingest->assertWrittenTimes(1);

        $ingest->assertLatestWrite('transaction:0.type', 'begin');
        $ingest->assertLatestWrite('transaction:0.level', 2);

        $ingest->assertLatestWrite('transaction:1.type', 'begin');
        $ingest->assertLatestWrite('transaction:1.level', 3);
        $ingest->assertLatestWrite('transaction:1.timestamp', 946688523.457789);

        $ingest->assertLatestWrite('transaction:2.type', 'committed');
        $ingest->assertLatestWrite('transaction:2.level', 3);
        $ingest->assertLatestWrite('transaction:2.duration', 500);
        $ingest->assertLatestWrite('transaction:2.timestamp', 946688523.457789);

        $ingest->assertLatestWrite('transaction:3.type', 'committed');
        $ingest->assertLatestWrite('transaction:3.level', 2);
        $ingest->assertLatestWrite('transaction:3.duration', 2500);
        $ingest->assertLatestWrite('transaction:3.timestamp', 946688523.456789);
    }

    public function test_it_can_ingest_committing_transaction_events(): void
    {
        $ingest = $this->fakeIngest();
        Route::get('/users', function () {
            // `committing` only fires when the outermost level commits, which
            // `RefreshDatabase` holds open, so we dispatch it manually.
            Event::dispatch(new TransactionCommitting(DB::connection()));

            return [];
        });

        $response = $this->get('/users');

        $response->assertOk();
        $ingest->assertWrittenTimes(1);
        $ingest->assertLatestWrite('transaction:0.type', 'committing');
        $ingest->assertLatestWrite('transaction:0.level', 1);
        $ingest->assertLatestWrite('transaction:0.duration', 0);
    }

    public function test_it_can_ignore_transactions(): void
    {
        $ingest = $this->fakeIngest();
        $this->core->config['filtering']['ignore_transactions'] = true;
        Route::get('/users', fn () => DB::transaction(fn () => []));

        $response = $this->get('/users');

        $response->assertOk();
        $ingest->assertWrittenTimes(1);
        $ingest->assertLatestWrite('transaction:*', []);
    }
}
