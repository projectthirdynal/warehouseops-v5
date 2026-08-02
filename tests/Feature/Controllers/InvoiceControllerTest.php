<?php

namespace Tests\Feature\Controllers;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->withRole('superadmin')->create();
    }

    /** @test */
    public function it_calculates_derived_fields_when_storing_a_line()
    {
        $invoice = Invoice::factory()->create(['status' => 'DRAFT']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('finance.invoices.lines.store', $invoice), [
                'description' => 'Test product line',
                'qty' => 2,
                'unit_price' => 100.00,
                'tax_rate' => 12,
                'discount_pct' => 10,
            ]);

        $response->assertRedirect();

        $line = $invoice->lines()->first();

        // Pre-discount: 2 * 100 = 200
        // Discount: 200 * 0.10 = 20
        // After discount: 200 - 20 = 180
        // Tax: 180 * 0.12 = 21.60
        // Total TTC: 180 + 21.60 = 201.60
        $this->assertEquals(180.00, (float) $line->total_ht);
        $this->assertEquals(20.00, (float) $line->discount_amount);
        $this->assertEquals(21.60, (float) $line->tax_amount);
        $this->assertEquals(201.60, (float) $line->total_ttc);
    }

    /** @test */
    public function it_recalculates_invoice_totals_after_adding_a_line()
    {
        $invoice = Invoice::factory()->create(['status' => 'DRAFT']);

        $this->actingAs($this->admin)
            ->postJson(route('finance.invoices.lines.store', $invoice), [
                'description' => 'Line 1',
                'qty' => 2,
                'unit_price' => 100.00,
                'tax_rate' => 12,
                'discount_pct' => 10,
            ]);

        $invoice->refresh();

        // With corrected recalculate():
        // subtotal = 200 (pre-discount)
        // discount_amount = 20
        // tax_amount = 21.60
        // total_amount = 200 - 20 + 21.60 = 201.60
        $this->assertEquals(200.00, (float) $invoice->subtotal);
        $this->assertEquals(20.00, (float) $invoice->discount_amount);
        $this->assertEquals(21.60, (float) $invoice->tax_amount);
        $this->assertEquals(201.60, (float) $invoice->total_amount);
    }

    /** @test */
    public function it_blocks_cancelling_a_paid_invoice()
    {
        $invoice = Invoice::factory()->paid()->create();

        $response = $this->actingAs($this->admin)
            ->postJson(route('finance.invoices.cancel', $invoice), [
                'reason' => 'Test cancellation',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertEquals('PAID', $invoice->fresh()->status);
    }

    /** @test */
    public function it_blocks_cancelling_a_partial_invoice()
    {
        $invoice = Invoice::factory()->create([
            'status' => 'PARTIAL',
            'amount_paid' => 50.00,
            'amount_due' => 150.00,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('finance.invoices.cancel', $invoice), [
                'reason' => 'Test cancellation',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertEquals('PARTIAL', $invoice->fresh()->status);
    }

    /** @test */
    public function it_sets_updated_by_on_status_transitions()
    {
        $invoice = Invoice::factory()->create(['status' => 'DRAFT']);

        $this->actingAs($this->admin)
            ->postJson(route('finance.invoices.validate', $invoice));

        $invoice->refresh();
        $this->assertEquals($this->admin->id, $invoice->updated_by);
    }
}
