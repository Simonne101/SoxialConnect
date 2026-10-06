<?php

namespace Tests\Feature\Ai;

use App\Domain\AI\Enums\AiTask;
use App\Models\AiGeneration;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** ai_generations (ADR-042) : tâche « adapt », suivi d'usage, compatibilité des tâches existantes. */
class AiGenerationSchemaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function insert(array $row): string
    {
        $row = ['id' => (string) Str::uuid7(), 'user_id' => $this->user->id, 'provider' => 'fake', 'model' => 'fake-model', 'created_at' => now(), 'updated_at' => now()] + $row;
        DB::table('ai_generations')->insert($row);

        return $row['id'];
    }

    public function test_usage_columns_and_cache_index_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('ai_generations', ['cost_estimate_micros', 'input_hash']));
        $indexes = array_map(fn (array $i) => implode(',', $i['columns']), Schema::getIndexes('ai_generations'));
        $this->assertContains('user_id,task,input_hash', $indexes);

        $id = $this->insert(['task' => 'adapt', 'cost_estimate_micros' => 1250, 'input_hash' => hash('sha256', 'entrée')]);
        $generation = AiGeneration::findOrFail($id);
        $this->assertSame(AiTask::Adapt, $generation->task);
        $this->assertSame(1250, $generation->cost_estimate_micros);
        $this->assertSame(64, strlen($generation->input_hash));
        $this->assertNull(AiGeneration::findOrFail($this->insert(['task' => 'post']))->cost_estimate_micros, 'Coût inconnu : NULL, jamais 0.');
    }

    public function test_every_existing_task_and_adapt_are_accepted_and_unknown_tasks_refused(): void
    {
        $previous = ['post', 'caption', 'ideas', 'hashtags', 'rephrase', 'script', 'tone', 'video_analysis', 'clip_detection', 'transcription'];
        foreach ([...$previous, 'adapt'] as $task) {
            $this->insert(['task' => $task]);
        }
        $this->assertSame(11, DB::table('ai_generations')->count());
        $this->assertEqualsCanonicalizing([...$previous, 'adapt'], AiTask::values(), 'Enum PHP et contrainte SQL alignés.');

        $this->expectException(QueryException::class);
        $this->insert(['task' => 'translate']);
    }

    public function test_a_project_can_be_traced_back_to_its_script_generation(): void
    {
        $generation = AiGeneration::factory()->for($this->user)->create(['task' => 'script']);
        $project = VideoProject::factory()->for($this->user)->create();
        DB::table('video_projects')->where('id', $project->id)->update(['ai_generation_id' => $generation->id]);

        $this->assertTrue(VideoProject::with('aiGeneration')->findOrFail($project->id)->aiGeneration->is($generation));
        $this->assertSame([$project->id], $generation->videoProjects()->pluck('id')->all());

        // Génération supprimée : le projet reste, sans lien.
        $generation->delete();
        $this->assertNull(DB::table('video_projects')->find($project->id)->ai_generation_id);
    }
}
