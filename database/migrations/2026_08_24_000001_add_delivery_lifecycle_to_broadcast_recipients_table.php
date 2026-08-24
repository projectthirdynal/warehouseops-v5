<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcast_recipients', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
            $table->timestamp('replied_at')->nullable()->after('read_at');
            $table->timestamp('failed_at')->nullable()->after('replied_at');
            $table->unsignedInteger('retry_count')->default(0)->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('broadcast_recipients', function (Blueprint $table) {
            $table->dropColumn(['retry_count', 'failed_at', 'replied_at', 'read_at', 'delivered_at']);
        });
    }
};
