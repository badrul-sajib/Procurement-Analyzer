<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => Settings::all() + ['business_timezone' => config('app.business_timezone')]]);
    }

    public function update(Request $request): JsonResponse
    {
        $values = $request->validate(Settings::RULES);
        $before = array_intersect_key(Settings::all(), $values);
        Settings::put($values, $request->user()->id);
        AuditLogger::log('settings.updated', 'settings', null, 'System settings updated', $before, $values);

        return $this->show();
    }
}
