<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customers table
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('name');
            $table->text('canonical_address')->nullable();
            $table->integer('total_orders')->default(0);
            $table->integer('successful_orders')->default(0);
            $table->integer('returned_orders')->default(0);
            $table->decimal('success_rate', 5, 2)->default(0);
            $table->decimal('total_revenue', 12, 2)->default(0);
            $table->enum('risk_level', ['LOW', 'MEDIUM', 'HIGH', 'BLACKLISTED'])->default('LOW');
            $table->boolean('is_blacklisted')->default(false);
            $table->text('blacklist_reason')->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['risk_level']);
            $table->index(['is_blacklisted']);
        });

        // Waybills table
        Schema::create('waybills', function (Blueprint $table) {
            $table->id();
            $table->string('waybill_number')->unique();
            $table->string('status')->default('PENDING');
            $table->string('receiver_name');
            $table->string('receiver_phone');
            $table->text('receiver_address');
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('barangay')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('item_name')->nullable();
            $table->integer('item_qty')->default(1);
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('courier_provider')->default('MANUAL');
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['receiver_phone']);
            $table->index(['created_at']);
            $table->index(['dispatched_at']);
        });

        // Waybill tracking history
        Schema::create('waybill_tracking_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waybill_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('previous_status')->nullable();
            $table->text('reason')->nullable();
            $table->string('location')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('tracked_at');
            $table->timestamps();

            $table->index(['waybill_id', 'tracked_at']);
        });

        // Uploads table
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('type')->default('waybill');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('success_rows')->default(0);
            $table->integer('error_rows')->default(0);
            $table->string('status')->default('pending');
            $table->json('errors')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['status']);
        });

        // Batch sessions
        Schema::create('batch_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->integer('scanned_count')->default(0);
            $table->integer('valid_count')->default(0);
            $table->integer('invalid_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Batch scan items
        Schema::create('batch_scan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waybill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scanned_value');
            $table->boolean('is_valid')->default(false);
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['batch_session_id']);
        });

        // Courier providers
        Schema::create('courier_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->string('api_endpoint')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_providers');
        Schema::dropIfExists('batch_scan_items');
        Schema::dropIfExists('batch_sessions');
        Schema::dropIfExists('uploads');
        Schema::dropIfExists('waybill_tracking_history');
        Schema::dropIfExists('waybills');
        Schema::dropIfExists('customers');
    }
};
