<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        return Inertia::render('Settings/Index', [
            'settings' => [
                'max_active_leads' => 10,
                'recycle_attempts' => 3,
                'callback_expiry_hours' => 24,
            ],
            'user' => $currentUser->only([
                'id', 'name', 'email', 'phone', 'role',
                'theme', 'language', 'timezone',
            ]),
            'system_settings' => [
                'company_name' => 'TECS Warehouse Operations',
                'timezone' => 'Asia/Manila',
                'date_format' => 'MM/DD/YYYY',
                'time_format' => '12 Hour (AM/PM)',
                'currency' => 'PHP - Philippine Peso',
                'language' => 'en',
            ],
            'integrations' => [
                ['name' => 'Google Workspace', 'icon' => 'google', 'status' => 'connected', 'description' => 'Email and calendar sync'],
                ['name' => 'Slack', 'icon' => 'slack', 'status' => 'connected', 'description' => 'Team notifications'],
                ['name' => 'Microsoft 365', 'icon' => 'microsoft', 'status' => 'disconnected', 'description' => 'Office integration'],
                ['name' => 'Webhook', 'icon' => 'webhook', 'status' => 'connected', 'description' => 'Custom event notifications'],
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $request->user()->update($validated);
        ActivityLog::log('update_profile', $request->user(), 'user', null, ['user_id' => $request->user()->id]);

        return redirect()->back(303)->with('success', 'Profile updated.');
    }

    public function updateAppearance(Request $request)
    {
        $validated = $request->validate([
            'theme'    => ['required', 'in:light,dark,system'],
            'language' => ['required', 'in:en,tl'],
            'timezone' => ['required', 'string', 'max:50'],
        ]);

        $request->user()->update($validated);

        return redirect()->back(303)->with('success', 'Appearance saved.');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);
        ActivityLog::log('update_password', $request->user(), 'user', null, ['user_id' => $request->user()->id]);

        return redirect()->back(303)->with('success', 'Password updated.');
    }

    /* ─── System Settings ─── */

    public function updateSystemSettings(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'timezone'     => ['required', 'string', 'max:50'],
            'date_format'  => ['required', 'in:MM/DD/YYYY,DD/MM/YYYY,YYYY-MM-DD'],
            'time_format'  => ['required', 'in:12,24'],
            'currency'     => ['required', 'string', 'max:50'],
            'language'     => ['required', 'in:en,tl'],
        ]);

        ActivityLog::log('update_system_settings', $request->user(), 'system', null, $validated);

        return redirect()->back(303)->with('success', 'System settings saved.');
    }
}
