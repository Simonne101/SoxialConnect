<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/database.md §3.5 — AI (clés étrangères explicites, pas de polymorphisme).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('video_project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task', 32);
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('prompt_version', 32)->nullable();
            $table->string('status', 16)->default('pending');
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'task']);
        });
        SchemaConstraints::in('ai_generations', 'task', ['post', 'caption', 'ideas', 'hashtags', 'rephrase', 'script', 'tone', 'video_analysis', 'clip_detection', 'transcription']);
        SchemaConstraints::in('ai_generations', 'status', ['pending', 'processing', 'succeeded', 'failed']);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};
