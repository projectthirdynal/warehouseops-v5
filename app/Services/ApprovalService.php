<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    public function getApprovers(string $type): Collection
    {
        $rolesKey = "{$type}_approver_roles";
        $userIdKey = "{$type}_approver_user_id";

        $settings = DB::table('approval_settings')
            ->whereIn('key', [$rolesKey, $userIdKey])
            ->pluck('value', 'key');

        $roles = array_filter(
            array_map('trim', explode(',', $settings[$rolesKey] ?? '')),
        );

        $approvers = User::where('is_active', true)
            ->when(count($roles) > 0, fn ($q) => $q->whereIn('role', $roles))
            ->get();

        $designatedId = $settings[$userIdKey] ?? null;
        if ($designatedId && ! $approvers->contains('id', (int) $designatedId)) {
            $designated = User::find((int) $designatedId);
            if ($designated) {
                $approvers->push($designated);
            }
        }

        return $approvers;
    }

    public function getSetting(string $key): ?string
    {
        return DB::table('approval_settings')->where('key', $key)->value('value');
    }

    public function setSetting(string $key, ?string $value): void
    {
        DB::table('approval_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()],
        );
    }

    public function getAllSettings(): array
    {
        return DB::table('approval_settings')->pluck('value', 'key')->toArray();
    }
}
