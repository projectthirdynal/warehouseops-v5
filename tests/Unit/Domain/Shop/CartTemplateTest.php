<?php

namespace Tests\Unit\Domain\Shop;

use App\Domain\Shop\Models\CartTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_roles_constant_matches_supported_roles(): void
    {
        $this->assertSame(['superadmin', 'admin', 'supervisor', 'agent', 'encoder'], CartTemplate::ALLOWED_ROLES);
    }

    public function test_is_owned_by_detects_owner(): void
    {
        $owner = User::factory()->create();
        $template = $this->makeTemplate($owner, []);

        $this->assertTrue($template->isOwnedBy($owner->id));
        $this->assertFalse($template->isOwnedBy($owner->id + 999));
    }

    public function test_private_template_is_only_accessible_by_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $template = $this->makeTemplate($owner, ['is_shared' => false]);

        $this->assertTrue($template->isAccessibleBy($owner->id, 'agent'));
        $this->assertFalse($template->isAccessibleBy($other->id, 'admin'));
    }

    public function test_shared_template_without_role_restriction_is_accessible_to_all(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $template = $this->makeTemplate($owner, ['is_shared' => true, 'allowed_roles' => null]);

        $this->assertTrue($template->isAccessibleBy($other->id, 'agent'));
        $this->assertTrue($template->isAccessibleBy($other->id));
        $this->assertTrue($template->isAccessibleBy($other->id, null));
    }

    public function test_shared_template_with_role_restriction_requires_matching_role(): void
    {
        $owner = User::factory()->create();
        $template = $this->makeTemplate($owner, [
            'is_shared' => true,
            'allowed_roles' => ['supervisor', 'admin'],
        ]);

        $this->assertTrue($template->isAccessibleBy($owner->id + 1, 'supervisor'));
        $this->assertFalse($template->isAccessibleBy($owner->id + 1, 'agent'));
        $this->assertFalse($template->isAccessibleBy($owner->id + 1, null));
    }

    public function test_accessible_to_scope_filters_private_templates(): void
    {
        $owner = User::factory()->create();
        $private = $this->makeTemplate($owner, ['is_shared' => false]);
        $shared = $this->makeTemplate($owner, ['is_shared' => true]);
        $restricted = $this->makeTemplate($owner, [
            'is_shared' => true,
            'allowed_roles' => ['supervisor'],
        ]);

        $visible = CartTemplate::query()->accessibleTo($owner->id + 1, 'agent')->get();

        $this->assertTrue($visible->contains($shared));
        $this->assertFalse($visible->contains($private));
        $this->assertFalse($visible->contains($restricted));

        $asSupervisor = CartTemplate::query()->accessibleTo($owner->id + 1, 'supervisor')->get();

        $this->assertTrue($asSupervisor->contains($restricted));

        $asOwner = CartTemplate::query()->accessibleTo($owner->id, 'agent')->get();

        $this->assertCount(3, $asOwner);
    }

    public function test_shared_or_owned_scope_returns_owned_and_shared(): void
    {
        $owner = User::factory()->create();
        $mine = $this->makeTemplate($owner, ['is_shared' => false]);
        $shared = $this->makeTemplate($owner, ['is_shared' => true]);
        $foreign = User::factory()->create();
        $theirs = $this->makeTemplate($foreign, ['is_shared' => false]);

        $result = CartTemplate::query()->sharedOrOwned($owner->id)->get();

        $this->assertTrue($result->contains($mine));
        $this->assertTrue($result->contains($shared));
        $this->assertFalse($result->contains($theirs));
    }

    public function test_items_count_counts_items_safely(): void
    {
        $owner = User::factory()->create();
        $template = $this->makeTemplate($owner, [
            'items' => [
                ['product_id' => 1, 'quantity' => 2, 'unit_price' => 100],
                ['product_id' => 2, 'quantity' => 1, 'unit_price' => 50],
            ],
        ]);

        $this->assertSame(2, $template->itemsCount());
    }

    public function test_record_usage_updates_last_used_at(): void
    {
        $owner = User::factory()->create();
        $template = $this->makeTemplate($owner);

        $this->assertNull($template->last_used_at);

        $template->recordUsage();
        $template->refresh();

        $this->assertNotNull($template->last_used_at);
        $this->assertEquals(now()->format('Y-m-d H:i'), $template->last_used_at->format('Y-m-d H:i'));
    }

    public function test_clone_for_copies_configuration_and_resets_sharing(): void
    {
        $owner = User::factory()->create();
        $source = $this->makeTemplate($owner, [
            'items' => [['product_id' => 1, 'quantity' => 3, 'unit_price' => 199.99]],
            'courier_code' => 'JNT',
            'shipping_fee' => 150,
            'discount_amount' => 50,
            'tax_rate' => 12,
            'remarks' => 'Priority handling',
            'is_shared' => true,
            'allowed_roles' => ['admin'],
            'last_used_at' => now(),
        ]);

        $other = User::factory()->create();
        $clone = $source->cloneFor($other->id);

        $this->assertNotSame($source->id, $clone->id);
        $this->assertSame($other->id, $clone->user_id);
        $this->assertSame('Test Template (Copy)', $clone->name);
        $this->assertSame($source->items, $clone->items);
        $this->assertSame('JNT', $clone->courier_code);
        $this->assertSame('150.00', (string) $clone->shipping_fee);
        $this->assertSame('50.00', (string) $clone->discount_amount);
        $this->assertSame('12.00', (string) $clone->tax_rate);
        $this->assertSame('Priority handling', $clone->remarks);
        $this->assertFalse($clone->is_shared);
        $this->assertNull($clone->allowed_roles);
        $this->assertSame($source->id, $clone->cloned_from);
        $this->assertNull($clone->last_used_at);
    }

    public function test_clone_for_accepts_custom_name(): void
    {
        $owner = User::factory()->create();
        $source = $this->makeTemplate($owner);

        $clone = $source->cloneFor($owner->id, 'Custom Name');

        $this->assertSame('Custom Name', $clone->name);
    }

    public function test_cloned_from_relation_resolves_source_template(): void
    {
        $owner = User::factory()->create();
        $source = $this->makeTemplate($owner);
        $clone = $source->cloneFor($owner->id);

        $this->assertTrue($clone->clonedFrom->is($source));
    }

    private function makeTemplate(User $owner, array $attributes = []): CartTemplate
    {
        return CartTemplate::query()->create(array_merge([
            'user_id' => $owner->id,
            'name' => 'Test Template',
            'items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => 100]],
        ], $attributes));
    }
}
