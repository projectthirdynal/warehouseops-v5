<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Shop\Models\CartTemplate;

class CartTemplateSharingService
{
    private const array ROLES = ['superadmin', 'admin', 'supervisor', 'agent', 'encoder'];

    /**
     * Share a template with optional role-based access control.
     */
    public function share(int $templateId, int $ownerId, bool $shared, ?array $allowedRoles = null): CartTemplate
    {
        $template = CartTemplate::query()
            ->where('id', $templateId)
            ->where('user_id', $ownerId)
            ->firstOrFail();

        $template->update([
            'is_shared' => $shared,
            'allowed_roles' => $shared ? $this->normalizeRoles($allowedRoles) : null,
        ]);

        return $template->fresh();
    }

    /**
     * Clone a template into the current user's account.
     */
    public function clone(int $templateId, int $userId, ?string $name = null): CartTemplate
    {
        $source = CartTemplate::query()
            ->sharedOrOwned($userId)
            ->findOrFail($templateId);

        $clone = CartTemplate::query()->create([
            'user_id' => $userId,
            'name' => $name ?? "{$source->name} (Copy)",
            'items' => $source->items,
            'courier_code' => $source->courier_code,
            'shipping_fee' => $source->shipping_fee,
            'discount_amount' => $source->discount_amount,
            'tax_rate' => $source->tax_rate,
            'remarks' => $source->remarks,
            'is_shared' => false,
            'allowed_roles' => null,
            'cloned_from' => $source->id,
        ]);

        return $clone;
    }

    /**
     * Mark a template as used (updates last_used_at timestamp).
     */
    public function markUsed(int $templateId): void
    {
        CartTemplate::query()
            ->where('id', $templateId)
            ->update(['last_used_at' => now()]);
    }

    /**
     * Get sharing statistics.
     */
    public function stats(): array
    {
        $total = CartTemplate::query()->count();
        $shared = CartTemplate::query()->where('is_shared', true)->count();
        $cloned = CartTemplate::query()->whereNotNull('cloned_from')->count();

        $byRole = [];
        $sharedTemplates = CartTemplate::query()
            ->where('is_shared', true)
            ->whereNotNull('allowed_roles')
            ->get(['allowed_roles']);

        foreach (self::ROLES as $role) {
            $byRole[$role] = $sharedTemplates->filter(
                fn ($t) => is_array($t->allowed_roles) && in_array($role, $t->allowed_roles, true)
            )->count();
        }

        $topOwners = CartTemplate::query()
            ->with('user:id,name')
            ->selectRaw('user_id, count(*) as count')
            ->groupBy('user_id')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'name' => $row->user?->name ?? 'Unknown',
                'count' => $row->count,
            ]);

        $recentClones = CartTemplate::query()
            ->whereNotNull('cloned_from')
            ->with(['user:id,name', 'clonedFrom:id,name,user_id'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'cloned_from' => $t->cloned_from,
                'source_name' => $t->clonedFrom?->name,
                'source_owner' => $t->clonedFrom?->user_id !== $t->user_id,
                'user_name' => $t->user?->name ?? 'Unknown',
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return [
            'total' => $total,
            'shared' => $shared,
            'private' => $total - $shared,
            'cloned' => $cloned,
            'by_role' => $byRole,
            'top_owners' => $topOwners,
            'recent_clones' => $recentClones,
        ];
    }

    /**
     * Get templates accessible to a user with role-based filtering.
     */
    public function listForUser(int $userId, ?string $role = null): Collection
    {
        return CartTemplate::query()
            ->accessibleTo($userId, $role)
            ->with(['user:id,name', 'clonedFrom:id,name'])
            ->latest()
            ->limit(100)
            ->get();
    }

    /**
     * Normalize and validate role list.
     */
    private function normalizeRoles(?array $roles): ?array
    {
        if ($roles === null || $roles === []) {
            return null; // null = accessible to all roles
        }

        return array_values(array_intersect($roles, self::ROLES));
    }

    /**
     * Available roles for sharing.
     */
    public function availableRoles(): array
    {
        return self::ROLES;
    }
}
