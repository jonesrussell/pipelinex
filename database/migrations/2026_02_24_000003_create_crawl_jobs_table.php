<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_jobs', function (Blueprint $table) {
            $table->string('id', 18)->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('api_key_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->text('final_url')->nullable();
            $table->string('status', 20)->default('processing');
            $table->jsonb('options')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->smallInteger('http_status_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_jobs');
    }
};
