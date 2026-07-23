<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('preferred_contact_method')->nullable()->after('payment_method');
            $table->string('preferred_contact_time')->nullable()->after('preferred_contact_method');
            $table->boolean('marketing_opt_out')->default(false)->after('preferred_contact_time');
            $table->string('language_preference')->nullable()->after('marketing_opt_out');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'preferred_contact_method',
                'preferred_contact_time',
                'marketing_opt_out',
                'language_preference',
            ]);
        });
    }
};
