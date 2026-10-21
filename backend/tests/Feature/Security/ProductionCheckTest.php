<?php

namespace Tests\Feature\Security;

use App\Domain\Media\Processing\ImageProcessor;
use App\Domain\Media\Security\MediaSecurityScanner;
use App\Support\Security\ProductionReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Contrôle de configuration de production (étape 8, lot 3 — ADR-072) : exigences vérifiées, aucun secret affiché,
 * cookie « Secure » par défaut en production, proxys de confiance lus dans la configuration (compatibles avec
 * « config:cache »), capacité réelle de réencodage des images.
 */
class ProductionCheckTest extends TestCase
{
    /** Configuration de production conforme (hors antivirus et GD, qui dépendent du serveur). */
    private function productionConfig(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://api.example.com', 'app.frontend_url' => 'https://app.example.com',
            'logging.default' => 'stack', 'logging.channels.stack.channels' => ['json'], 'logging.channels.json.level' => 'info',
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax', 'session.encrypt' => true, 'session.driver' => 'database',
            'cors.allowed_origins' => ['https://app.example.com'], 'cors.allowed_origins_patterns' => [], 'cors.supports_credentials' => true,
            'sanctum.stateful' => ['app.example.com'], 'security.trusted_proxies' => ['10.0.0.5'],
            'security.token_encryption.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.telegram.bot_token' => null, 'queue.default' => 'redis', 'cache.default' => 'redis', 'mail.default' => 'smtp',
            'media.scan.required' => true, 'quotas.storage.max_mb' => 10240, 'quotas.disk.enabled' => true,
        ]);
    }

    /** @return array<string, array{status: string, message: string}> */
    private function report(array $options = []): array
    {
        $this->artisan('security:check-production', ['--json' => true, ...$options]);

        return collect(app(ProductionReadiness::class)->checks())->keyBy('id')->all();
    }

    public function test_the_local_configuration_fails_with_explicit_reasons_and_no_secret_is_ever_printed(): void
    {
        config(['app.debug' => true, 'session.secure' => false, 'cors.allowed_origins' => ['http://localhost:5173'], 'sanctum.stateful' => ['localhost:5173', 'tunnel.ngrok-free.dev']]);

        $exit = $this->withoutMockingConsoleOutput()->artisan('security:check-production');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        foreach (['APP_DEBUG=true', 'SESSION_SECURE_COOKIE', 'CORS_ALLOWED_ORIGINS', 'SANCTUM_STATEFUL_DOMAINS', 'APP_ENV'] as $reason) {
            $this->assertStringContainsString($reason, $output);
        }
        foreach ([(string) config('app.key'), (string) config('security.token_encryption.key')] as $secret) {
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, $output, 'Aucune valeur secrète affichée.');
        }
    }

    public function test_a_compliant_production_configuration_only_depends_on_the_server_antivirus_and_gd(): void
    {
        $this->productionConfig();

        $checks = $this->report();

        $failing = collect($checks)->reject(fn ($c) => $c['status'] === ProductionReadiness::OK)->keys()->all();
        $this->assertSame([], array_values(array_diff($failing, ['media.scanner', 'media.gd', 'media.reencode', 'php.exif', 'php.opcache'])),
            'Seuls l\'antivirus joignable, GD, exif et OPcache (php.ini du serveur) peuvent encore manquer.');
        $this->assertSame(ProductionReadiness::FAIL, $checks['media.scanner']['status'], 'Aucun moteur antivirus branché : échec fermé signalé.');
    }

    public function test_each_requirement_is_detected(): void
    {
        $this->productionConfig();
        config([
            'app.url' => 'http://api.example.com', 'queue.default' => 'sync', 'cache.default' => 'array',
            'cors.allowed_origins' => ['*'], 'security.token_encryption.key' => (string) config('app.key'),
            'services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => null, 'media.scan.required' => false,
            'session.same_site' => 'none', 'security.trusted_proxies' => ['127.0.0.1', '::1'],
        ]);

        $checks = $this->report();

        foreach (['https.app_url', 'queue', 'cache', 'cors.origins', 'secrets.token_key_distinct', 'secrets.telegram_webhook', 'media.scan_required', 'session.same_site'] as $id) {
            $this->assertSame(ProductionReadiness::FAIL, $checks[$id]['status'], $id);
        }
        $this->assertSame(ProductionReadiness::WARN, $checks['proxies']['status'], 'Proxy local seulement : avertissement.');
    }

    public function test_strict_mode_also_fails_on_warnings_and_json_output_is_machine_readable(): void
    {
        $this->productionConfig();
        config(['media.scan.required' => true, 'mail.default' => 'log']);
        $this->app->instance(ProductionReadiness::class, new class(app(ImageProcessor::class), app(MediaSecurityScanner::class)) extends ProductionReadiness
        {
            public function checks(): array
            {
                return array_map(fn ($c) => $c['id'] === 'mail' ? $c : [...$c, 'status' => self::OK, 'message' => ''], parent::checks());
            }
        });

        $this->artisan('security:check-production')->assertSuccessful(); // avertissement seul : accepté
        $this->artisan('security:check-production', ['--strict' => true])->assertFailed();
        $this->artisan('security:check-production', ['--json' => true])->expectsOutputToContain('"passed":true')->assertSuccessful();
    }

    public function test_the_session_cookie_is_secure_by_default_in_production_only(): void
    {
        $load = function (string $env): mixed {
            $backup = [$_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null, getenv('APP_ENV'), $_ENV['SESSION_SECURE_COOKIE'] ?? null, $_SERVER['SESSION_SECURE_COOKIE'] ?? null, getenv('SESSION_SECURE_COOKIE')];
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;
            putenv("APP_ENV={$env}");
            unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
            putenv('SESSION_SECURE_COOKIE');
            try {
                return (require base_path('config/session.php'))['secure'];
            } finally {
                [$_ENV['APP_ENV'], $_SERVER['APP_ENV']] = [$backup[0], $backup[1]];
                $backup[2] === false ? putenv('APP_ENV') : putenv('APP_ENV='.$backup[2]);
                if ($backup[3] !== null) {
                    $_ENV['SESSION_SECURE_COOKIE'] = $backup[3];
                }
                if ($backup[4] !== null) {
                    $_SERVER['SESSION_SECURE_COOKIE'] = $backup[4];
                }
                if ($backup[5] !== false) {
                    putenv('SESSION_SECURE_COOKIE='.$backup[5]);
                }
            }
        };

        $this->assertTrue($load('production'), 'Production : cookie Secure même si SESSION_SECURE_COOKIE est absent.');
        $this->assertFalse($load('local'), 'Développement HTTP local : non imposé.');
    }

    /** Appel isolé (une requête par test : le client de test réutilise le schéma de la requête précédente). */
    private function proxied(string $remote): array
    {
        Route::get('/_probe/secure', fn () => response()->json(['secure' => request()->isSecure(), 'ip' => request()->ip()]));

        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])->getJson('/_probe/secure')->json();
    }

    public function test_a_configured_reverse_proxy_is_trusted(): void
    {
        config(['security.trusted_proxies' => ['10.0.0.5']]);

        $this->assertSame(['secure' => true, 'ip' => '203.0.113.7'], $this->proxied('10.0.0.5'), 'Reverse proxy déclaré : HTTPS et IP cliente reconnus.');
    }

    public function test_forwarded_headers_from_an_undeclared_address_are_ignored(): void
    {
        config(['security.trusted_proxies' => ['10.0.0.5']]);

        $this->assertSame(['secure' => false, 'ip' => '10.0.0.9'], $this->proxied('10.0.0.9'));
    }

    public function test_a_wildcard_is_never_accepted_as_trusted_proxy(): void
    {
        config(['security.trusted_proxies' => ['*']]);

        $this->assertSame(['secure' => false, 'ip' => '10.0.0.9'], $this->proxied('10.0.0.9'), '« * » refusé : jamais de confiance universelle.');
    }

    public function test_image_reencoding_is_verified_for_real(): void
    {
        $checks = $this->report();

        if (extension_loaded('gd')) {
            $this->assertSame(ProductionReadiness::OK, $checks['media.reencode']['status'], 'GD présente : un JPEG avec EXIF est réellement réencodé sans métadonnées.');
        } else {
            $this->assertSame(ProductionReadiness::FAIL, $checks['media.gd']['status']);
            $this->assertSame(ProductionReadiness::FAIL, $checks['media.reencode']['status']);
            $this->assertStringContainsString('EXIF', $checks['media.gd']['message']);
        }
    }
}
