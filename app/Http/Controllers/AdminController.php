<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AgentProfile;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserModuleAccess;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')
            ->get(['id', 'name', 'email', 'phone', 'role', 'is_active', 'last_login_at', 'created_at']);

        $roles = ['superadmin', 'admin', 'supervisor', 'finance', 'accounting', 'warehouse', 'agent'];

        $modules = UserModuleAccess::moduleDefinitions();

        // Per-user module access: user_id => [module_key => bool]
        // Guard: table may not exist yet if migration hasn't run on this environment
        $tableExists = Schema::hasTable('user_module_access');
        $allOverrides = $tableExists && $users->isNotEmpty()
            ? UserModuleAccess::whereIn('user_id', $users->pluck('id'))->get()->groupBy('user_id')
            : collect();

        $userModules = [];
        foreach ($users as $user) {
            $defaults = array_fill_keys(
                UserModuleAccess::defaultsForRole($user->role),
                true
            );
            $overrides = $allOverrides->get($user->id, collect())
                ->pluck('granted', 'module_key')
                ->all();
            $userModules[$user->id] = array_merge($defaults, $overrides);
        }

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
            'modules' => $modules,
            'userModules' => $userModules,
            'stats' => $stats,
            'recentActivity' => $recentActivity,
        ]);
    }

    public function updateUserModules(Request $request, User $user)
    {
        if (! Schema::hasTable('user_module_access')) {
            return redirect()->back(303)->with('error', 'Module access table not ready. Run: php artisan migrate');
        }

        $validKeys = collect(UserModuleAccess::moduleDefinitions())->pluck('key')->all();

        $validated = $request->validate([
            'modules' => 'required|array',
            'modules.*' => 'boolean',
        ]);

        DB::transaction(function () use ($user, $validated, $validKeys) {
            foreach ($validated['modules'] as $moduleKey => $granted) {
                if (! in_array($moduleKey, $validKeys)) {
                    continue;
                }
                UserModuleAccess::updateOrCreate(
                    ['user_id' => $user->id, 'module_key' => $moduleKey],
                    ['granted' => (bool) $granted]
                );
            }
        });

        ActivityLog::log('user.modules_updated', $request->user(), 'User', $user->id, [
            'modules' => $validated['modules'],
        ]);

        return redirect()->back(303)->with('success', 'Module access updated.');
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

        $user->update(['is_active' => ! $user->is_active]);

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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:superadmin,admin,supervisor,finance,accounting,warehouse,agent'],
            'password' => ['required', Password::min(8)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => $validated['role'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
            // email_verified_at intentionally null — verification email sent below
        ]);

        if ($user->role === 'agent') {
            AgentProfile::create(['user_id' => $user->id]);
        }

        // Send email verification notification
        event(new Registered($user));

        ActivityLog::log('user.created', $request->user(), 'User', $user->id, ['role' => $user->role]);

        return redirect()->back(303)->with('success', "User \"{$user->name}\" created. A verification email has been sent.");
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
