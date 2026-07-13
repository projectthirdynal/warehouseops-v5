<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('priority')->default('normal')->after('status');
            $table->boolean('is_flagged')->default(false)->after('priority');
            $table->text('flag_reason')->nullable()->after('is_flagged');
            $table->timestamp('flagged_at')->nullable()->after('flag_reason');

            $table->index(['priority', 'status']);
            $table->index(['is_flagged', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['priority', 'status']);
            $table->dropIndex(['is_flagged', 'status']);
            $table->dropColumn(['priority', 'is_flagged', 'flag_reason', 'flagged_at']);
        });
    }
};
