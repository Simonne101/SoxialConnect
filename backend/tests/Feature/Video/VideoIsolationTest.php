<?php

namespace Tests\Feature\Video;

use App\Models\AiGeneration;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRenderJob;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Isolation par utilisateur des données IA et vidéo (ADR-042), au niveau préparé par la phase 1 :
 * - garantie par la base : un rendu appartient toujours au propriétaire de son projet ;
 * - requêtes : ownedBy() ne renvoie que les ressources de l'utilisateur ;
 * - politiques : un projet ou une génération d'autrui est introuvable (404) ;
 * - assignation de masse : propriétaire, statut, version et références ne sont jamais acceptés.
 * La propriété des médias des scènes et pistes est une règle de TimelineService (phase 5,
 * docs/database.md §3.6) : ce test fixe la requête sur laquelle elle s'appuiera.
 */
class VideoIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    public function test_the_database_refuses_a_render_owned_by_someone_else_than_the_project_owner(): void
    {
        $bobsProject = VideoProject::factory()->for($this->bob)->create();

        try {
            DB::table('video_render_jobs')->insert([
                'id' => (string) Str::uuid7(),
                'video_project_id' => $bobsProject->id,
                'user_id' => $this->alice->id,
                'spec' => '{}',
                'spec_hash' => str_repeat('a', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Un rendu d\'Alice sur le projet de Bob aurait dû être refusé.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('video_render_jobs_project_owner_foreign', $e->getMessage());
        }

        // Le changement de propriétaire d'un rendu existant est refusé de la même façon.
        $render = VideoRenderJob::factory()->for($bobsProject, 'project')->create();
        $this->expectException(QueryException::class);
        DB::table('video_render_jobs')->where('id', $render->id)->update(['user_id' => $this->alice->id]);
    }

    public function test_owned_by_returns_only_the_users_own_projects_renders_and_generations(): void
    {
        $aliceProject = VideoProject::factory()->for($this->alice)->create();
        $bobProject = VideoProject::factory()->for($this->bob)->create();
        VideoRenderJob::factory()->for($aliceProject, 'project')->create();
        VideoRenderJob::factory()->for($bobProject, 'project')->create();
        AiGeneration::factory()->for($this->alice)->create();
        AiGeneration::factory()->for($this->bob)->create();

        $this->assertSame([$aliceProject->id], VideoProject::query()->ownedBy($this->alice)->pluck('id')->all());
        $this->assertSame([$this->alice->id], VideoRenderJob::query()->ownedBy($this->alice)->pluck('user_id')->unique()->values()->all());
        $this->assertSame([$this->alice->id], AiGeneration::query()->ownedBy($this->alice)->pluck('user_id')->all());
    }

    public function test_policies_hide_another_users_project_and_generation(): void
    {
        $bobProject = VideoProject::factory()->for($this->bob)->create();
        $bobGeneration = AiGeneration::factory()->for($this->bob)->create();

        foreach (['view', 'update', 'delete'] as $ability) {
            $project = Gate::forUser($this->alice)->inspect($ability, $bobProject);
            $this->assertTrue($project->denied(), "project.{$ability}");
            $this->assertSame(404, $project->status(), "project.{$ability} : introuvable, sans révéler l'existence");
            $this->assertTrue(Gate::forUser($this->alice)->inspect($ability, $bobGeneration)->denied(), "generation.{$ability}");
            $this->assertTrue(Gate::forUser($this->bob)->allows($ability, $bobProject), "propriétaire : project.{$ability}");
        }
    }

    public function test_ownership_status_and_references_are_never_mass_assignable(): void
    {
        $bobGeneration = AiGeneration::factory()->for($this->bob)->create();

        foreach ([
            ['user_id' => $this->bob->id],
            ['status' => 'ready'],
            ['lock_version' => 99],
            ['ai_generation_id' => $bobGeneration->id],
            ['last_render_id' => (string) Str::uuid7()],
        ] as $attributes) {
            try {
                new VideoProject(['title' => 'Projet'] + $attributes);
                $this->fail('Attribut assignable en masse : '.array_key_first($attributes));
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(MassAssignmentException::class);
        new VideoRenderJob(['user_id' => $this->alice->id]);
    }

    public function test_media_of_another_user_is_distinguished_by_owned_by(): void
    {
        $aliceMedia = Media::factory()->for($this->alice)->create();
        $bobMedia = Media::factory()->for($this->bob)->create();

        // Requête de contrôle que TimelineService appliquera à chaque media_id d'une scène ou d'une piste.
        $this->assertTrue(Media::query()->ownedBy($this->alice)->whereKey($aliceMedia->id)->exists());
        $this->assertFalse(Media::query()->ownedBy($this->alice)->whereKey($bobMedia->id)->exists());
        $this->assertSame([$aliceMedia->id], Media::query()->ownedBy($this->alice)->whereKey([$aliceMedia->id, $bobMedia->id])->pluck('id')->all());
    }

    public function test_deleting_a_user_removes_their_video_and_ai_data_only(): void
    {
        $aliceProject = VideoProject::factory()->for($this->alice)->create();
        VideoRenderJob::factory()->for($aliceProject, 'project')->create();
        AiGeneration::factory()->for($this->alice)->create();
        $bobProject = VideoProject::factory()->for($this->bob)->create();
        VideoRenderJob::factory()->for($bobProject, 'project')->create();

        $this->alice->forceDelete();

        $this->assertSame([$bobProject->id], DB::table('video_projects')->pluck('id')->all());
        $this->assertSame([$this->bob->id], DB::table('video_render_jobs')->pluck('user_id')->all());
        $this->assertSame(0, DB::table('ai_generations')->where('user_id', $this->alice->id)->count());
    }
}
