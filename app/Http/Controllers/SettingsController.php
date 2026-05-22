<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $authUser = $request->user();
        $canManageUsers = in_array($authUser->role, ['superadmin', 'admin']);

        return Inertia::render('Settings/Index', [
            'settings' => [
                'max_active_leads'      => 10,
                'recycle_attempts'      => 3,
                'callback_expiry_hours' => 24,
            ],
            'user' => $authUser->only([
                'id', 'name', 'email', 'phone', 'role',
                'theme', 'language', 'timezone',
            ]),
            'can_manage_users' => $canManageUsers,
            'users' => $canManageUsers
                ? User::orderByRaw("CASE role
                        WHEN 'superadmin' THEN 1
                        WHEN 'admin'      THEN 2
                        WHEN 'supervisor' THEN 3
                        WHEN 'finance'    THEN 4
                        WHEN 'accounting' THEN 5
                        WHEN 'warehouse'  THEN 6
                        ELSE 7 END")
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'phone', 'role', 'is_active', 'last_login_at', 'created_at'])
                : [],
        ]);
    }

    public function storeUser(Request $request)
    {
        $this->authorizeUserManagement($request);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'phone'    => ['nullable', 'string', 'max:20'],
            'role'     => ['required', 'in:superadmin,admin,supervisor,finance,accounting,warehouse,agent'],
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'phone'     => $data['phone'] ?? null,
            'role'      => $data['role'],
            'is_active' => true,
        ]);

        if ($data['role'] === 'agent') {
            $user->agentProfile()->create([
                'max_active_cycles' => 10,
                'is_available'      => true,
            ]);
        }

        return back()->with('success', "User {$user->name} created as {$data['role']}.");
    }

    public function updateUser(Request $request, User $user)
    {
        $this->authorizeUserManagement($request);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email,' . $user->id],
            'phone'    => ['nullable', 'string', 'max:20'],
            'role'     => ['required', 'in:superadmin,admin,supervisor,finance,accounting,warehouse,agent'],
            'password' => ['nullable', Password::min(8)],
        ]);

        $payload = [
            'name'  => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role'  => $data['role'],
        ];

        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);

        return back()->with('success', "User {$user->name} updated.");
    }

    public function toggleUser(User $user, Request $request)
    {
        $this->authorizeUserManagement($request);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => ! $user->is_active]);
        $status = $user->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "User {$user->name} {$status}.");
    }

    public function deleteUser(User $user, Request $request)
    {
        $this->authorizeUserManagement($request);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $name = $user->name;
        $user->agentProfile?->delete();
        $user->delete();

        return back()->with('success', "User {$name} deleted.");
    }

    private function authorizeUserManagement(Request $request): void
    {
        if (! in_array($request->user()?->role, ['superadmin', 'admin'])) {
            abort(403, 'Only admins can manage users.');
        }
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $request->user()->update($validated);

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

        return redirect()->back(303)->with('success', 'Password updated.');
    }

    public function resetUserPassword(Request $request, User $user)
    {
        $this->authorizeUserManagement($request);

        $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Send email notification to user about password reset
        \Illuminate\Support\Facades\Mail::to($user->email)->send(
            new \App\Mail\PasswordResetByAdmin($user, $request->user())
        );

        return back()->with('success', "Password reset for {$user->name}. They have been notified by email.");
    }
}
