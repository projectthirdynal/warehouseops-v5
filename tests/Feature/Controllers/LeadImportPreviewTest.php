<?php

namespace Tests\Feature\Controllers;

use App\Domain\Lead\Enums\PoolStatus;
use App\Domain\Lead\Models\Lead;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LeadImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = User::factory()->create(['role' => 'supervisor']);
        $this->actingAs($this->supervisor);
        $this->withoutMiddleware([VerifyCsrfToken::class]);
    }

    public function test_preview_detects_new_rows(): void
    {
        $csv = "name,phone,city\nJuan Dela Cruz,09171234567,Davao City\nMaria Santos,09281234567,Cebu City\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $response = $this->postJson('/lead-pool/import/preview', ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('summary.total', 2);
        $response->assertJsonPath('summary.new', 2);
        $response->assertJsonPath('summary.duplicate_db', 0);
        $response->assertJsonPath('summary.duplicate_file', 0);
        $response->assertJsonPath('summary.errors', 0);
    }

    public function test_preview_detects_db_duplicates(): void
    {
        Lead::factory()->create([
            'phone' => '+639171234567',
            'pool_status' => PoolStatus::AVAILABLE,
        ]);

        $csv = "name,phone,city\nJuan Dela Cruz,09171234567,Davao City\nMaria Santos,09281234567,Cebu City\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $response = $this->postJson('/lead-pool/import/preview', ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('summary.total', 2);
        $response->assertJsonPath('summary.new', 1);
        $response->assertJsonPath('summary.duplicate_db', 1);
    }

    public function test_preview_detects_in_file_duplicates(): void
    {
        $csv = "name,phone,city\nJuan Dela Cruz,09171234567,Davao City\nJuan Duplicate,09171234567,Davao City\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $response = $this->postJson('/lead-pool/import/preview', ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('summary.total', 2);
        $response->assertJsonPath('summary.new', 1);
        $response->assertJsonPath('summary.duplicate_file', 1);
    }

    public function test_preview_detects_errors(): void
    {
        $csv = "name,phone,city\n,09171234567,Davao City\nMaria Santos,invalid,Cebu City\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $response = $this->postJson('/lead-pool/import/preview', ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('summary.total', 2);
        $response->assertJsonPath('summary.errors', 2);
        $response->assertJsonPath('summary.new', 0);
    }

    public function test_preview_does_not_write_to_db(): void
    {
        // Count leads before preview
        $countBefore = Lead::count();

        $csv = "name,phone,city\nJuan Dela Cruz,09171234567,Davao City\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $this->postJson('/lead-pool/import/preview', ['file' => $file]);

        // No new leads should have been created
        $this->assertEquals($countBefore, Lead::count());
    }

    public function test_preview_requires_file(): void
    {
        $response = $this->postJson('/lead-pool/import/preview', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_preview_rejects_non_supervisor(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent);

        $csv = "name,phone\nTest,09171234567\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        // Middleware aborts with 403, but some environments may redirect
        $response = $this->post('/lead-pool/import/preview', ['file' => $file]);

        $this->assertContains($response->status(), [403, 302]);
    }
}
