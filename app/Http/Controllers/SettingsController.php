<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Settings/Index', [
            'settings' => [
                'max_active_leads' => 10,
                'recycle_attempts' => 3,
                'callback_expiry_hours' => 24,
            ],
            'user' => $request->user(),
        ]);
    }
}
