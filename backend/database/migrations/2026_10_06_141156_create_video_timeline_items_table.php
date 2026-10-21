<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Éléments de timeline (ADR-044). Unité de temps unique : la milliseconde (entiers).
 * - start_ms / duration_ms : position et durée sur la timeline du projet ;
 * - source_start_ms / source_duration_ms : extrait lu dans le média source (vidéo, audio) ;
 * - ordre dans une piste : start_ms (pas de chevauchement dans une piste, contrôlé par
 *   VideoTimelineService sous verrou du projet) ; superposition entre pistes : position de la piste.
 *
 * Règles appliquées par VideoTimelineService (non exprimables en SQL : MySQL interdit un CHECK sur
 * une colonne portant une action référentielle) : asset du même projet et de type compatible,
 * texte sans asset, extrait dans la durée du média, fin <= durée maximale du projet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_timeline_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_track_id')->constrained()->cascadeOnDelete();
            // Scène à laquelle l'élément appartient (facultatif) ; la suppression de la scène le détache.
            $table->foreignUuid('video_scene_id')->nullable()->constrained()->nullOnDelete();
            // Un asset utilisé sur la timeline ne peut pas être retiré du projet.
            $table->foreignUuid('video_project_asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 8);
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('source_start_ms')->nullable();
            $table->unsignedInteger('source_duration_ms')->nullable();
            $table->json('properties')->nullable();
            $table->json('transitions')->nullable();
            $table->timestamps();

            $table->index(['video_track_id', 'start_ms']);
        });

        SchemaConstraints::in('video_timeline_items', 'type', ['video', 'image', 'text', 'audio']);
        SchemaConstraints::check('video_timeline_items', 'video_timeline_items_duration_check', '`duration_ms` > 0');
        SchemaConstraints::check('video_timeline_items', 'video_timeline_items_source_duration_check', '`source_duration_ms` IS NULL OR `source_duration_ms` > 0');
        // Un extrait de source n'a de sens que pour la vidéo et l'audio.
        SchemaConstraints::check('video_timeline_items', 'video_timeline_items_source_check',
            "`type` IN ('video', 'audio') OR (`source_start_ms` IS NULL AND `source_duration_ms` IS NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('video_timeline_items');
    }
};
