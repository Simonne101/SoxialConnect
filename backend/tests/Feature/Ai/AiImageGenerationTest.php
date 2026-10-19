<?php

namespace Tests\Feature\Ai;

use App\Jobs\GenerateAiImageJob;
use App\Models\AiGeneration;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Media\Concerns\MediaFixtures;
use Tests\TestCase;

/**
 * Génération d'images par IA (ADR-046) : fournisseur simulé (OpenAI Images, aucune requête réelle),
 * recadrage au format choisi, enregistrement par le chemin normal de la médiathèque.
 */
class AiImageGenerationTest extends TestCase
{
    use MediaFixtures, RefreshDatabase;

    private const KEY = 'sk-test-cle-fictive-ne-doit-jamais-sortir';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('media');
        Http::preventStrayRequests();
        config([
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => self::KEY,
            'ai.tasks.image.model' => 'image-test',
        ]);
        $this->user = User::factory()->create();
    }

    private function api(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->user)->fromSpa();
    }

    /** Réponse OpenAI Images simulée : PNG réel de la taille demandée. */
    private function fakeProvider(): void
    {
        Http::fake(['api.openai.com/v1/images/generations' => function ($request) {
            [$width, $height] = array_map('intval', explode('x', $request['size']));
            $image = imagecreatetruecolor($width, $height);
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 79, 70, 229));
            ob_start();
            imagepng($image);

            return Http::response(['created' => 1, 'data' => [['b64_json' => base64_encode((string) ob_get_clean())]], 'usage' => ['input_tokens' => 30, 'output_tokens' => 4000]]);
        }]);
    }

    private function generate(string $ratio, string $prompt = 'Une image moderne et professionnelle sur l\'intelligence artificielle'): AiGeneration
    {
        $id = $this->api()->postJson('/api/v1/ai/images', ['prompt' => $prompt, 'aspect_ratio' => $ratio])
            ->assertStatus(202)->assertJsonPath('data.status', 'pending')->json('data.id');
        Queue::assertPushed(GenerateAiImageJob::class, fn ($job) => $job->generationId === $id && $job->queue === 'ai');
        app()->call([new GenerateAiImageJob($id), 'handle']);

        return AiGeneration::findOrFail($id);
    }

    /** @return array<string, array{0: string, 1: string, 2: array{0: int, 1: int}, 3: array{0: int, 1: int}}> */
    public static function formats(): array
    {
        return [
            '1:1' => ['1:1', '1024x1024', [1024, 1024], [1024, 1024]],
            '4:5' => ['4:5', '1024x1536', [1024, 1536], [1024, 1280]],
            '9:16' => ['9:16', '1024x1536', [1024, 1536], [864, 1536]],
            '16:9' => ['16:9', '1536x1024', [1536, 1024], [1536, 864]],
        ];
    }

    /**
     * @param  array{0: int, 1: int}  $generated
     * @param  array{0: int, 1: int}  $final
     */
    #[DataProvider('formats')]
    public function test_an_image_is_generated_in_each_format_and_saved_to_the_media_library(string $ratio, string $size, array $generated, array $final): void
    {
        $this->requiresGd();
        $this->fakeProvider();

        $generation = $this->generate($ratio);

        $this->assertSame('succeeded', $generation->status->value, (string) $generation->error);
        Http::assertSent(fn ($request) => $request['size'] === $size && $request['model'] === 'image-test' && $request->hasHeader('Authorization', 'Bearer '.self::KEY));
        $media = Media::findOrFail($generation->output['media_id']);
        $this->assertSame($this->user->id, $media->user_id);
        $this->assertSame('image', $media->type->value);
        $this->assertSame($final, [$media->width, $media->height], 'Recadrage au ratio exact.');
        $this->assertSame('ai_image', $media->metadata['source']);
        $this->assertSame($ratio, $media->metadata['aspect_ratio']);
        $this->assertSame($generated, [$media->metadata['generated_width'], $media->metadata['generated_height']], 'Dimensions réellement générées conservées.');
        $this->assertSame('image-test', $media->metadata['model']);
        $this->assertTrue($media->isUsable());

        $this->api()->getJson("/api/v1/ai/images/{$generation->id}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.width', $final[0])->assertJsonPath('data.height', $final[1])
            ->assertJsonPath('data.media.id', $media->id);
        $this->api()->getJson('/api/v1/media')->assertOk()->assertJsonPath('data.0.id', $media->id);
    }

    public function test_provider_errors_fail_cleanly_without_media(): void
    {
        Http::fake(['api.openai.com/v1/images/generations' => Http::response(['error' => ['code' => 'moderation_blocked', 'message' => 'Rejected for '.self::KEY]], 400)]);

        $generation = $this->generate('1:1');

        $this->assertSame('failed', $generation->status->value);
        $this->assertStringStartsWith('CONTENT_REJECTED', (string) $generation->error);
        $this->assertStringNotContainsString(self::KEY, (string) $generation->error);
        $this->assertDatabaseCount('media', 0);
        $this->api()->getJson("/api/v1/ai/images/{$generation->id}")->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'CONTENT_REJECTED')->assertJsonPath('data.media', null);
    }

    public function test_billing_errors_and_invalid_responses_fail_cleanly(): void
    {
        Http::fake(['api.openai.com/v1/images/generations' => Http::sequence()
            ->push(['error' => ['code' => 'credit_balance_exhausted']], 429)
            ->push(['data' => [['url' => 'https://exemple.test/image.png']]], 200)]);

        $this->assertStringStartsWith('QUOTA_EXCEEDED', (string) $this->generate('1:1')->error);
        $this->assertStringStartsWith('INVALID_RESPONSE', (string) $this->generate('16:9')->error);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_requests_are_validated_and_refused_when_not_configured(): void
    {
        $this->api()->postJson('/api/v1/ai/images', ['prompt' => '', 'aspect_ratio' => '1:1'])->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->api()->postJson('/api/v1/ai/images', ['prompt' => 'Un visuel', 'aspect_ratio' => '3:2'])->assertUnprocessable()->assertJsonValidationErrors('aspect_ratio');
        $this->api()->postJson('/api/v1/ai/images', ['prompt' => str_repeat('a', 2001), 'aspect_ratio' => '1:1'])->assertUnprocessable()->assertJsonValidationErrors('prompt');

        config(['ai.tasks.image.model' => null]);
        $this->api()->postJson('/api/v1/ai/images', ['prompt' => 'Un visuel', 'aspect_ratio' => '1:1'])
            ->assertStatus(503)->assertJsonPath('code', 'AI_NOT_CONFIGURED');
        $this->api()->getJson('/api/v1/ai/images')->assertOk()->assertJsonPath('meta.configured', false);

        $this->assertDatabaseCount('ai_generations', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_generations_of_other_accounts_are_not_found(): void
    {
        $this->requiresGd();
        $this->fakeProvider();
        $generation = $this->generate('1:1');
        $other = User::factory()->create();

        $this->api($other)->getJson("/api/v1/ai/images/{$generation->id}")->assertNotFound();
        $this->api($other)->getJson('/api/v1/ai/images')->assertOk()->assertJsonCount(0, 'data');
        $this->api()->getJson('/api/v1/ai/images')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_duplicated_job_does_not_generate_twice(): void
    {
        $this->requiresGd();
        $this->fakeProvider();
        $generation = $this->generate('1:1');

        app()->call([new GenerateAiImageJob($generation->id), 'handle']);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('media', 1);
    }
}
