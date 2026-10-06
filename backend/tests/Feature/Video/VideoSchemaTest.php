<?php

namespace Tests\Feature\Video;

use App\Domain\Video\Enums\RenderStatus;
use App\Domain\Video\Enums\SceneFit;
use App\Domain\Video\Enums\SceneMediaType;
use App\Domain\Video\Enums\SceneRole;
use App\Domain\Video\Enums\SceneTransition;
use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\Enums\VideoTrackType;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectAsset;
use App\Models\VideoRenderJob;
use App\Models\VideoScene;
use App\Models\VideoTimelineItem;
use App\Models\VideoTrack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Schéma Video Studio (ADR-042) : colonnes, valeurs par défaut, index, clés étrangères et
 * contraintes CHECK réellement appliquées par MySQL. Insertions en SQL brut : on vérifie ce que
 * la base garantit, indépendamment du code PHP.
 */
class VideoSchemaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** Insère une ligne et renvoie son identifiant. */
    private function insert(string $table, array $row): string
    {
        $row = ['id' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now()] + $row;
        DB::table($table)->insert($row);

        return $row['id'];
    }

    private function project(array $row = []): string
    {
        return $this->insert('video_projects', $row + ['user_id' => $this->user->id, 'title' => 'Projet']);
    }

    private function assertRejected(callable $statement, string $case): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("La base aurait dû refuser : {$case}.");
    }

    /** @return list<string> */
    private function indexColumns(string $table): array
    {
        return array_map(fn (array $i) => implode(',', $i['columns']).($i['unique'] ? ' (unique)' : ''), Schema::getIndexes($table));
    }

    /** @return array<string, string> colonnes → table:colonnes ON DELETE */
    private function foreignKeys(string $table): array
    {
        $keys = [];
        foreach (Schema::getForeignKeys($table) as $fk) {
            $keys[implode(',', $fk['columns'])] = $fk['foreign_table'].':'.implode(',', $fk['foreign_columns']).' '.strtolower($fk['on_delete']);
        }

        return $keys;
    }

    // --- video_projects ---------------------------------------------------------------------

    public function test_a_new_project_is_a_composition_draft_with_safe_defaults(): void
    {
        $id = $this->project();
        $row = DB::table('video_projects')->find($id);

        $this->assertSame('composition', $row->kind);
        $this->assertSame('draft', $row->status);
        $this->assertSame('9:16', $row->aspect_ratio);
        $this->assertSame(0, (int) $row->lock_version);
        $this->assertNull($row->source_type);
        $this->assertNull($row->settings);
        $this->assertNull($row->ai_generation_id);
        $this->assertNull($row->last_render_id);

        $this->assertContains('user_id,kind,updated_at', $this->indexColumns('video_projects'));
        $this->assertContains('id,user_id (unique)', $this->indexColumns('video_projects'));
        $this->assertSame('ai_generations:id set null', $this->foreignKeys('video_projects')['ai_generation_id']);
        $this->assertSame('video_render_jobs:id set null', $this->foreignKeys('video_projects')['last_render_id']);
    }

    public function test_status_and_source_depend_on_the_project_kind(): void
    {
        foreach (['draft', 'rendering', 'ready', 'failed'] as $status) {
            $this->project(['status' => $status]);
        }
        foreach (['pending', 'importing', 'analyzing', 'transcribing', 'detecting', 'ready', 'failed', 'cancelled'] as $status) {
            $this->project(['kind' => 'clipping', 'status' => $status, 'source_type' => 'upload']);
        }

        $this->assertRejected(fn () => $this->project(['status' => 'pending']), 'statut de clipping sur une composition');
        $this->assertRejected(fn () => $this->project(['kind' => 'clipping', 'status' => 'draft', 'source_type' => 'url']), 'statut de composition sur un clipping');
        $this->assertRejected(fn () => $this->project(['kind' => 'clipping', 'status' => 'pending']), 'clipping sans source');
        $this->assertRejected(fn () => $this->project(['kind' => 'montage']), 'type inconnu');
        $this->assertRejected(fn () => $this->project(['aspect_ratio' => '4:3']), 'format inconnu');
        $this->assertRejected(fn () => $this->project(['source_type' => 'ftp']), 'source inconnue');
        $this->assertRejected(fn () => $this->project(['target_duration_ms' => 0]), 'durée cible nulle');
    }

    public function test_every_domain_enum_value_is_accepted_by_the_database(): void
    {
        foreach (VideoProjectKind::values() as $kind) {
            $this->project(['kind' => $kind] + ($kind === 'clipping' ? ['status' => 'pending', 'source_type' => 'upload'] : []));
        }
        $project = $this->project();
        foreach (SceneRole::cases() as $i => $role) {
            $this->insert('video_scenes', ['video_project_id' => $project, 'position' => $i, 'role' => $role->value, 'duration_ms' => 1000]);
        }
        foreach ([...SceneFit::values(), ...SceneTransition::values(), ...SceneMediaType::values()] as $i => $value) {
            $column = in_array($value, SceneFit::values(), true) ? 'fit' : (in_array($value, SceneTransition::values(), true) ? 'transition' : 'media_type');
            $this->insert('video_scenes', ['video_project_id' => $project, 'position' => 100 + $i, 'duration_ms' => 1000, $column => $value]);
        }
        foreach (VideoTrackType::values() as $position => $type) {
            $this->insert('video_tracks', ['video_project_id' => $project, 'type' => $type, 'position' => $position]);
        }
        foreach (RenderStatus::values() as $status) {
            $this->insert('video_render_jobs', ['video_project_id' => $this->project(), 'user_id' => $this->user->id, 'status' => $status, 'spec' => '{}', 'spec_hash' => str_repeat('a', 64)]);
        }

        $this->assertSame(count(SceneRole::cases()) + 6, DB::table('video_scenes')->count());
    }

    // --- video_scenes -----------------------------------------------------------------------

    public function test_scene_columns_defaults_indexes_and_foreign_keys(): void
    {
        $id = $this->insert('video_scenes', ['video_project_id' => $this->project(), 'position' => 0, 'duration_ms' => 3000]);
        $row = DB::table('video_scenes')->find($id);

        $this->assertSame('body', $row->role);
        $this->assertSame('cover', $row->fit);
        $this->assertSame('none', $row->transition);
        $this->assertNull($row->media_id);
        $this->assertNull($row->media_type);
        $this->assertContains('video_project_id,position (unique)', $this->indexColumns('video_scenes'));
        $this->assertSame('video_projects:id cascade', $this->foreignKeys('video_scenes')['video_project_id']);
        $this->assertSame('media:id restrict', $this->foreignKeys('video_scenes')['media_id']);
    }

    public function test_scene_constraints_are_enforced(): void
    {
        $project = $this->project();
        $scene = fn (array $row) => $this->insert('video_scenes', $row + ['video_project_id' => $project, 'position' => random_int(1000, 60000), 'duration_ms' => 1000]);

        $scene(['duration_ms' => 500]);
        $scene(['duration_ms' => 30000]);
        $this->assertRejected(fn () => $scene(['duration_ms' => 499]), 'scène de moins de 500 ms');
        $this->assertRejected(fn () => $scene(['duration_ms' => 30001]), 'scène de plus de 30 s');
        $this->assertRejected(fn () => $scene(['role' => 'outro']), 'rôle inconnu');
        $this->assertRejected(fn () => $scene(['media_type' => 'audio']), 'audio comme visuel');
        $this->assertRejected(fn () => $scene(['fit' => 'stretch']), 'cadrage inconnu');
        $this->assertRejected(fn () => $scene(['transition' => 'wipe']), 'transition inconnue');

        $scene(['position' => 7]);
        $this->assertRejected(fn () => $scene(['position' => 7]), 'deux scènes à la même position');
        $this->assertRejected(fn () => $this->insert('video_scenes', ['video_project_id' => (string) Str::uuid7(), 'position' => 0, 'duration_ms' => 1000]), 'projet inexistant');
    }

    // --- video_tracks -----------------------------------------------------------------------

    /** Pistes de timeline génériques (ADR-044, remplace « une piste audio par type » de l'ADR-042). */
    public function test_tracks_are_ordered_layers_of_a_known_type(): void
    {
        $project = $this->project();
        $video = $this->insert('video_tracks', ['video_project_id' => $project, 'type' => 'video', 'position' => 0]);
        $this->insert('video_tracks', ['video_project_id' => $project, 'type' => 'video', 'position' => 1]);

        $row = DB::table('video_tracks')->find($video);
        $this->assertSame('', $row->name);
        $this->assertSame(0, (int) $row->locked);
        $this->assertSame(0, (int) $row->muted);
        $this->assertSame(0, (int) $row->hidden);
        $this->assertNull($row->metadata);

        $this->assertRejected(fn () => $this->insert('video_tracks', ['video_project_id' => $project, 'type' => 'text', 'position' => 1]), 'deux pistes à la même position');
        $this->assertRejected(fn () => $this->insert('video_tracks', ['video_project_id' => $project, 'type' => 'music', 'position' => 5]), 'ancien type « music »');
        $this->assertRejected(fn () => $this->insert('video_tracks', ['video_project_id' => $project, 'type' => 'sfx', 'position' => 6]), 'type de piste inconnu');

        $this->assertContains('video_project_id,position (unique)', $this->indexColumns('video_tracks'));
        $this->assertNotContains('video_project_id,type (unique)', $this->indexColumns('video_tracks'), 'Plus de limite d\'une piste par type.');
        $this->assertSame(['video_project_id' => 'video_projects:id cascade'], $this->foreignKeys('video_tracks'), 'Plus de média sur la piste : il est porté par les éléments.');
    }

    public function test_media_used_by_a_scene_or_attached_to_a_project_cannot_be_purged(): void
    {
        $image = Media::factory()->for($this->user)->create();
        $audio = Media::factory()->for($this->user)->create(['type' => 'audio', 'mime_type' => 'audio/mpeg', 'extension' => 'mp3']);
        $project = VideoProject::factory()->for($this->user)->create();
        VideoScene::factory()->for($project, 'project')->create(['media_id' => $image->id, 'media_type' => 'image']);
        VideoProjectAsset::factory()->for($project, 'project')->create(['media_id' => $audio->id]);

        $this->assertRejected(fn () => $image->forceDelete(), 'purge d\'une image utilisée par une scène');
        $this->assertRejected(fn () => $audio->forceDelete(), 'purge d\'un audio rattaché à un projet');
    }

    // --- video_render_jobs ------------------------------------------------------------------

    public function test_render_job_columns_defaults_indexes_and_foreign_keys(): void
    {
        $id = $this->insert('video_render_jobs', ['video_project_id' => $this->project(), 'user_id' => $this->user->id, 'spec' => '{}', 'spec_hash' => str_repeat('a', 64)]);
        $row = DB::table('video_render_jobs')->find($id);

        $this->assertSame('queued', $row->status);
        $this->assertSame(0, (int) $row->progress);
        $this->assertSame($row->video_project_id, $row->active_project_id);

        $indexes = $this->indexColumns('video_render_jobs');
        foreach (['video_project_id,created_at', 'user_id,status', 'video_project_id,spec_hash', 'active_project_id (unique)'] as $index) {
            $this->assertContains($index, $indexes);
        }
        $keys = $this->foreignKeys('video_render_jobs');
        $this->assertSame('video_projects:id cascade', $keys['video_project_id']);
        $this->assertSame('users:id cascade', $keys['user_id']);
        $this->assertSame('video_projects:id,user_id cascade', $keys['video_project_id,user_id']);
        $this->assertSame('media:id set null', $keys['output_media_id']);
    }

    public function test_render_job_constraints_are_enforced(): void
    {
        $render = fn (array $row) => $this->insert('video_render_jobs', $row + ['video_project_id' => $this->project(), 'user_id' => $this->user->id, 'status' => 'succeeded', 'spec' => '{}', 'spec_hash' => str_repeat('a', 64)]);

        $render(['progress' => 100, 'width' => 1080, 'height' => 1920, 'fps' => 30]);
        $this->assertRejected(fn () => $render(['status' => 'paused']), 'statut inconnu');
        $this->assertRejected(fn () => $render(['progress' => 101]), 'progression > 100');
        $this->assertRejected(fn () => $render(['fps' => 61]), 'fps > 60');
        $this->assertRejected(fn () => $render(['fps' => 0]), 'fps nul');
        $this->assertRejected(fn () => $render(['width' => 0]), 'largeur nulle');
        $this->assertRejected(fn () => $render(['spec' => null]), 'rendu sans spécification');
    }

    public function test_only_one_render_can_be_active_per_project(): void
    {
        $project = VideoProject::factory()->for($this->user)->create();
        $first = VideoRenderJob::factory()->for($project, 'project')->queued()->create();

        $this->assertRejected(fn () => VideoRenderJob::factory()->for($project, 'project')->queued()->create(), 'deuxième rendu en file');
        $this->assertRejected(fn () => VideoRenderJob::factory()->for($project, 'project')->create(['status' => 'rendering']), 'deuxième rendu en cours');

        // Une fois le rendu terminé, un nouveau rendu peut être demandé ; les rendus terminés s'accumulent.
        $first->forceFill(['status' => 'succeeded'])->save();
        VideoRenderJob::factory()->for($project, 'project')->queued()->create();
        VideoRenderJob::factory()->for($project, 'project')->count(2)->create(['status' => 'failed']);
        // Un autre projet a son propre rendu actif.
        VideoRenderJob::factory()->queued()->create();

        $this->assertSame(4, VideoRenderJob::query()->where('video_project_id', $project->id)->count());
    }

    public function test_deleting_a_project_or_an_output_cleans_references_without_losing_media(): void
    {
        $output = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $project = VideoProject::factory()->for($this->user)->create();
        $render = VideoRenderJob::factory()->for($project, 'project')->create();
        DB::table('video_render_jobs')->where('id', $render->id)->update(['output_media_id' => $output->id]);
        DB::table('video_projects')->where('id', $project->id)->update(['last_render_id' => $render->id]);
        VideoScene::factory()->for($project, 'project')->create();

        // Média de sortie supprimé définitivement : le rendu reste, sans lien.
        $output->forceDelete();
        $this->assertNull(DB::table('video_render_jobs')->find($render->id)->output_media_id);

        // Rendu supprimé : le projet perd seulement sa référence au dernier rendu.
        DB::table('video_render_jobs')->where('id', $render->id)->delete();
        $this->assertNull(DB::table('video_projects')->find($project->id)->last_render_id);

        // Projet supprimé définitivement : scènes, pistes et rendus suivent ; les médias restent.
        $image = Media::factory()->for($this->user)->create();
        VideoRenderJob::factory()->for($project, 'project')->create();
        $project->forceDelete();
        $this->assertSame(0, DB::table('video_scenes')->count());
        $this->assertSame(0, DB::table('video_render_jobs')->count());
        $this->assertNotNull(Media::find($image->id));
    }

    public function test_relations_and_casts_of_the_models(): void
    {
        $project = VideoProject::factory()->for($this->user)->create(['settings' => ['font' => 'Inter']]);
        VideoScene::factory()->for($project, 'project')->create(['position' => 2, 'role' => 'cta']);
        VideoScene::factory()->for($project, 'project')->create(['position' => 1, 'role' => 'hook']);
        $track = VideoTrack::factory()->for($project, 'project')->type('audio')->create();
        $asset = VideoProjectAsset::factory()->for($project, 'project')->create([
            'media_id' => Media::factory()->for($this->user)->create(['type' => 'audio', 'mime_type' => 'audio/mpeg', 'extension' => 'mp3'])->id,
        ]);
        VideoTimelineItem::factory()->for($track, 'track')->create(['type' => 'audio', 'video_project_asset_id' => $asset->id, 'properties' => ['volume' => 80]]);
        $render = VideoRenderJob::factory()->for($project, 'project')->create();

        $fresh = VideoProject::query()->with(['scenes', 'tracks.items.asset.media', 'assets', 'renderJobs', 'user'])->findOrFail($project->id);
        $this->assertSame(VideoProjectKind::Composition, $fresh->kind);
        $this->assertSame(['font' => 'Inter'], $fresh->settings);
        $this->assertSame([SceneRole::Hook, SceneRole::Cta], $fresh->scenes->pluck('role')->all(), 'Scènes dans l\'ordre de position.');
        $this->assertSame(VideoTrackType::Audio, $fresh->tracks->sole()->type);
        $this->assertSame('audio', $fresh->tracks->sole()->items->sole()->asset->media->type->value);
        $this->assertSame([$asset->id], $fresh->assets->pluck('id')->all());
        $this->assertTrue($fresh->user->is($this->user));
        $this->assertSame(RenderStatus::Succeeded, $fresh->renderJobs->sole()->status);
        $this->assertTrue(VideoRenderJob::with('project', 'user')->findOrFail($render->id)->user->is($this->user));
    }
}
