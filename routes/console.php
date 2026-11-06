<?php

use Illuminate\Support\Facades\Schedule;

// Requires one cron entry: * * * * * php /path/to/artisan schedule:run
Schedule::command('integrity:check')->dailyAt('02:00')->timezone(config('app.business_timezone'))->withoutOverlapping();
Schedule::command('exports:prune')->hourly();
Schedule::command('queue:prune-failed --hours=720')->weekly();
