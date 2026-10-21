<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pistes audio d'un projet « composition » (ADR-042) : musique et narration, chacune un média
 * audio de la médiathèque (RESTRICT, comme les scènes).
 *
 * Limite V1 de 2 pistes garantie par la base : une piste au plus par type et par projet
 * (unique video_project_id + type), soit une musique et une narration.
 * Règles appliquées par TimelineService (phase 5), non exprimables en SQL : média de type audio,
 * appartenant au propriétaire du projet, « ready » ; trim_start_ms inférieur à sa durée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->foreignUuid('media_id')->constrained('media')->restrictOnDelete();
            $table->unsignedInteger('start_ms')->default(0);
            $table->unsignedInteger('trim_start_ms')->default(0);
            $table->unsignedTinyInteger('volume')->default(100);
            $table->boolean('loop')->default(false);
            $table->timestamps();

            $table->unique(['video_project_id', 'type']);
        });

        SchemaConstraints::in('video_tracks', 'type', ['music', 'voiceover']);
        SchemaConstraints::check('video_tracks', 'video_tracks_volume_check', '`volume` BETWEEN 0 AND 100');
    }

    public function down(): void
    {
        Schema::dropIfExists('video_tracks');
    }
};
