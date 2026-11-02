<?php

namespace Tests\Feature;

use App\Jobs\RefreshWeatherData;
use App\Models\User;
use App\Services\WeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeatherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Never hit the real API from tests.
        Http::preventStrayRequests();
        Sanctum::actingAs(User::factory()->create());
    }

    private function fakeApiResponse(float $temperature = 24.5): array
    {
        return [
            'current' => [
                'time' => '2026-11-02T14:00',
                'temperature_2m' => $temperature,
                'apparent_temperature' => 23.9,
                'relative_humidity_2m' => 48,
                'precipitation' => 0.0,
                'weather_code' => 1,
                'wind_speed_10m' => 17.3,
            ],
            'daily' => [
                'time' => ['2026-11-02'],
                'temperature_2m_max' => [27.1],
                'temperature_2m_min' => [15.4],
                'precipitation_probability_max' => [5],
            ],
        ];
    }

    public function test_returns_current_weather_for_perth(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response($this->fakeApiResponse())]);

        $this->getJson('/api/weather')
            ->assertOk()
            ->assertJsonPath('data.location', 'Perth, Australia')
            ->assertJsonPath('data.temperature_c', 24.5)
            ->assertJsonPath('data.condition', 'Mainly clear')
            ->assertJsonPath('data.today.max_c', 27.1)
            ->assertJsonPath('meta.stale', false);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'latitude=-31.9523')
            && str_contains($request->url(), 'timezone=Australia%2FPerth'));
    }

    public function test_weather_is_cached_between_requests(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response($this->fakeApiResponse())]);

        $this->getJson('/api/weather')->assertOk();
        $this->getJson('/api/weather')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_cache_expires_after_configured_minutes(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response($this->fakeApiResponse())]);

        $this->getJson('/api/weather')->assertOk();
        $this->travel(16)->minutes();
        $this->getJson('/api/weather')->assertOk();

        Http::assertSentCount(2);
    }

    public function test_returns_503_when_api_fails_and_nothing_is_cached(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['error' => true], 500)]);

        $this->getJson('/api/weather')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Weather data is temporarily unavailable. Please try again later.');
    }

    public function test_returns_503_on_unexpected_payload(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['unexpected' => 'shape'])]);

        $this->getJson('/api/weather')->assertStatus(503);
    }

    public function test_serves_last_known_data_as_stale_when_api_fails(): void
    {
        Http::fakeSequence('api.open-meteo.com/*')
            ->push($this->fakeApiResponse(22.0))
            ->whenEmpty(Http::response(null, 502));

        $this->getJson('/api/weather')->assertOk();

        Cache::forget(WeatherService::CACHE_KEY); // simulate the 15-minute cache expiring

        $this->getJson('/api/weather')
            ->assertOk()
            ->assertJsonPath('data.temperature_c', 22)
            ->assertJsonPath('meta.stale', true);
    }

    public function test_refresh_job_updates_the_cache(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response($this->fakeApiResponse(30.2))]);

        RefreshWeatherData::dispatchSync();

        $this->assertSame(30.2, Cache::get(WeatherService::CACHE_KEY)['temperature_c']);
    }
}
