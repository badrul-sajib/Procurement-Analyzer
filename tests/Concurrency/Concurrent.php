<?php

namespace Tests\Concurrency;

use Illuminate\Support\Facades\DB;

/** Run $child in a forked process with its own DB connection; $parent runs meanwhile in this one. */
final class Concurrent
{
    public static function run(\Closure $child, \Closure $parent, int $parentDelayMs = 300): array
    {
        if (! function_exists('pcntl_fork')) {
            test()->markTestSkipped('pcntl is required for concurrency tests');
        }
        $resultFile = tempnam(sys_get_temp_dir(), 'conc');
        DB::disconnect(); // never share a socket across fork

        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            try {
                $result = ['ok' => true, 'value' => $child()];
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'error' => $e::class, 'code' => property_exists($e, 'errorCode') ? $e->errorCode : null];
            }
            file_put_contents($resultFile, serialize($result));
            posix_kill(getmypid(), SIGKILL); // skip PHPUnit shutdown handlers in the child
        }

        usleep($parentDelayMs * 1000);
        DB::reconnect();
        try {
            $parentResult = ['ok' => true, 'value' => $parent()];
        } catch (\Throwable $e) {
            $parentResult = ['ok' => false, 'error' => $e::class, 'code' => property_exists($e, 'errorCode') ? $e->errorCode : null];
        }
        pcntl_waitpid($pid, $status);
        $childResult = unserialize(file_get_contents($resultFile));
        unlink($resultFile);

        return [$childResult, $parentResult];
    }
}
