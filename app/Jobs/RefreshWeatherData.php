<?php

namespace App\Jobs;

use App\Services\WeatherService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs every hour (see routes/console.php) to keep the weather cache warm.
 */
class RefreshWeatherData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds to wait between retries. */
    public array $backoff = [30, 120];

    /** Don't queue a second refresh while one is still waiting. */
    public int $uniqueFor = 600;

    public function handle(WeatherService $weather): void
    {
        $weather->refresh();
    }
}
