<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the orphaned google_connections table (OAuth approach was replaced
     * by public-link CSV export) and the unused sheet_tab_name column.
     */
    public function up(): void
    {
        Schema::dropIfExists('google_connections');

        if (Schema::hasTable('google_sheet_configs') && Schema::hasColumn('google_sheet_configs', 'sheet_tab_name')) {
            Schema::table('google_sheet_configs', function (Blueprint $table) {
                $table->dropColumn('sheet_tab_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('google_sheet_configs') && ! Schema::hasColumn('google_sheet_configs', 'sheet_tab_name')) {
            Schema::table('google_sheet_configs', function (Blueprint $table) {
                $table->string('sheet_tab_name')->nullable()->after('sheet_url');
            });
        }

        if (! Schema::hasTable('google_connections')) {
            Schema::create('google_connections', function (Blueprint $table) {
                $table->id();
                $table->string('google_user_id')->nullable()->index();
                $table->string('email')->nullable();
                $table->text('access_token');
                $table->text('refresh_token');
                $table->timestamp('expires_at')->nullable();
                $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('connected_at')->useCurrent();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }
};
