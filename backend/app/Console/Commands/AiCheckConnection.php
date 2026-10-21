<?php

namespace App\Console\Commands;

use App\Domain\AI\AiService;
use App\Domain\AI\Data\AiTextRequest;
use App\Domain\AI\Enums\AiCapability;
use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\Video\Clipper\Analysis\ClipAnalyzerResolver;
use App\Domain\Video\Clipper\Transcription\TranscriberResolver;
use App\Domain\Video\Clipper\Transcription\WhisperCppTranscriber;
use Illuminate\Console\Command;

/**
 * Test réel de la connexion au fournisseur IA (ADR-043), séparé des tests automatisés (qui
 * simulent toujours HTTP). Une requête minimale (16 tokens au plus), sans contenu utilisateur et
 * sans aucune écriture en base. Sans clé configurée, rien n'est envoyé et ce n'est pas une erreur.
 *
 *   php artisan ai:check                 modèle AI_DEFAULT_MODEL, sinon modèle de test
 *   php artisan ai:check --model=<id>    modèle donné (test uniquement)
 *   php artisan ai:check --tasks         configuration par tâche (fournisseur, modèle, capacité),
 *                                        transcription locale ; AUCUN appel réseau
 *   php artisan ai:check --tasks --live  + un appel texte minimal pour l'analyse (16 tokens) ;
 *                                        jamais d'appel de transcription ni d'image (payants)
 */
class AiCheckConnection extends Command
{
    /**
     * Modèle utilisé pour ce test quand AI_DEFAULT_MODEL est vide : le plus économique de la liste
     * officielle OpenAI au 2026-10-06. Modèle de TEST uniquement : aucune tâche ne l'utilise.
     */
    public const TEST_MODEL = 'gpt-6-luna';

    protected $signature = 'ai:check {--model= : Modèle à utiliser pour ce test (sinon AI_DEFAULT_MODEL, puis '.self::TEST_MODEL.')}
        {--tasks : Configuration par tâche du Creative Studio, sans appel réseau}
        {--live : Avec --tasks : appel texte minimal pour l\'analyse (jamais transcription ni image)}';

    protected $description = 'Vérifie la connexion au fournisseur IA par une requête minimale (aucune donnée enregistrée)';

    public function handle(AiService $ai): int
    {
        if ($this->option('tasks')) {
            return $this->tasks($ai);
        }

        try {
            $provider = $ai->provider();
        } catch (AiProviderException $e) {
            $this->components->error($e->getMessage().' Test réel non exécuté.');

            return self::FAILURE;
        }

        if (! $provider->isConfigured()) {
            $this->components->warn(strtoupper($provider->name()).'_API_KEY non configurée.');
            $this->components->info('Test réel non exécuté.');

            return self::SUCCESS;
        }

        $model = $this->option('model') ?: config('ai.default_model') ?: self::TEST_MODEL;
        $this->components->info("Fournisseur : {$provider->name()} — modèle : {$model}".($model === self::TEST_MODEL ? ' (modèle de test)' : ''));

        try {
            $result = $ai->checkConnection($model);
        } catch (AiProviderException $e) {
            $detail = implode(', ', array_filter([
                $e->httpStatus ? "HTTP {$e->httpStatus}" : null,
                $e->providerCode,
            ]));
            $this->components->error("Échec ({$e->errorCode}) : {$e->getMessage()}".($detail !== '' ? " [{$detail}]" : ''));

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Connexion', '<fg=green>OK</>');
        $this->components->twoColumnDetail('Modèle utilisé', $result->model);
        $this->components->twoColumnDetail('Réponse', $result->complete ? 'complète' : "incomplète ({$result->incompleteReason})");
        $this->components->twoColumnDetail('Texte', $result->text !== '' ? mb_substr($result->text, 0, 40) : '—');
        $this->components->twoColumnDetail('Tokens (entrée / sortie)', ($result->inputTokens ?? '?').' / '.($result->outputTokens ?? '?'));
        $this->components->twoColumnDetail('Latence', "{$result->latencyMs} ms");

        return self::SUCCESS;
    }

    /**
     * Configuration réelle par tâche (ADR-048) : fournisseur, modèle, clé, capacité. Avec --live,
     * un seul appel texte minimal (analyse) ; erreurs normalisées, jamais de secret affiché.
     */
    private function tasks(AiService $ai): int
    {
        $rows = [
            ['Analyse des passages', AiTask::VideoAnalysis, AiCapability::Text],
            ['Analyse depuis un lien YouTube', AiTask::VideoAnalysis, AiCapability::VideoUrl],
            ['Transcription', AiTask::Transcription, AiCapability::Transcription],
            ['Images', AiTask::Image, AiCapability::Image],
        ];
        foreach ($rows as [$label, $task, $capability]) {
            $this->components->twoColumnDetail("<options=bold>{$label}</>");
            // Analyse par lien : fournisseur dédié possible (AI_VIDEO_URL_ANALYSIS_PROVIDER).
            $provider = $capability === AiCapability::VideoUrl ? $ai->urlProviderNameFor($task) : $ai->providerNameFor($task);
            $model = $ai->modelFor($task, $provider);
            try {
                $instance = $provider !== null ? $ai->provider($provider) : null;
            } catch (AiProviderException) {
                $instance = null;
            }
            $status = match (true) {
                $instance === null => '<fg=yellow>NON CONFIGURÉ</> (fournisseur absent ou inconnu)',
                ! $instance->supports($capability) => '<fg=yellow>NON PRIS EN CHARGE</> par ce fournisseur',
                ! $instance->isConfigured() => '<fg=yellow>NON CONFIGURÉ</> (clé API absente)',
                $model === null => '<fg=yellow>NON CONFIGURÉ</> (modèle absent)',
                default => '<fg=green>CONFIGURÉ</>',
            };
            $this->components->twoColumnDetail('  Fournisseur', $provider ?? '—');
            $this->components->twoColumnDetail('  Modèle', $model ?? '—');
            $this->components->twoColumnDetail('  État', $status);
        }

        $whisper = app(WhisperCppTranscriber::class);
        $this->components->twoColumnDetail('<options=bold>Clipper</>');
        $this->components->twoColumnDetail('  Transcription locale (whisper.cpp)', $whisper->isInstalled() ? '<fg=green>INSTALLÉE</> ('.$whisper->model().')' : 'non installée');
        $this->components->twoColumnDetail('  Moteur de transcription utilisé', app(TranscriberResolver::class)->resolve()?->engine() ?? 'aucun (passage manuel)');
        $this->components->twoColumnDetail('  Analyse utilisée', match (app(ClipAnalyzerResolver::class)->primaryMode()) {
            'ai' => 'IA (repli : analyse locale)',
            'local' => 'analyse locale (sans IA)',
            default => 'aucune (passage manuel)',
        });

        if (! $this->option('live')) {
            $this->components->info('Configuration seule : aucun appel réseau. Ajoutez --live pour tester l\'analyse (appel texte minimal).');

            return self::SUCCESS;
        }
        if (! $ai->supportsFor(AiTask::VideoAnalysis, AiCapability::Text)) {
            $this->components->warn('Analyse non configurée : aucun appel effectué.');

            return self::SUCCESS;
        }
        try {
            $result = $ai->generateText(new AiTextRequest((string) $ai->modelFor(AiTask::VideoAnalysis), 'Réponds uniquement par le mot OK.', maxOutputTokens: 16));
            $this->components->twoColumnDetail('Connexion (analyse)', '<fg=green>OK</> — '.$result->provider.' / '.$result->model.' ('.$result->latencyMs.' ms)');
            $this->components->twoColumnDetail('Réponse', $result->complete
                ? 'complète — « '.mb_substr($result->text, 0, 40).' »'
                : '<fg=yellow>incomplète</> (budget de test de 16 tokens atteint, souvent par le raisonnement du modèle) : connexion confirmée, qualité non évaluée');
        } catch (AiProviderException $e) {
            $this->components->twoColumnDetail('Connexion (analyse)', "<fg=red>{$e->errorCode}</> — {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->components->info('Transcription et images non testées en réel : appels payants.');

        return self::SUCCESS;
    }
}
