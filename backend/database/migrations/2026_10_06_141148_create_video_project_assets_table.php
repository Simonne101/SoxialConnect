<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assets d'un projet vidéo (ADR-044) : médias de la médiathèque rattachés au projet — aucune copie,
 * aucune seconde médiathèque. Les éléments de timeline référencent un asset du projet.
 *
 * Isolation garantie par la base : user_id est à la fois le propriétaire du projet
 * (FK composite → video_projects (id, user_id)) et celui du média (FK composite → media (id, user_id)) :
 * un projet ne peut pas référencer le média d'un autre utilisateur.
 * RESTRICT sur le média : un média rattaché à un projet ne peut pas être purgé définitivement.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cible de la clé étrangère composite (id seul est déjà unique : aucune donnée modifiée).
        Schema::table('media', function (Blueprint $table) {
            $table->unique(['id', 'user_id'], 'media_id_user_unique');
        });

        Schema::create('video_project_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('media_id')->constrained('media')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['video_project_id', 'user_id'], 'video_project_assets_project_owner_foreign')
                ->references(['id', 'user_id'])->on('video_projects')->cascadeOnDelete();
            $table->foreign(['media_id', 'user_id'], 'video_project_assets_media_owner_foreign')
                ->references(['id', 'user_id'])->on('media')->restrictOnDelete();
            $table->unique(['video_project_id', 'media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_project_assets');
        Schema::table('media', function (Blueprint $table) {
            $table->dropUnique('media_id_user_unique');
        });
    }
};
