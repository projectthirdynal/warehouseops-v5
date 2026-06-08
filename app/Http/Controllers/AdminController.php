<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')
            ->get(['id', 'name', 'email', 'phone', 'role', 'is_active', 'last_login_at', 'created_at']);

        $permissions = Permission::orderBy('section')->orderBy('key')->get();
        $rolePermissions = RolePermission::pluck('permission_id', 'role')->groupBy('role');

        $roles = ['superadmin', 'admin', 'supervisor', 'finance', 'accounting', 'warehouse', 'agent'];

        $stats = [
            'total_users' => $users->count(),
            'active_users' => $users->where('is_active', true)->count(),
            'inactive_users' => $users->where('is_active', false)->count(),
            'role_distribution' => $users->groupBy('role')->map->count(),
        ];

        $recentActivity = ActivityLog::with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return Inertia::render('Admin/Dashboard', [
            'users' => $users,
            'roles' => $roles,
            'permissions' => $permissions->groupBy('section'),
            'rolePermissions' => $rolePermissions->map(fn($items) => $items->values()->all())->toArray(),
            'stats' => $stats,
            'recentActivity' => $recentActivity,
        ]);
    }

    public function updateRolePermissions(Request $request)
    {
        $validated = $request->validate([
            'role' => 'required|string|in:superadmin,admin,supervisor,finance,accounting,warehouse,agent',
            'permissions' => 'required|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);

        DB::transaction(function () use ($validated) {
            RolePermission::where('role', $validated['role'])->delete();
            foreach ($validated['permissions'] as $permissionId) {
                RolePermission::create([
                    'role' => $validated['role'],
                    'permission_id' => $permissionId,
                ]);
            }
        });

        ActivityLog::log('permissions.updated', $request->user(), 'RolePermission', null, [
            'role' => $validated['role'],
            'permission_ids' => $validated['permissions'],
        ]);

        return redirect()->back(303)->with('success', 'Role permissions updated.');
    }

    public function toggleUser(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return redirect()->back(303)->with('error', 'You cannot deactivate yourself.');
        }

        if ($user->role === 'superadmin' && $request->user()->role !== 'superadmin') {
            return redirect()->back(303)->with('error', 'Only a superadmin can deactivate another superadmin.');
        }

        $user->update(['is_active' => !$user->is_active]);

        ActivityLog::log(
            $user->is_active ? 'user.activated' : 'user.deactivated',
            $request->user(),
            'User',
            $user->id,
            ['is_active' => $user->is_active]
        );

        return redirect()->back(303)->with('success', 'User status updated.');
    }

    public function updateUserRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => 'required|string|in:superadmin,admin,supervisor,finance,accounting,warehouse,agent',
        ]);

        $oldRole = $user->role;
        $user->update(['role' => $validated['role']]);

        ActivityLog::log('user.role_changed', $request->user(), 'User', $user->id, [
            'old_role' => $oldRole,
            'new_role' => $validated['role'],
        ]);

        return redirect()->back(303)->with('success', 'User role updated.');
    }

    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users'],
            'phone'    => ['nullable', 'string', 'max:20'],
            'role'     => ['required', 'in:superadmin,admin,supervisor,finance,accounting,warehouse,agent'],
            'password' => ['required', Password::min(8)],
        ]);

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'phone'     => $validated['phone'] ?? null,
            'role'      => $validated['role'],
            'password'  => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        if ($user->role === 'agent') {
            \App\Models\AgentProfile::create(['user_id' => $user->id]);
        }

        ActivityLog::log('user.created', $request->user(), 'User', $user->id, ['role' => $user->role]);

        return redirect()->back(303)->with('success', "User \"{$user->name}\" created.");
    }

    public function deleteUser(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return redirect()->back(303)->with('error', 'You cannot delete your own account.');
        }

        if ($user->role === 'superadmin' && $request->user()->role !== 'superadmin') {
            return redirect()->back(303)->with('error', 'Only a superadmin can delete another superadmin.');
        }

        $name = $user->name;
        $user->delete();

        ActivityLog::log('user.deleted', $request->user(), 'User', $user->id, ['deleted_user_name' => $name]);

        return redirect()->back(303)->with('success', "User \"{$name}\" deleted.");
    }
}
