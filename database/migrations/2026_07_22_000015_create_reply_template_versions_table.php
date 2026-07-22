<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reply_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reply_template_id')->constrained('reply_templates')->cascadeOnDelete();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('title');
            $table->text('content');
            $table->json('variables')->nullable();
            $table->string('category')->nullable();
            $table->string('intent')->nullable();
            $table->json('allowed_roles')->nullable();
            $table->string('shortcut')->nullable();
            $table->foreignId('facebook_page_id')->nullable()->constrained('facebook_pages')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('shared_page_ids')->nullable();
            $table->text('change_summary')->nullable();
            $table->timestamps();

            $table->index(['reply_template_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reply_template_versions');
    }
};
