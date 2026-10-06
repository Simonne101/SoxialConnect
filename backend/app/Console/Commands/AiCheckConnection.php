<?php

namespace App\Console\Commands;

use App\Domain\AI\AiService;
use App\Domain\AI\Exceptions\AiProviderException;
use Illuminate\Console\Command;

/**
 * Test réel de la connexion au fournisseur IA (ADR-043), séparé des tests automatisés (qui
 * simulent toujours HTTP). Une requête minimale (16 tokens au plus), sans contenu utilisateur et
 * sans aucune écriture en base. Sans clé configurée, rien n'est envoyé et ce n'est pas une erreur.
 *
 *   php artisan ai:check                 modèle AI_DEFAULT_MODEL, sinon modèle de test
 *   php artisan ai:check --model=<id>    modèle donné (test uniquement)
 */
class AiCheckConnection extends Command
{
    /**
     * Modèle utilisé pour ce test quand AI_DEFAULT_MODEL est vide : le plus économique de la liste
     * officielle OpenAI au 2026-10-06. Modèle de TEST uniquement : aucune tâche ne l'utilise.
     */
    public const TEST_MODEL = 'gpt-6-luna';

    protected $signature = 'ai:check {--model= : Modèle à utiliser pour ce test (sinon AI_DEFAULT_MODEL, puis '.self::TEST_MODEL.')}';

    protected $description = 'Vérifie la connexion au fournisseur IA par une requête minimale (aucune donnée enregistrée)';

    public function handle(AiService $ai): int
    {
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
}
