<?php

use App\Jobs\RefreshWeatherData;
use Illuminate\Support\Facades\Schedule;

// Keep the Perth weather cache fresh. Requires `php artisan schedule:work` (or a cron entry)
// plus a running queue worker.
Schedule::job(new RefreshWeatherData)->hourly()->withoutOverlapping();
