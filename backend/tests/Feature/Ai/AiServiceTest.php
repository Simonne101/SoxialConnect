<?php

namespace Tests\Feature\Ai;

use App\Console\Commands\AiCheckConnection;
use App\Domain\AI\AiService;
use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\Providers\OpenAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AiService et commande ai:check (ADR-043) : OpenAI fournisseur par défaut, choix du modèle,
 * vérification de connexion minimale sans écriture en base, absence de clé gérée proprement.
 * Aucun appel réel : la commande n'appelle OpenAI que lorsqu'elle est lancée à la main.
 */
class AiServiceTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-test-cle-fictive-ne-doit-jamais-sortir-1111';

    private const URL = 'https://api.openai.com/v1/responses';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai.default_provider' => 'openai',
            'ai.default_model' => null,
            // Indépendant du .env local (modèles de tâches éventuellement configurés).
            ...collect(array_keys(config('ai.tasks')))->mapWithKeys(fn ($task) => ["ai.tasks.{$task}.model" => null, "ai.tasks.{$task}.provider" => null])->all(),
            'ai.providers.openai.api_key' => self::KEY,
            'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        ]);
    }

    private function ok(): array
    {
        return [
            'id' => 'resp_ok', 'object' => 'response', 'status' => 'completed', 'model' => 'modele-renvoye',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'OK']]]],
            'usage' => ['input_tokens' => 14, 'output_tokens' => 2, 'total_tokens' => 16],
            'error' => null, 'incomplete_details' => null,
        ];
    }

    public function test_openai_is_the_default_provider(): void
    {
        $config = (function () {
            $_SERVER['AI_DEFAULT_PROVIDER'] = $_ENV['AI_DEFAULT_PROVIDER'] = 'openai';
            try {
                return require config_path('ai.php');
            } finally {
                unset($_SERVER['AI_DEFAULT_PROVIDER'], $_ENV['AI_DEFAULT_PROVIDER']);
            }
        })();
        $this->assertSame('openai', $config['default_provider']);
        $this->assertMatchesRegularExpression('/^AI_DEFAULT_PROVIDER=openai$/m', (string) file_get_contents(base_path('.env.example')), 'Valeur documentée dans .env.example.');

        $service = app(AiService::class);
        $this->assertInstanceOf(OpenAiProvider::class, $service->provider());
        $this->assertTrue($service->isConfigured());

        config(['ai.providers.openai.api_key' => null]);
        $this->assertFalse($service->isConfigured(), 'Sans clé : non configuré, sans appel.');
        Http::assertNothingSent();
    }

    public function test_missing_or_unknown_provider_is_reported(): void
    {
        foreach ([null => 'AI_DEFAULT_PROVIDER non configuré.', 'mistral' => 'Fournisseur IA inconnu : mistral.'] as $name => $message) {
            config(['ai.default_provider' => $name === '' ? null : $name]);
            try {
                app(AiService::class)->provider();
                $this->fail('Exception attendue.');
            } catch (AiProviderException $e) {
                $this->assertSame('NOT_CONFIGURED', $e->errorCode);
                $this->assertSame($message, $e->getMessage());
            }
            $this->assertFalse(app(AiService::class)->isConfigured());
        }
    }

    public function test_the_model_comes_from_the_task_then_from_the_default_model(): void
    {
        $service = app(AiService::class);
        $this->assertNull($service->modelFor(AiTask::Ideas), 'Aucun modèle choisi.');
        $this->assertNull($service->modelFor(AiTask::Transcription));

        config(['ai.default_model' => 'modele-general', 'ai.tasks.ideas.model' => 'modele-general', 'ai.tasks.script.model' => 'modele-script']);
        $this->assertSame('modele-script', $service->modelFor(AiTask::Script));
        $this->assertSame('modele-general', $service->modelFor(AiTask::Ideas));
        $this->assertSame('modele-general', $service->modelFor(AiTask::ClipDetection), 'Tâche sans réglage propre : modèle par défaut.');
        $this->assertNull($service->modelFor(AiTask::Transcription), 'Modèle spécialisé : jamais le modèle de texte par défaut.');
        $this->assertNull($service->modelFor(AiTask::Image), 'Modèle spécialisé : jamais le modèle de texte par défaut.');
        config(['ai.tasks.transcription.model' => 'modele-audio', 'ai.tasks.image.model' => 'modele-image']);
        $this->assertSame('modele-audio', $service->modelFor(AiTask::Transcription));
        $this->assertSame('modele-image', $service->modelFor(AiTask::Image));
    }

    public function test_check_command_without_a_key_sends_nothing_and_is_not_an_error(): void
    {
        config(['ai.providers.openai.api_key' => null]);
        Http::fake();

        $this->artisan('ai:check')
            ->expectsOutputToContain('OPENAI_API_KEY non configurée.')
            ->expectsOutputToContain('Test réel non exécuté.')
            ->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_check_command_sends_one_minimal_request_and_writes_nothing(): void
    {
        Http::fake([self::URL => Http::response($this->ok())]);
        $tables = ['ai_generations', 'video_projects', 'posts', 'social_accounts', 'media'];
        $before = array_map(fn (string $t) => DB::table($t)->count(), $tables);

        $this->artisan('ai:check')
            ->expectsOutputToContain(AiCheckConnection::TEST_MODEL.' (modèle de test)')
            ->expectsOutputToContain('OK')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['model'] === AiCheckConnection::TEST_MODEL && $r['max_output_tokens'] === 16 && $r['store'] === false
            && $r['input'] === 'Réponds uniquement par le mot OK.' && ! isset($r['instructions']));
        $this->assertSame($before, array_map(fn (string $t) => DB::table($t)->count(), $tables), 'Aucune écriture en base.');
    }

    public function test_check_command_uses_the_configured_or_requested_model(): void
    {
        Http::fake([self::URL => Http::response($this->ok())]);

        config(['ai.default_model' => 'modele-configure']);
        $this->artisan('ai:check')->assertSuccessful();
        $this->artisan('ai:check', ['--model' => 'modele-option'])->assertSuccessful();

        $models = Http::recorded()->map(fn ($pair) => $pair[0]['model'])->all();
        $this->assertSame(['modele-configure', 'modele-option'], $models);
    }

    public function test_check_command_reports_failures_without_revealing_the_key(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'Incorrect API key provided: '.self::KEY, 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 401)]);

        $exit = Artisan::call('ai:check');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('AUTHENTICATION_FAILED', $output);
        $this->assertStringContainsString('HTTP 401, invalid_api_key', $output);
        $this->assertStringNotContainsString(self::KEY, $output);
        $this->assertStringNotContainsString('Bearer', $output);
    }
}
