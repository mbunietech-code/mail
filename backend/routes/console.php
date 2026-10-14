<?php

use Illuminate\Support\Facades\Schedule;

/*
| Production runs `php artisan schedule:run` every minute (systemd timer / cron) plus
| queue workers for the `send`, `sync` and `default` queues (docs/DEPLOYMENT.md).
*/

Schedule::command('mbuniemail:sync-due')->everyMinute()->withoutOverlapping(5);
Schedule::command('mbuniemail:prune')->hourly()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('mbuniemail:audit-verify')->dailyAt('03:30');
