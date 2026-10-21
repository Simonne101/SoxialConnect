<?php

namespace App\Domain\AI;

use App\Domain\AI\Contracts\AiProvider;
use App\Domain\AI\Data\AiImageRequest;
use App\Domain\AI\Data\AiImageResult;
use App\Domain\AI\Data\AiTextRequest;
use App\Domain\AI\Data\AiTextResult;
use App\Domain\AI\Data\AiTranscriptionRequest;
use App\Domain\AI\Data\AiTranscriptionResult;
use App\Domain\AI\Enums\AiCapability;
use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\Providers\GeminiProvider;
use App\Domain\AI\Providers\OpenAiProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Point d'entrée IA de SoxialConnect (ADR-043) : choix du fournisseur et du modèle selon
 * config/ai.php. Le reste de l'application ne connaît que ce service et les DTO.
 *
 * Utilisé par le YouTube Clipper (transcription, analyse) et la génération d'images (ADR-046) ;
 * l'enregistrement dans ai_generations est fait par ces fonctionnalités.
 */
final class AiService
{
    /** Fournisseurs disponibles : nom dans config/ai.php → implémentation. */
    private const PROVIDERS = [
        OpenAiProvider::NAME => OpenAiProvider::class,
        GeminiProvider::NAME => GeminiProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @throws AiProviderException fournisseur absent ou inconnu */
    public function provider(?string $name = null): AiProvider
    {
        $name ??= config('ai.default_provider');
        if (! is_string($name) || $name === '') {
            throw AiProviderException::notConfigured('AI_DEFAULT_PROVIDER non configuré.');
        }
        $class = self::PROVIDERS[$name] ?? null;
        if ($class === null) {
            throw AiProviderException::notConfigured("Fournisseur IA inconnu : {$name}.");
        }

        return $this->container->make($class);
    }

    /**
     * Fournisseur d'une tâche : ai.tasks.<tâche>.provider (ex. AI_IMAGE_PROVIDER), sinon le fournisseur
     * par défaut. Permet d'ajouter un fournisseur par usage sans changer les appelants.
     *
     * @throws AiProviderException
     */
    public function providerFor(AiTask $task, ?string $provider = null): AiProvider
    {
        return $this->provider($provider ?? $this->providerNameFor($task));
    }

    /** Nom du fournisseur d'une tâche (réglage de la tâche, sinon fournisseur par défaut). */
    public function providerNameFor(AiTask $task): ?string
    {
        $name = config("ai.tasks.{$task->value}.provider");
        $name = is_string($name) && $name !== '' ? $name : config('ai.default_provider');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Fournisseur de l'analyse d'une vidéo par son LIEN (capacité VideoUrl, ex. Gemini) : réglage dédié
     * ai.tasks.video_analysis.url_provider (AI_VIDEO_URL_ANALYSIS_PROVIDER), sinon celui de la tâche. Permet
     * d'analyser les transcriptions avec un autre fournisseur (ex. OpenAI) que celui qui regarde la vidéo.
     */
    public function urlProviderNameFor(AiTask $task): ?string
    {
        $name = config("ai.tasks.{$task->value}.url_provider");

        return is_string($name) && $name !== '' ? $name : $this->providerNameFor($task);
    }

    /** Vrai si un fournisseur est choisi et que ses réglages (clé) sont présents. Aucun appel réseau. */
    public function isConfigured(): bool
    {
        try {
            return $this->provider()->isConfigured();
        } catch (AiProviderException) {
            return false;
        }
    }

    /** Fournisseur ET modèle de la tâche configurés (aucun appel réseau). */
    public function isConfiguredFor(AiTask $task): bool
    {
        try {
            return $this->providerFor($task)->isConfigured() && $this->modelFor($task) !== null;
        } catch (AiProviderException) {
            return false;
        }
    }

    /**
     * Modèle d'une tâche, tel que réglé dans config/ai.php (tâches de texte : AI_MODEL_<TÂCHE>, sinon
     * AI_DEFAULT_MODEL ; transcription et image : leur seule variable, jamais un modèle de texte).
     * Tâche sans réglage propre : AI_DEFAULT_MODEL. Null si aucun modèle.
     */
    public function modelFor(AiTask $task, ?string $provider = null): ?string
    {
        // Fournisseur doté de ses propres modèles (Gemini) : uniquement ceux-là, jamais un modèle d'un autre fournisseur.
        $provider ??= $this->providerNameFor($task);
        $own = $provider !== null ? config("ai.providers.{$provider}.models") : null;
        if (is_array($own)) {
            $model = $own[$task->value] ?? null;

            return is_string($model) && $model !== '' ? $model : null;
        }

        $tasks = (array) config('ai.tasks', []);
        $model = array_key_exists($task->value, $tasks) ? ($tasks[$task->value]['model'] ?? null) : config('ai.default_model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    /** Le fournisseur de la tâche, configuré, possède cette capacité (aucun appel réseau). */
    public function supportsFor(AiTask $task, AiCapability $capability, ?string $providerName = null): bool
    {
        try {
            $provider = $this->providerFor($task, $providerName);
        } catch (AiProviderException) {
            return false;
        }

        return $provider->isConfigured() && $provider->supports($capability) && $this->modelFor($task, $providerName) !== null;
    }

    /** @throws AiProviderException */
    public function generateText(AiTextRequest $request, AiTask $task = AiTask::VideoAnalysis, ?string $provider = null): AiTextResult
    {
        return $this->providerFor($task, $provider)->generateText($request);
    }

    /** @throws AiProviderException */
    public function transcribe(AiTranscriptionRequest $request): AiTranscriptionResult
    {
        return $this->providerFor(AiTask::Transcription)->transcribe($request);
    }

    /** @throws AiProviderException */
    public function generateImage(AiImageRequest $request): AiImageResult
    {
        return $this->providerFor(AiTask::Image)->generateImage($request);
    }

    /**
     * Vérification minimale de la connexion au fournisseur par défaut : une requête très courte,
     * sans contenu utilisateur, sans aucune écriture en base.
     *
     * @throws AiProviderException
     */
    public function checkConnection(string $model): AiTextResult
    {
        return $this->provider()->generateText(new AiTextRequest(
            model: $model,
            input: 'Réponds uniquement par le mot OK.',
            // Minimum accepté par l'API ; les modèles de raisonnement peuvent l'épuiser avant de
            // répondre (réponse « incomplete ») : la connexion est tout de même établie.
            maxOutputTokens: 16,
        ));
    }
}
