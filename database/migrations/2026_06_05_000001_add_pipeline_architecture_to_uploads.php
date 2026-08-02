<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('file_path')->nullable()->after('filename');
            $table->integer('total_chunks')->default(0)->after('total_rows');
            $table->integer('processed_chunks')->default(0)->after('total_chunks');
            $table->json('metadata')->nullable()->after('errors');
            $table->boolean('auto_import')->default(false)->after('import_type');

            $table->index(['status', 'created_at'], 'idx_status_created');
        });

        Schema::create('import_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('uploads')->onDelete('cascade');
            $table->integer('chunk_number')->unsigned();
            $table->string('status', 50)->default('pending');
            $table->integer('rows_count')->default(0);
            $table->integer('inserted_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['upload_id', 'chunk_number']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_chunks');

        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex('idx_status_created');
            $table->dropColumn([
                'uuid',
                'file_path',
                'total_chunks',
                'processed_chunks',
                'metadata',
                'auto_import',
            ]);
        });
    }
};
