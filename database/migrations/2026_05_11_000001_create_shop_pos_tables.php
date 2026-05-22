<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('facebook_user_id');
            $table->string('facebook_user_name')->nullable();
            $table->string('email')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status')->default('connected');
            $table->timestamp('connected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'facebook_user_id']);
            $table->index(['facebook_user_id']);
            $table->index(['status']);
        });

        Schema::create('facebook_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('page_id')->unique();
            $table->string('page_name');
            $table->string('category')->nullable();
            $table->string('business_id')->nullable();
            $table->text('page_access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('connected_status')->default('disconnected');
            $table->string('webhook_status')->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['connected_status']);
            $table->index(['webhook_status']);
            $table->index(['last_sync_at']);
        });

        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('facebook_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('facebook');
            $table->string('provider_user_id');
            $table->string('display_name')->nullable();
            $table->string('profile_pic_url')->nullable();
            $table->string('phone_detected')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id', 'facebook_page_id'], 'customer_identities_provider_user_page_unique');
            $table->index(['customer_id']);
            $table->index(['phone_detected']);
            $table->index(['last_seen_at']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel')->default('messenger');
            $table->string('status')->default('open');
            $table->string('thread_key')->nullable()->unique();
            $table->text('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'last_message_at']);
            $table->index(['facebook_page_id', 'status']);
            $table->index(['assigned_agent_id', 'status']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facebook_page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_message_id')->nullable()->unique();
            $table->string('direction')->default('inbound');
            $table->string('message_type')->default('text');
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->json('phone_candidates')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'sent_at']);
            $table->index(['facebook_page_id', 'sent_at']);
            $table->index(['direction']);
        });

        Schema::create('facebook_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_id')->nullable()->unique();
            $table->string('object')->nullable();
            $table->string('event_type')->nullable();
            $table->string('sender_psid')->nullable();
            $table->string('recipient_id')->nullable();
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'created_at']);
            $table->index(['sender_psid']);
            $table->index(['processed_at']);
        });

        Schema::create('address_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('country')->default('PH');
            $table->string('region')->nullable();
            $table->string('province');
            $table->string('city_municipality');
            $table->string('barangay')->nullable();
            $table->string('island_group')->nullable();
            $table->string('courier_zone')->nullable();
            $table->string('postal_code')->nullable();
            $table->json('aliases')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['country', 'province', 'city_municipality', 'barangay'], 'address_mapping_unique_location');
            $table->index(['province', 'city_municipality']);
            $table->index(['region']);
            $table->index(['courier_zone']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('normalized_phone')->nullable()->after('phone')->index();
            $table->string('facebook_name')->nullable()->after('name');
            $table->string('landmark')->nullable()->after('canonical_address');
            $table->string('barangay')->nullable()->after('landmark');
            $table->string('city_municipality')->nullable()->after('barangay');
            $table->string('province')->nullable()->after('city_municipality');
            $table->string('region')->nullable()->after('province');
            $table->foreignId('last_page_ordered_from')->nullable()->after('region')->constrained('facebook_pages')->nullOnDelete();
            $table->timestamp('last_order_date')->nullable()->after('last_page_ordered_from');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
            $table->foreignId('facebook_page_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
            $table->foreignId('encoder_id')->nullable()->after('assigned_agent_id')->constrained('users')->nullOnDelete();
            $table->foreignId('address_mapping_id')->nullable()->after('postal_code')->constrained('address_mappings')->nullOnDelete();
            $table->string('source_channel')->default('manual')->after('address_mapping_id');
            $table->decimal('address_confidence', 5, 2)->nullable()->after('source_channel');
            $table->string('export_status')->default('pending')->after('address_confidence');
            $table->timestamp('encoded_at')->nullable()->after('returned_at');

            $table->index(['facebook_page_id', 'created_at']);
            $table->index(['conversation_id']);
            $table->index(['encoder_id', 'status']);
            $table->index(['export_status']);
        });

        Schema::create('shop_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('sku')->nullable();
            $table->string('product_name');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['product_id']);
        });

        Schema::create('order_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('note');
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['type']);
        });

        Schema::create('courier_export_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number')->unique();
            $table->string('courier_code');
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('file_path')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['courier_code', 'status']);
            $table->index(['exported_at']);
        });

        Schema::create('courier_export_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_export_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('status')->default('pending');
            $table->string('receiver_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->text('complete_address')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('barangay')->nullable();
            $table->string('landmark')->nullable();
            $table->string('product_name')->nullable();
            $table->decimal('cod_amount', 10, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->text('remarks')->nullable();
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();

            $table->index(['courier_export_batch_id', 'row_number']);
            $table->index(['order_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_export_rows');
        Schema::dropIfExists('courier_export_batches');
        Schema::dropIfExists('order_remarks');
        Schema::dropIfExists('shop_order_items');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropForeign(['facebook_page_id']);
            $table->dropForeign(['encoder_id']);
            $table->dropForeign(['address_mapping_id']);
            $table->dropIndex(['facebook_page_id', 'created_at']);
            $table->dropIndex(['conversation_id']);
            $table->dropIndex(['encoder_id', 'status']);
            $table->dropIndex(['export_status']);
            $table->dropColumn([
                'conversation_id',
                'facebook_page_id',
                'encoder_id',
                'address_mapping_id',
                'source_channel',
                'address_confidence',
                'export_status',
                'encoded_at',
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['last_page_ordered_from']);
            $table->dropIndex(['normalized_phone']);
            $table->dropColumn([
                'normalized_phone',
                'facebook_name',
                'landmark',
                'barangay',
                'city_municipality',
                'province',
                'region',
                'last_page_ordered_from',
                'last_order_date',
            ]);
        });

        Schema::dropIfExists('address_mappings');
        Schema::dropIfExists('facebook_webhook_events');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('customer_identities');
        Schema::dropIfExists('facebook_pages');
        Schema::dropIfExists('facebook_accounts');
    }
};
