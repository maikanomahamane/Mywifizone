<?php

use Illuminate\Support\Facades\Schedule;

// Le scheduler (service `scheduler` de docker-compose) exécute ceci.
Schedule::command('subscriptions:process')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('payments:expire-stale')->everyTenMinutes()->withoutOverlapping();
Schedule::command('network:maintain')->everyMinute()->withoutOverlapping();
Schedule::command('vouchers:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('monitor:check')->everyFiveMinutes()->withoutOverlapping();
