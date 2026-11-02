<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WeatherUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\WeatherService;
use Illuminate\Http\JsonResponse;

class WeatherController extends Controller
{
    public function __invoke(WeatherService $weather): JsonResponse
    {
        try {
            $result = $weather->current();
        } catch (WeatherUnavailableException) {
            return response()->json([
                'message' => 'Weather data is temporarily unavailable. Please try again later.',
            ], 503);
        }

        return response()->json([
            'data' => $result['data'],
            'meta' => ['stale' => $result['stale']],
        ]);
    }
}
