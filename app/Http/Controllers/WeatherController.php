<?php

namespace App\Http\Controllers;

use App\Services\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON proxy to OpenWeather for signed-in users (the key stays on the server).
 * Rate-limited per user; responses are cached by WeatherService.
 */
class WeatherController extends Controller
{
    public function show(Request $request, WeatherService $weather): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = $weather->current((float) $data['lat'], (float) $data['lon']);
        $result['attribution'] = 'Weather data © OpenWeather';

        return response()
            ->json($result, $result['status'] === 'ok' ? 200 : 503)
            ->header('Cache-Control', 'private, no-store');
    }
}
