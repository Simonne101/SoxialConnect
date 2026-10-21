<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nouvelle tâche IA « image » (génération d'images, ADR-046). Toutes les tâches existantes restent
 * autorisées ; aucune donnée existante modifiée.
 */
return new class extends Migration
{
    private const NAME = 'ai_generations_task_check';

    private const BEFORE = ['post', 'caption', 'ideas', 'hashtags', 'rephrase', 'script', 'tone', 'video_analysis', 'clip_detection', 'transcription', 'adapt'];

    public function up(): void
    {
        $this->replaceTaskCheck([...self::BEFORE, 'image']);
    }

    /** Échoue (volontairement) si des générations « image » existent encore : aucune donnée n'est effacée. */
    public function down(): void
    {
        $this->replaceTaskCheck(self::BEFORE);
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
