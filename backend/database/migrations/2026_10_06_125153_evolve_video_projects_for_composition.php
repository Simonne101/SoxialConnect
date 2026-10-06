<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Video Studio par scènes (ADR-042) : video_projects accueille un second type de projet,
 * « composition » (scènes + pistes audio → rendu MP4), à côté du « clipping » d'origine.
 *
 * Lignes existantes : toutes antérieures à la composition, donc marquées « clipping » ; leurs
 * colonnes et statuts restent valides. Nouveaux projets : « composition », statut « draft ».
 * Les CHECK de source_type et status deviennent dépendants du type de projet.
 * last_render_id reçoit sa clé étrangère dans la migration de video_render_jobs.
 */
return new class extends Migration
{
    private const CLIPPING_STATUSES = ['pending', 'importing', 'analyzing', 'transcribing', 'detecting', 'ready', 'failed', 'cancelled'];

    private const COMPOSITION_STATUSES = ['draft', 'rendering', 'ready', 'failed'];

    private const SOURCE_TYPES = ['upload', 'url'];

    private const NEW_CHECKS = [
        'video_projects_kind_check',
        'video_projects_aspect_ratio_check',
        'video_projects_clipping_source_check',
        'video_projects_target_duration_check',
        'video_projects_lock_version_check',
    ];

    public function up(): void
    {
        $this->dropCheck('video_projects_source_type_check');
        $this->dropCheck('video_projects_status_check');

        Schema::table('video_projects', function (Blueprint $table) {
            $table->string('kind', 16)->default('composition')->after('user_id');
            $table->string('aspect_ratio', 8)->default('9:16')->after('title');
            $table->unsignedInteger('target_duration_ms')->nullable()->after('aspect_ratio');
            $table->json('settings')->nullable()->after('target_duration_ms');
            $table->unsignedInteger('lock_version')->default(0)->after('settings');
            $table->foreignUuid('ai_generation_id')->nullable()->after('lock_version')->constrained('ai_generations')->nullOnDelete();
            $table->uuid('last_render_id')->nullable()->after('ai_generation_id');
            $table->string('source_type', 16)->nullable()->change();
            $table->string('status', 32)->default('draft')->change();
            $table->index(['user_id', 'kind', 'updated_at']);
        });

        // Projets existants : tous issus du clipping (seul type existant jusqu'ici).
        DB::table('video_projects')->update(['kind' => 'clipping']);

        SchemaConstraints::in('video_projects', 'kind', ['composition', 'clipping']);
        SchemaConstraints::in('video_projects', 'aspect_ratio', ['9:16', '1:1', '16:9']);
        SchemaConstraints::check('video_projects', 'video_projects_source_type_check', '`source_type` IS NULL OR `source_type` IN ('.$this->list(self::SOURCE_TYPES).')');
        // Un projet de clipping a toujours une source ; une composition n'en a pas.
        SchemaConstraints::check('video_projects', 'video_projects_clipping_source_check', "`kind` <> 'clipping' OR `source_type` IS NOT NULL");
        SchemaConstraints::check('video_projects', 'video_projects_status_check',
            "(`kind` = 'clipping' AND `status` IN (".$this->list(self::CLIPPING_STATUSES).'))'
            ." OR (`kind` = 'composition' AND `status` IN (".$this->list(self::COMPOSITION_STATUSES).'))');
        SchemaConstraints::check('video_projects', 'video_projects_target_duration_check', '`target_duration_ms` IS NULL OR `target_duration_ms` > 0');
        SchemaConstraints::check('video_projects', 'video_projects_lock_version_check', '`lock_version` >= 0');
    }

    /** Refusé (aucune donnée effacée) tant que des projets « composition » existent. */
    public function down(): void
    {
        if (DB::table('video_projects')->where('kind', 'composition')->exists()) {
            throw new RuntimeException('Des projets vidéo « composition » existent : supprimez-les avant d\'annuler cette migration.');
        }

        foreach ([...self::NEW_CHECKS, 'video_projects_source_type_check', 'video_projects_status_check'] as $name) {
            $this->dropCheck($name);
        }

        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'kind', 'updated_at']);
            $table->dropConstrainedForeignId('ai_generation_id');
            $table->dropColumn(['kind', 'aspect_ratio', 'target_duration_ms', 'settings', 'lock_version', 'last_render_id']);
            $table->string('source_type', 16)->nullable(false)->change();
            $table->string('status', 32)->default('pending')->change();
        });

        SchemaConstraints::in('video_projects', 'source_type', self::SOURCE_TYPES);
        SchemaConstraints::in('video_projects', 'status', self::CLIPPING_STATUSES);
    }

    private function dropCheck(string $name): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `video_projects` DROP CHECK `{$name}`");
        }
    }

    /** @param list<string> $values */
    private function list(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => DB::getPdo()->quote($v), $values));
    }
};
