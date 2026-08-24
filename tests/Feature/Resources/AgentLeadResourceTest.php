<?php

namespace Tests\Feature\Resources;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Http\Resources\AgentLeadResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLeadResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_is_included_in_output(): void
    {
        $lead = Lead::factory()->create([
            'phone' => '09171234567',
            'name' => 'Test Customer',
        ]);

        $resource = new AgentLeadResource($lead);
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('phone', $array);
        $this->assertEquals('09171234567', $array['phone']);
    }

    public function test_includes_safe_lead_data(): void
    {
        $lead = Lead::factory()->create([
            'name' => 'John Doe',
            'city' => 'Manila',
            'product_name' => 'Widget',
            'amount' => 999.00,
        ]);

        $resource = new AgentLeadResource($lead);
        $array = $resource->toArray(request());

        $this->assertEquals('John Doe', $array['name']);
        $this->assertEquals('Manila', $array['city']);
        $this->assertEquals('Widget', $array['product_name']);
        $this->assertEquals(999.00, $array['amount']);
    }

    public function test_includes_call_history(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::factory()->create([
            'assigned_to' => $agent->id,
            'pool_status' => PoolStatus::ASSIGNED,
        ]);
        $lead->cycles()->create([
            'cycle_number' => 1,
            'assigned_agent_id' => $agent->id,
            'status' => 'ACTIVE',
            'opened_at' => now(),
            'call_count' => 3,
        ]);

        $resource = new AgentLeadResource($lead->load('cycles'));
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('cycles', $array);
        $this->assertEquals(3, $array['cycles'][0]['call_count']);
    }

    public function test_address_is_included_in_output(): void
    {
        $lead = Lead::factory()->create([
            'address' => '123 Secret Street',
            'street' => 'Secret Street',
            'city' => 'Manila',
            'state' => 'Metro Manila',
            'barangay' => 'Barangay 123',
        ]);

        $resource = new AgentLeadResource($lead);
        $array = $resource->toArray(request());

        $this->assertArrayHasKey('address', $array);
        $this->assertArrayHasKey('street', $array);
        $this->assertArrayHasKey('address_details', $array);
        $this->assertEquals('Metro Manila', $array['address_details']['province']);
        $this->assertEquals('Manila', $array['address_details']['city']);
        $this->assertEquals('Barangay 123', $array['address_details']['barangay']);
        $this->assertEquals('123 Secret Street', $array['address_details']['address']);
    }
}
