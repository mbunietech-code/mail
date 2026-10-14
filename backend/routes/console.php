<?php

use Illuminate\Support\Facades\Schedule;

/*
| Production runs `php artisan schedule:run` every minute (systemd timer / cron) plus
| queue workers for the `send`, `sync` and `default` queues (docs/DEPLOYMENT.md).
*/

Schedule::command('mbuniemail:sync-due')->everyMinute()->withoutOverlapping(5);
Schedule::command('mbuniemail:prune')->hourly()->withoutOverlapping();
Schedule::command('mbuniemail:prune-audit')->monthlyOn(1, '04:00')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('mbuniemail:audit-verify')->dailyAt('03:30');
Schedule::command('mbuniemail:security-alerts --window=10')->everyFiveMinutes()->withoutOverlapping();
