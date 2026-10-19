<?php

namespace App\Domain\AI\Images;

use App\Domain\AI\AiGenerationLog;
use App\Domain\AI\AiService;
use App\Domain\AI\Data\AiImageRequest;
use App\Domain\AI\Enums\AiCapability;
use App\Domain\AI\Enums\AiGenerationStatus;
use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\Media\Actions\UploadMedia;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Exceptions\MediaScanUnavailable;
use App\Jobs\GenerateAiImageJob;
use App\Models\AiGeneration;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Génération d'images par IA (ADR-046) :
 * demande (génération « pending » dans ai_generations) → GenerateAiImageJob (file « ai ») :
 * fournisseur IA configuré → recadrage centré au format choisi (GD) → import par UploadMedia, le
 * chemin normal de la médiathèque (contrôles, antivirus, stockage privé, traitement, vignettes) →
 * métadonnées d'origine (fournisseur, modèle, format, dimensions réellement générées).
 */
final class AiImageService
{
    public function __construct(
        private readonly AiService $ai,
        private readonly AiGenerationLog $log,
        private readonly UploadMedia $upload,
    ) {}

    /** Fournisseur et modèle d'image configurés (aucun appel réseau). */
    public function isConfigured(): bool
    {
        return $this->ai->supportsFor(AiTask::Image, AiCapability::Image);
    }

    /** @throws AiProviderException fournisseur ou modèle non configuré */
    public function request(User $user, string $prompt, ImageFormat $format): AiGeneration
    {
        $model = $this->ai->modelFor(AiTask::Image);
        if ($model === null || ! $this->ai->supportsFor(AiTask::Image, AiCapability::Image)) {
            throw AiProviderException::notConfigured('Génération d\'images non configurée.');
        }
        $provider = $this->ai->providerFor(AiTask::Image)->name();

        return DB::transaction(function () use ($user, $prompt, $format, $model, $provider) {
            $generation = $this->log->start($user->getKey(), AiTask::Image, $provider, $model, ['prompt' => $prompt, 'aspect_ratio' => $format->value]);
            $generation->forceFill(['status' => AiGenerationStatus::Pending])->save();
            GenerateAiImageJob::dispatch($generation->getKey())->afterCommit();

            return $generation;
        });
    }

    public function run(string $generationId): void
    {
        $generation = DB::transaction(function () use ($generationId) {
            $locked = AiGeneration::query()->whereKey($generationId)->where('task', AiTask::Image->value)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== AiGenerationStatus::Pending) {
                return null; // déjà traitée : un job dupliqué ne fait rien
            }
            $locked->forceFill(['status' => AiGenerationStatus::Processing])->save();

            return $locked;
        });
        if ($generation === null) {
            return;
        }

        $file = null;
        try {
            $format = ImageFormat::from((string) ($generation->input['aspect_ratio'] ?? ''));
            $result = $this->ai->generateImage(new AiImageRequest($generation->model, (string) $generation->input['prompt'], $format->providerSize(), $format->value));
            [$file, $size] = $this->crop($result->bytes, $format, $generation->getKey());

            $user = User::query()->findOrFail($generation->user_id);
            $name = 'Image IA — '.Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $generation->input['prompt']) ?? ''), 60, '').'.png';
            $media = $this->upload->handle($user, new UploadedFile($file, $name, 'image/png', null, true));
            $media->forceFill(['metadata' => [
                ...($media->metadata ?? []),
                'source' => 'ai_image',
                'ai_generation_id' => $generation->getKey(),
                'provider' => $result->provider,
                'model' => $result->model,
                'aspect_ratio' => $format->value,
                'generated_width' => $size['generated'][0],
                'generated_height' => $size['generated'][1],
            ]])->save();

            $this->log->succeed($generation, [
                'media_id' => $media->getKey(),
                'width' => $size['final'][0],
                'height' => $size['final'][1],
                'generated_width' => $size['generated'][0],
                'generated_height' => $size['generated'][1],
            ], $result->latencyMs, $result->inputTokens, $result->outputTokens, $result->model);
        } catch (AiProviderException $e) {
            $this->log->fail($generation, $e->errorCode, $e->getMessage());
        } catch (ImageGenerationFailure|MediaRejected|MediaScanUnavailable $e) {
            $this->log->fail($generation, 'IMAGE_REJECTED', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('ai.image_failed', ['generation_id' => $generation->getKey(), 'exception' => $e::class]);
            $this->log->fail($generation, 'IMAGE_FAILED', 'La génération de l\'image a échoué de façon inattendue.');
        } finally {
            if ($file !== null) {
                File::delete($file);
            }
        }
    }

    /** Média produit par une génération réussie (null sinon ou s'il a été supprimé). */
    public function outputMedia(AiGeneration $generation): ?Media
    {
        $id = $generation->output['media_id'] ?? null;

        return is_string($id) ? Media::query()->where('user_id', $generation->user_id)->whereKey($id)->first() : null;
    }

    /**
     * Recadrage centré au ratio exact du format, encodé en PNG dans un fichier temporaire privé.
     *
     * @return array{0: string, 1: array{generated: array{0: int, 1: int}, final: array{0: int, 1: int}}}
     *
     * @throws ImageGenerationFailure
     */
    private function crop(string $bytes, ImageFormat $format, string $generationId): array
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            throw new ImageGenerationFailure('Le traitement des images (extension GD) n\'est pas disponible sur le serveur.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ImageGenerationFailure('Le fournisseur IA a renvoyé une image illisible.');
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $box = $format->cropBox($width, $height);
        $cropped = ($box['width'] === $width && $box['height'] === $height) ? $image : imagecrop($image, $box);
        if ($cropped === false) {
            throw new ImageGenerationFailure('Le recadrage de l\'image a échoué.');
        }

        $dir = storage_path('app/private/tmp/ai-images');
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.$generationId.'.png';
        if (! imagepng($cropped, $path)) {
            throw new ImageGenerationFailure('L\'image n\'a pas pu être enregistrée.');
        }

        return [$path, ['generated' => [$width, $height], 'final' => [imagesx($cropped), imagesy($cropped)]]];
    }
}
