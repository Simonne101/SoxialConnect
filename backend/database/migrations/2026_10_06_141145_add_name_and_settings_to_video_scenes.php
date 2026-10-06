<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video Studio — timeline (ADR-044) : une scène reçoit un nom et des réglages libres (JSON).
 * Une scène est un segment narratif du projet (accroche, développement, appel à l'action…) ;
 * son fond éventuel reste media_id + fit (phase 1). Les éléments de timeline peuvent lui être
 * rattachés (video_timeline_items.video_scene_id). Ajouts seulement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_scenes', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('position');
            $table->json('settings')->nullable()->after('transition');
        });
    }

    public function down(): void
    {
        Schema::table('video_scenes', function (Blueprint $table) {
            $table->dropColumn(['name', 'settings']);
        });
    }
};
