<?php

namespace App\Http\Controllers;

class AppVersionController extends Controller
{
    public function getLatestVersion()
    {
        return response()->json([
            'status'         => 'success',
            'latest_version' => config('app_version.latest_version', '1.2.22'),
            'min_version'    => config('app_version.min_version', '1.2.22'),
            'is_force_update' => filter_var(config('app_version.force_update', false), FILTER_VALIDATE_BOOLEAN),
            'playstore_url'  => config('app_version.playstore_url', 'https://play.google.com/store/apps/details?id=com.syhnwu.posandroid'),
        ]);
    }
}
