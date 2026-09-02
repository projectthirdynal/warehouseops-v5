<?php

declare(strict_types=1);

namespace Modules\Shop\Services;

use App\Models\Customer;
use App\Models\CustomerNote;

class CustomerNoteService
{
    /**
     * Add a note to a customer.
     *
     * @param  array<string, mixed>  $data
     */
    public function addNote(Customer $customer, array $data): CustomerNote
    {
        $tags = $this->normalizeTags($data['tags'] ?? []);

        $note = $customer->notes()->create([
            'user_id' => auth()->id(),
            'note_type' => $data['note_type'] ?? 'agent_note',
            'body' => $data['body'],
            'tags' => $tags,
            'metadata' => $data['metadata'] ?? null,
            'pinned_until' => $data['pinned_until'] ?? null,
        ]);

        $this->syncProfileTags($customer);

        return $note;
    }

    /**
     * Update the customer's profile-level tags.
     *
     * @param  array<int, string>  $tags
     */
    public function setTags(Customer $customer, array $tags): Customer
    {
        $customer->forceFill([
            'tags' => $this->normalizeTags($tags),
        ])->save();

        return $customer;
    }

    /**
     * Rebuild profile tags from the most recent note tags.
     */
    public function syncProfileTags(Customer $customer): Customer
    {
        $noteTags = $customer->notes()
            ->whereNotNull('tags')
            ->latest('created_at')
            ->limit(50)
            ->get(['tags'])
            ->flatMap(fn (CustomerNote $note) => $note->tags ?? [])
            ->unique()
            ->values()
            ->all();

        $profileTags = $customer->tags ?? [];
        $merged = collect(array_merge($profileTags, $noteTags))
            ->map(fn (string $tag) => $this->normalizeTag($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $customer->forceFill(['tags' => $merged])->save();

        return $customer;
    }

    /**
     * @param  mixed  $tags
     * @return array<int, string>
     */
    private function normalizeTags($tags): array
    {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        return collect($tags ?? [])
            ->map(fn ($tag) => $this->normalizeTag($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeTag(mixed $tag): ?string
    {
        if (! is_string($tag) && ! is_numeric($tag)) {
            return null;
        }

        $normalized = trim((string) $tag);

        return $normalized !== '' ? mb_strtolower($normalized) : null;
    }
}
