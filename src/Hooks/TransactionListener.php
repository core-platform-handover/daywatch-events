<?php

namespace Laravel\Nightwatch\Hooks;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class TransactionListener
{
    /**
     * @param  Core<RequestState|CommandState>  $nightwatch
     */
    public function __construct(
        private Core $nightwatch,
    ) {
        //
    }

    public function __invoke(TransactionBeginning|TransactionCommitting|TransactionCommitted|TransactionRolledBack $event): void
    {
        try {
            $this->nightwatch->transaction($event);
        } catch (Throwable $e) {
            $this->nightwatch->report($e, handled: true);
        }
    }
}
