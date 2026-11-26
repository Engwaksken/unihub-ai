<?php

use Illuminate\Support\Facades\Schedule;

// Run via `php artisan schedule:work` under systemd (infra/systemd/unihub-gateway-scheduler.service).
Schedule::command('gateway:sample')->everyThirtySeconds()->withoutOverlapping();
Schedule::command('gateway:purge')->everyFifteenMinutes()->withoutOverlapping();
