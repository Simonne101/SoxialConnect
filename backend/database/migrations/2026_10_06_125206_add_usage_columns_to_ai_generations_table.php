<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi d'usage IA (ADR-042), sans facturation :
 * - cost_estimate_micros : coût estimé en millionièmes de l'unité monétaire (config ai.pricing) ;
 * - input_hash : empreinte SHA-256 (tâche + version de prompt + entrée normalisée + modèle) pour
 *   le cache des générations identiques.
 * Nouvelle tâche « adapt » (adaptation d'un contenu à un réseau) ; toutes les tâches existantes
 * restent autorisées. Aucune donnée existante modifiée.
 */
return new class extends Migration
{
    private const NAME = 'ai_generations_task_check';

    private const BEFORE = ['post', 'caption', 'ideas', 'hashtags', 'rephrase', 'script', 'tone', 'video_analysis', 'clip_detection', 'transcription'];

    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_estimate_micros')->nullable()->after('latency_ms');
            $table->char('input_hash', 64)->nullable()->after('cost_estimate_micros');
            $table->index(['user_id', 'task', 'input_hash']);
        });

        $this->replaceTaskCheck([...self::BEFORE, 'adapt']);
    }

    /** Échoue (volontairement) si des générations « adapt » existent encore : aucune donnée n'est effacée. */
    public function down(): void
    {
        $this->replaceTaskCheck(self::BEFORE);

        Schema::table('ai_generations', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'task', 'input_hash']);
            $table->dropColumn(['cost_estimate_micros', 'input_hash']);
        });
    }

    /** @param list<string> $values */
    private function replaceTaskCheck(array $values): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE `ai_generations` DROP CHECK `'.self::NAME.'`');
        SchemaConstraints::in('ai_generations', 'task', $values);
    }
};
