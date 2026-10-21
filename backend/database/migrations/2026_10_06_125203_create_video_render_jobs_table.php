<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rendus MP4 d'un projet « composition » (ADR-042). Aucun rendu n'est exécuté dans cette phase.
 *
 * Garanties portées par la base :
 * - isolation : clé étrangère composite (video_project_id, user_id) → video_projects (id, user_id) :
 *   un rendu ne peut appartenir qu'au propriétaire du projet ;
 * - un seul rendu actif (queued ou rendering) par projet : colonne générée active_project_id,
 *   unique (NULL pour les rendus terminés, qui peuvent être multiples).
 * spec : timeline figée au moment de la demande ; spec_hash (SHA-256) sert à l'idempotence.
 * Le MP4 produit devient un média normal (output_media_id, SET NULL si le média est supprimé).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cible de la clé étrangère composite (id seul est déjà unique : aucun changement de données).
        Schema::table('video_projects', function (Blueprint $table) {
            $table->unique(['id', 'user_id'], 'video_projects_id_user_unique');
        });

        Schema::create('video_render_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('spec');
            $table->char('spec_hash', 64);
            $table->string('renderer', 32)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedTinyInteger('fps')->nullable();
            $table->foreignUuid('output_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->uuid('active_project_id')->nullable()
                ->virtualAs("IF(`status` IN ('queued', 'rendering'), `video_project_id`, NULL)");

            $table->foreign(['video_project_id', 'user_id'], 'video_render_jobs_project_owner_foreign')
                ->references(['id', 'user_id'])->on('video_projects')->cascadeOnDelete();
            $table->unique('active_project_id', 'video_render_jobs_one_active_per_project');
            $table->index(['video_project_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['video_project_id', 'spec_hash']);
        });

        SchemaConstraints::in('video_render_jobs', 'status', ['queued', 'rendering', 'succeeded', 'failed', 'cancelled']);
        SchemaConstraints::check('video_render_jobs', 'video_render_jobs_progress_check', '`progress` BETWEEN 0 AND 100');
        SchemaConstraints::check('video_render_jobs', 'video_render_jobs_dimensions_check', '(`width` IS NULL OR `width` > 0) AND (`height` IS NULL OR `height` > 0)');
        SchemaConstraints::check('video_render_jobs', 'video_render_jobs_fps_check', '`fps` IS NULL OR `fps` BETWEEN 1 AND 60');

        Schema::table('video_projects', function (Blueprint $table) {
            $table->foreign('last_render_id')->references('id')->on('video_render_jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropForeign(['last_render_id']);
        });
        Schema::dropIfExists('video_render_jobs');
        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropUnique('video_projects_id_user_unique');
        });
    }
};
