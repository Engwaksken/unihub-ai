<?php

use Illuminate\Support\Facades\Schedule;

// cPanel cron runs `php artisan schedule:run` every minute (ADR D12).

// E-mail new gateway alerts, and a local "gateway unreachable" alert after
// 2 minutes of failed polls (ADR D13).
Schedule::command('cms:notify-alerts')
    ->everyMinute()
    ->withoutOverlapping(5);
