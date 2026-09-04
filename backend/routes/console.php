<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// All business-facing schedules run on Manila wall-clock — the server itself is
// UTC (config/app.php), so without an explicit timezone "07:00" would fire at
// 3 PM Manila.
// Generate due recurring invoices for every org, once a day.
Schedule::command('accounting:generate-recurring')->dailyAt('01:00')->timezone('Asia/Manila');

// Recognize deferred revenue due today, once a day.
Schedule::command('accounting:recognize-revenue')->dailyAt('01:30')->timezone('Asia/Manila');

// Email subscription expiry reminders, once a day.
Schedule::command('accounting:subscription-reminders')->dailyAt('08:00')->timezone('Asia/Manila');

// Email inventory low-stock + expiry alerts to each org, once a day.
Schedule::command('inventory:send-alerts')->dailyAt('07:00')->timezone('Asia/Manila');
