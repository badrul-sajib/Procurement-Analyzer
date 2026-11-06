<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Transactions
{
    /**
     * Run a capacity-checking operation (purchase, delivery, payment allocation).
     *
     * Under MySQL's default REPEATABLE READ, a transaction that waits on a row lock keeps the
     * snapshot it took before waiting and would not see what the lock holder just committed,
     * so it could over-allocate. READ COMMITTED makes every statement read the latest committed
     * data, so totals recomputed after acquiring the lock are always current.
     */
    public static function critical(\Closure $callback): mixed
    {
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); // applies to the next transaction only
        }

        return DB::transaction($callback, 3);
    }
}
