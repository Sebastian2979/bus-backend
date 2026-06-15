<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('gtfs:check-and-import')
    ->twiceWeekly(3, 5, '13:00')
    ->withoutOverlapping()
    ->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
