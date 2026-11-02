<?php

namespace App\Services;

use App\Exceptions\WeatherUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fetches today's weather for Perth from Open-Meteo (free, no API key).
 *
 * Caching strategy:
 *  - "weather:current" holds fresh data for WEATHER_CACHE_MINUTES (default 15).
 *  - "weather:last_good" keeps the last successful result with no expiry, so the
 *    endpoint can still answer (marked as stale) if the external API is down.
 *  - An hourly scheduled job calls refresh() so most requests never wait on the API.
 */
class WeatherService
{
    public const CACHE_KEY = 'weather:current';

    public const LAST_GOOD_KEY = 'weather:last_good';

    /** WMO weather interpretation codes used by Open-Meteo. */
    private const CONDITIONS = [
        0 => 'Clear sky',
        1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
        45 => 'Fog', 48 => 'Depositing rime fog',
        51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle',
        56 => 'Light freezing drizzle', 57 => 'Dense freezing drizzle',
        61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
        66 => 'Light freezing rain', 67 => 'Heavy freezing rain',
        71 => 'Slight snow', 73 => 'Moderate snow', 75 => 'Heavy snow', 77 => 'Snow grains',
        80 => 'Slight rain showers', 81 => 'Moderate rain showers', 82 => 'Violent rain showers',
        85 => 'Slight snow showers', 86 => 'Heavy snow showers',
        95 => 'Thunderstorm', 96 => 'Thunderstorm with slight hail', 99 => 'Thunderstorm with heavy hail',
    ];

    public function __construct(private readonly Http $http) {}

    /**
     * Current weather, served from cache when possible.
     *
     * @return array{data: array<string, mixed>, stale: bool}
     *
     * @throws WeatherUnavailableException when the API fails and nothing is cached.
     */
    public function current(): array
    {
        if ($cached = Cache::get(self::CACHE_KEY)) {
            return ['data' => $cached, 'stale' => false];
        }

        try {
            return ['data' => $this->refresh(), 'stale' => false];
        } catch (WeatherUnavailableException $e) {
            if ($lastGood = Cache::get(self::LAST_GOOD_KEY)) {
                return ['data' => $lastGood, 'stale' => true];
            }

            throw $e;
        }
    }

    /**
     * Fetch fresh data from the API and store it in the cache.
     *
     * @return array<string, mixed>
     *
     * @throws WeatherUnavailableException
     */
    public function refresh(): array
    {
        $data = $this->fetch();

        Cache::put(self::CACHE_KEY, $data, now()->addMinutes(config('services.weather.cache_minutes')));
        Cache::forever(self::LAST_GOOD_KEY, $data);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        $config = config('services.weather');

        try {
            $response = $this->http
                ->baseUrl($config['base_url'])
                ->acceptJson()
                ->timeout($config['timeout'])
                ->retry(2, 200, throw: false)
                ->get('/v1/forecast', [
                    'latitude' => $config['latitude'],
                    'longitude' => $config['longitude'],
                    'timezone' => $config['timezone'],
                    'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,weather_code,wind_speed_10m',
                    'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                    'forecast_days' => 1,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Weather API unreachable', ['error' => $e->getMessage()]);

            throw new WeatherUnavailableException('Weather service is unreachable.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('Weather API returned an error', ['status' => $response->status()]);

            throw new WeatherUnavailableException("Weather service returned HTTP {$response->status()}.");
        }

        $current = $response->json('current');
        $daily = $response->json('daily');

        if (! is_array($current) || ! isset($current['temperature_2m'], $current['weather_code'])) {
            Log::warning('Weather API returned an unexpected payload');

            throw new WeatherUnavailableException('Weather service returned an unexpected response.');
        }

        return [
            'location' => $config['location_name'],
            'timezone' => $config['timezone'],
            'observed_at' => $current['time'] ?? null,
            'condition' => self::CONDITIONS[$current['weather_code']] ?? 'Unknown',
            'temperature_c' => $current['temperature_2m'],
            'feels_like_c' => $current['apparent_temperature'] ?? null,
            'humidity_percent' => $current['relative_humidity_2m'] ?? null,
            'precipitation_mm' => $current['precipitation'] ?? null,
            'wind_speed_kmh' => $current['wind_speed_10m'] ?? null,
            'today' => [
                'date' => $daily['time'][0] ?? null,
                'min_c' => $daily['temperature_2m_min'][0] ?? null,
                'max_c' => $daily['temperature_2m_max'][0] ?? null,
                'precipitation_chance_percent' => $daily['precipitation_probability_max'][0] ?? null,
            ],
            'source' => 'open-meteo.com',
            'fetched_at' => now()->toIso8601String(),
        ];
    }
}
