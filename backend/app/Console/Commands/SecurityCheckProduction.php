<?php

namespace App\Console\Commands;

use App\Support\Security\ProductionReadiness;
use Illuminate\Console\Command;

/**
 * Contrôle de la configuration de production (ADR-072) : à lancer sur le serveur avant chaque mise en ligne
 * (après « php artisan config:cache »). Échoue (code 1) si une exigence de sécurité n'est pas remplie ; --strict
 * fait aussi échouer les avertissements. N'affiche jamais de valeur secrète ; aucune modification.
 */
class SecurityCheckProduction extends Command
{
    protected $signature = 'security:check-production {--strict : les avertissements font aussi échouer le contrôle} {--json : sortie JSON}';

    protected $description = 'Vérifie la configuration de sécurité de production (sans afficher de secret)';

    public function handle(ProductionReadiness $readiness): int
    {
        $checks = $readiness->checks();
        $failed = array_filter($checks, fn ($c) => $c['status'] === ProductionReadiness::FAIL);
        $warned = array_filter($checks, fn ($c) => $c['status'] === ProductionReadiness::WARN);
        $passed = $failed === [] && (! $this->option('strict') || $warned === []);

        if ($this->option('json')) {
            $this->line((string) json_encode(['passed' => $passed, 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $check) {
            $status = match ($check['status']) {
                ProductionReadiness::OK => '<fg=green>OK</>',
                ProductionReadiness::WARN => '<fg=yellow>ATTENTION</>',
                default => '<fg=red>ÉCHEC</>',
            };
            $this->components->twoColumnDetail($check['label'], $status);
            if ($check['message'] !== '') {
                $this->line('    '.$check['message']);
            }
        }
        $this->newLine();
        $summary = sprintf('%d contrôle(s) : %d échec(s), %d avertissement(s).', count($checks), count($failed), count($warned));
        $passed ? $this->components->info($summary) : $this->components->error($summary);

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
