<?php

use Illuminate\Support\Facades\Schedule;

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('holidays:send-reminders')
    ->hourly()
    ->withoutOverlapping();


Schedule::command('holidays:send-balance-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping();
