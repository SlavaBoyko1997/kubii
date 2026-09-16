<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('catalog:cache-warm --trigger=auto')
    ->hourlyAt(5)
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('catalog:refresh-popularity')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('counterparties:sync-feeds --trigger=auto')
    ->cron('0 4,12,20 * * *')
    ->timezone('Europe/Kyiv')
    ->withoutOverlapping(360)
    ->runInBackground();

Schedule::command('merchant:feed:generate')
    ->dailyAt('03:00')
    ->timezone('Europe/Kyiv')
    ->withoutOverlapping(120)
    ->runInBackground();
