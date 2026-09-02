<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waybills', function (Blueprint $table) {
            // J&T specific fields
            $table->string('creator_code')->nullable()->after('waybill_number');
            $table->boolean('sign_for_pictures')->default(false)->after('status');
            $table->string('payment_method')->nullable()->after('amount');
            $table->decimal('shipping_cost', 10, 2)->default(0)->after('payment_method');
            $table->decimal('cod_amount', 10, 2)->default(0)->after('shipping_cost');
            $table->decimal('settlement_weight', 8, 2)->nullable()->after('cod_amount');
            $table->decimal('item_value', 10, 2)->nullable()->after('item_qty');
            $table->decimal('valuation_fee', 10, 2)->nullable()->after('item_value');
            $table->string('express_type')->nullable()->after('courier_provider');
            $table->text('rts_reason')->nullable()->after('express_type');
            $table->text('remarks')->nullable()->after('rts_reason');

            // Sender info
            $table->string('sender_name')->nullable()->after('remarks');
            $table->string('sender_phone')->nullable()->after('sender_name');
            $table->string('sender_province')->nullable()->after('sender_phone');
            $table->string('sender_city')->nullable()->after('sender_province');

            // Timestamps from courier
            $table->timestamp('submitted_at')->nullable()->after('sender_city');
            $table->timestamp('signed_at')->nullable()->after('submitted_at');

            // Upload tracking
            $table->foreignId('upload_id')->nullable()->after('uploaded_by')->constrained('uploads')->nullOnDelete();

            // Additional indexes
            $table->index(['creator_code']);
            $table->index(['submitted_at']);
            $table->index(['courier_provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('waybills', function (Blueprint $table) {
            $table->dropIndex(['creator_code']);
            $table->dropIndex(['submitted_at']);
            $table->dropIndex(['courier_provider', 'status']);

            $table->dropForeign(['upload_id']);

            $table->dropColumn([
                'creator_code',
                'sign_for_pictures',
                'payment_method',
                'shipping_cost',
                'cod_amount',
                'settlement_weight',
                'item_value',
                'valuation_fee',
                'express_type',
                'rts_reason',
                'remarks',
                'sender_name',
                'sender_phone',
                'sender_province',
                'sender_city',
                'submitted_at',
                'signed_at',
                'upload_id',
            ]);
        });
    }
};
