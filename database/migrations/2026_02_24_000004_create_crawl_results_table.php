<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('crawl_job_id', 18)->unique();
            $table->foreign('crawl_job_id')->references('id')->on('crawl_jobs')->cascadeOnDelete();
            $table->text('title')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_date')->nullable();
            $table->text('body');
            $table->integer('word_count')->default(0);
            $table->smallInteger('quality_score')->default(0);
            $table->jsonb('topics')->default('[]');
            $table->jsonb('og')->default('{}');
            $table->jsonb('links')->default('[]');
            $table->jsonb('images')->default('[]');
            $table->text('raw_html')->nullable();
            $table->string('content_type', 50)->default('text/html');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_results');
    }
};
