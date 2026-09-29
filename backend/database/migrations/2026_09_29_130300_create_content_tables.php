<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/database.md §3.3 — CONTENT.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('body');
            $table->string('status', 32)->default('draft');
            $table->dateTime('scheduled_at')->nullable();
            $table->string('timezone', 64)->default('Europe/Paris');
            $table->dateTime('published_at')->nullable();
            $table->string('source', 16)->default('manual');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });
        SchemaConstraints::in('posts', 'status', ['draft', 'scheduled', 'processing', 'partially_published', 'published', 'failed', 'cancelled']);
        SchemaConstraints::in('posts', 'source', ['manual', 'ai', 'clip']);
        SchemaConstraints::check('posts', 'posts_scheduled_requires_date', "`status` <> 'scheduled' OR `scheduled_at` IS NOT NULL");

        Schema::create('post_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->constrained()->restrictOnDelete();
            $table->text('body')->nullable();
            $table->string('format', 32);
            $table->json('options')->nullable();
            $table->string('status', 32)->default('draft');
            $table->dateTime('scheduled_at')->nullable();
            $table->string('external_post_id', 191)->nullable();
            $table->string('external_url', 1024)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'social_account_id', 'format']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['social_account_id', 'external_post_id']);
        });
        SchemaConstraints::in('post_variants', 'format', ['feed', 'story', 'reel', 'short', 'video', 'pin', 'thread', 'carousel']);
        SchemaConstraints::in('post_variants', 'status', ['draft', 'scheduled', 'queued', 'publishing', 'published', 'failed', 'cancelled']);

        Schema::create('post_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('media_id')->constrained('media')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('alt_text', 1000)->nullable();
            $table->json('crop')->nullable();
            $table->timestamps();
            $table->char('post_variant_key', 36)->virtualAs("COALESCE(`post_variant_id`, '')");
            $table->unique(['post_id', 'post_variant_key', 'media_id'], 'post_media_unique');
        });

        Schema::create('publication_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('idempotency_key', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->boolean('retryable')->default(false);
            $table->uuid('job_uuid')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->json('response_excerpt')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['post_variant_id', 'attempt_number']);
            $table->index(['status', 'started_at']);
        });
        SchemaConstraints::in('publication_attempts', 'status', ['pending', 'in_progress', 'succeeded', 'failed', 'unknown']);
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_attempts');
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('post_variants');
        Schema::dropIfExists('posts');
    }
};
