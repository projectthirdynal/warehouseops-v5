<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AgentController extends Controller
{
    public function index(Request $request)
    {
        $agents = User::where('role', 'agent')
            ->with('agentProfile')
            ->get()
            ->map(function ($agent) {
                // Calculate today's stats for each agent
                $agent->stats = [
                    'leads_today' => Lead::where('assigned_to', $agent->id)
                        ->whereDate('created_at', today())
                        ->count(),
                    'sales_today' => Lead::where('assigned_to', $agent->id)
                        ->where('status', 'SALE')
                        ->whereDate('updated_at', today())
                        ->count(),
                    'conversion_rate' => 0,
                    'active_leads' => Lead::where('assigned_to', $agent->id)
                        ->whereNotIn('status', ['SALE', 'DELIVERED', 'RETURNED', 'CANCELLED', 'ARCHIVED'])
                        ->count(),
                ];

                // Calculate conversion rate
                if ($agent->stats['leads_today'] > 0) {
                    $agent->stats['conversion_rate'] = round(
                        ($agent->stats['sales_today'] / $agent->stats['leads_today']) * 100
                    );
                }

                return $agent;
            });

        $stats = [
            'total' => $agents->count(),
            'active' => $agents->where('is_active', true)->count(),
            'inactive' => $agents->where('is_active', false)->count(),
            'avg_performance' => $agents->avg(fn($a) => $a->agentProfile?->performance_score ?? 50),
        ];

        return Inertia::render('Agents/Index', [
            'agents' => $agents,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'phone'    => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
            'phone'     => $validated['phone'] ?? null,
            'role'      => 'agent',
            'is_active' => true,
        ]);

        $user->agentProfile()->create([
            'max_active_cycles' => 10,
            'is_available'      => true,
        ]);

        return back()->with('success', "Agent account created for {$user->name}.");
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email,' . $user->id],
            'phone'    => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', Password::min(8)],
        ]);

        $data = [
            'name'  => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
        ];

        if (!empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        return back()->with('success', 'Agent updated.');
    }

    public function toggleActive(User $user)
    {
        $user->update(['is_active' => !$user->is_active]);
        $status = $user->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Agent {$user->name} has been {$status}.");
    }

    public function updateProfile(Request $request, User $user)
    {
        $validated = $request->validate([
            'product_skills'    => ['nullable', 'array'],
            'product_skills.*'  => ['string', 'max:100'],
            'regions'           => ['nullable', 'array'],
            'regions.*'         => ['string', 'max:100'],
            'max_active_cycles' => ['nullable', 'integer', 'min:1', 'max:50'],
            'is_available'      => ['nullable', 'boolean'],
        ]);

        $profile = $user->agentProfile;

        if (!$profile) {
            $user->agentProfile()->create([
                'product_skills'    => $validated['product_skills'] ?? [],
                'regions'           => $validated['regions'] ?? [],
                'max_active_cycles' => $validated['max_active_cycles'] ?? 10,
                'is_available'      => $validated['is_available'] ?? true,
            ]);
        } else {
            $profile->update(array_filter([
                'product_skills'    => $validated['product_skills'] ?? $profile->product_skills,
                'regions'           => $validated['regions'] ?? $profile->regions,
                'max_active_cycles' => $validated['max_active_cycles'] ?? $profile->max_active_cycles,
                'is_available'      => $validated['is_available'] ?? $profile->is_available,
            ], fn ($v) => $v !== null));
        }

        return back()->with('success', "Profile updated for {$user->name}.");
    }

    public function monitoring()
    {
        $metrics = [
            'leads' => [
                'total' => Lead::count(),
                'new_today' => Lead::whereDate('created_at', today())->count(),
                'converted' => Lead::where('status', 'SALE')->count(),
                'conversion_rate' => Lead::count() > 0
                    ? round((Lead::where('status', 'SALE')->count() / Lead::count()) * 100, 1)
                    : 0,
                'trend' => 12,
            ],
            'waybills' => [
                'total' => \App\Models\Waybill::count(),
                'dispatched_today' => \App\Models\Waybill::whereDate('dispatched_at', today())->count(),
                'delivered_today' => \App\Models\Waybill::whereDate('delivered_at', today())->count(),
                'returned_today' => \App\Models\Waybill::whereDate('returned_at', today())->count(),
                'delivery_rate' => 85,
            ],
            'agents' => [
                'total' => User::where('role', 'agent')->count(),
                'online' => User::where('role', 'agent')
                    ->where('is_active', true)
                    ->whereNotNull('last_login_at')
                    ->where('last_login_at', '>=', now()->subHours(1))
                    ->count(),
                'avg_performance' => 72,
                'top_performer' => User::where('role', 'agent')->first()?->name ?? 'N/A',
            ],
            'revenue' => [
                'today' => Lead::where('status', 'SALE')
                    ->whereDate('updated_at', today())
                    ->sum('amount'),
                'this_week' => Lead::where('status', 'SALE')
                    ->whereBetween('updated_at', [now()->startOfWeek(), now()])
                    ->sum('amount'),
                'this_month' => Lead::where('status', 'SALE')
                    ->whereMonth('updated_at', now()->month)
                    ->sum('amount'),
                'trend' => 8,
            ],
        ];

        return Inertia::render('Monitoring/Index', [
            'metrics' => $metrics,
            'hourly_data' => [],
            'agent_performance' => [],
        ]);
    }
}
