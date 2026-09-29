<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/database.md §3.6 — CLIPPING.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->string('source_type', 16);
            $table->string('source_url', 2048)->nullable();
            $table->string('title');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status']);
        });
        SchemaConstraints::in('video_projects', 'source_type', ['upload', 'url']);
        SchemaConstraints::in('video_projects', 'status', ['pending', 'importing', 'analyzing', 'transcribing', 'detecting', 'ready', 'failed', 'cancelled']);
        // « source_media_id OU source_url obligatoire » : contrôlé côté application.
        // MySQL interdit un CHECK sur une colonne portant une action référentielle (ON DELETE).

        Schema::create('video_transcripts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_project_id')->constrained()->cascadeOnDelete();
            $table->string('language', 10);
            $table->string('provider', 32)->nullable();
            $table->string('status', 16)->default('pending');
            $table->json('segments')->nullable();
            $table->longText('full_text')->nullable();
            $table->timestamps();
            $table->unique(['video_project_id', 'language']);
        });
        SchemaConstraints::in('video_transcripts', 'status', ['pending', 'processing', 'ready', 'failed']);

        Schema::create('video_clips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('output_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('title');
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->unsignedInteger('duration_ms')->storedAs('`end_ms` - `start_ms`');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('aspect_ratio', 8)->default('9:16');
            $table->boolean('subtitles_enabled')->default(true);
            $table->json('subtitle_style')->nullable();
            $table->string('source', 16)->default('ai');
            $table->string('status', 16)->default('suggested');
            $table->timestamps();
            $table->index(['video_project_id', 'score']);
        });
        SchemaConstraints::check('video_clips', 'video_clips_range_check', '`start_ms` < `end_ms`');
        SchemaConstraints::check('video_clips', 'video_clips_score_check', '`score` IS NULL OR (`score` >= 0 AND `score` <= 100)');
        SchemaConstraints::in('video_clips', 'aspect_ratio', ['9:16', '1:1', '16:9']);
        SchemaConstraints::in('video_clips', 'source', ['ai', 'manual']);
        SchemaConstraints::in('video_clips', 'status', ['suggested', 'edited', 'rendering', 'rendered', 'failed', 'discarded']);
    }

    public function down(): void
    {
        Schema::dropIfExists('video_clips');
        Schema::dropIfExists('video_transcripts');
        Schema::dropIfExists('video_projects');
    }
};
