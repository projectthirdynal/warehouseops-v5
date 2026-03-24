<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
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
            'user' => $request->user()->only([
                'id', 'name', 'email', 'phone', 'role',
                'theme', 'language', 'timezone',
            ]),
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

        return back()->with('success', 'Profile updated.');
    }

    public function updateAppearance(Request $request)
    {
        $validated = $request->validate([
            'theme'    => ['required', 'in:light,dark,system'],
            'language' => ['required', 'in:en,tl'],
            'timezone' => ['required', 'string', 'max:50'],
        ]);

        $request->user()->update($validated);

        return back()->with('success', 'Appearance saved.');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password'      => ['required', 'string'],
            'password'              => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return back()->with('success', 'Password updated.');
    }
}
