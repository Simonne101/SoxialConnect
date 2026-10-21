<?php

namespace App\Support\Security;

use App\Domain\Media\Processing\ImageProcessor;
use App\Domain\Media\Security\MediaSecurityScanner;
use Throwable;

/**
 * Contrôle de configuration de production (étape 8, lot 3 — ADR-072), sans réseau ni écriture hors d'un fichier
 * temporaire : chaque vérification renvoie un statut (ok, warn, fail) et un message qui ne contient JAMAIS de
 * valeur secrète (au plus le nom de la variable ou un domaine public).
 */
class ProductionReadiness
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        private readonly ImageProcessor $images,
        private readonly MediaSecurityScanner $scanner,
    ) {}

    /** @return list<array{id: string, label: string, status: string, message: string}> */
    public function checks(): array
    {
        return [
            ...$this->application(),
            ...$this->https(),
            ...$this->session(),
            ...$this->cors(),
            ...$this->sanctum(),
            $this->proxies(),
            ...$this->secrets(),
            ...$this->infrastructure(),
            ...$this->php(),
            ...$this->media(),
            ...$this->quotas(),
        ];
    }

    /** @return list<array<string, string>> */
    private function application(): array
    {
        return [
            $this->check('app.env', 'APP_ENV = production', config('app.env') === 'production', 'APP_ENV doit valoir « production » (en-têtes HSTS, analyse antivirus obligatoire, politique de mots de passe).'),
            $this->check('app.debug', 'APP_DEBUG désactivé', config('app.debug') === false, 'APP_DEBUG=true exposerait les traces et la configuration dans les erreurs.'),
            $this->check('app.key', 'APP_KEY défini', filled(config('app.key')), 'APP_KEY est vide : sessions et chiffrement impossibles (php artisan key:generate, sans jamais réutiliser la clé de dev).'),
            $this->check('log.level', 'Niveau de journal ≥ info', ! in_array(config('logging.channels.'.$this->firstLogChannel().'.level', config('logging.level')), ['debug'], true), 'LOG_LEVEL=debug : journaux verbeux (données personnelles, volume).', self::WARN),
        ];
    }

    /** @return list<array<string, string>> */
    private function https(): array
    {
        return [
            $this->check('https.app_url', 'APP_URL en HTTPS', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL doit commencer par https:// (URL signées, liens des e-mails).'),
            $this->check('https.frontend_url', 'FRONTEND_URL en HTTPS', str_starts_with((string) config('app.frontend_url'), 'https://'), 'FRONTEND_URL doit commencer par https:// (redirections OAuth, CORS).'),
        ];
    }

    /** @return list<array<string, string>> */
    private function session(): array
    {
        $sameSite = strtolower((string) config('session.same_site'));

        return [
            $this->check('session.secure', 'Cookie de session « Secure »', config('session.secure') === true, 'SESSION_SECURE_COOKIE doit valoir true : sans lui, le cookie de session peut circuler en clair.'),
            $this->check('session.http_only', 'Cookie de session « HttpOnly »', config('session.http_only') === true, 'SESSION_HTTP_ONLY doit valoir true (cookie inaccessible au JavaScript).'),
            $this->check('session.same_site', 'Cookie de session « SameSite » lax ou strict', in_array($sameSite, ['lax', 'strict'], true), 'SESSION_SAME_SITE doit valoir lax ou strict (protection CSRF).'),
            $this->check('session.encrypt', 'Session chiffrée', config('session.encrypt') === true, 'SESSION_ENCRYPT=true recommandé (contenu des sessions chiffré en base).', self::WARN),
            $this->check('session.driver', 'Stockage de session partagé', in_array(config('session.driver'), ['database', 'redis'], true), 'SESSION_DRIVER doit être database ou redis (révocation des sessions, plusieurs serveurs).', self::WARN),
        ];
    }

    /** @return list<array<string, string>> */
    private function cors(): array
    {
        $origins = (array) config('cors.allowed_origins', []);
        $local = array_filter($origins, fn ($o) => $this->isLocal((string) $o));
        $insecure = array_filter($origins, fn ($o) => ! str_starts_with((string) $o, 'https://'));

        return [
            $this->check('cors.origins', 'CORS : origines explicites en HTTPS', $origins !== [] && ! in_array('*', $origins, true) && $insecure === [] && $local === [] && (array) config('cors.allowed_origins_patterns', []) === [],
                'CORS_ALLOWED_ORIGINS doit lister uniquement l\'URL HTTPS du frontend (ni « * », ni motif, ni localhost).'),
            $this->check('cors.credentials', 'CORS : cookies autorisés', config('cors.supports_credentials') === true, 'supports_credentials doit valoir true pour le SPA à cookies (Sanctum).'),
        ];
    }

    /** @return list<array<string, string>> */
    private function sanctum(): array
    {
        $domains = array_values(array_filter(array_map('trim', (array) config('sanctum.stateful', []))));
        $frontendHost = (string) parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
        $hasFrontend = $frontendHost !== '' && in_array($frontendHost, array_map(fn ($d) => (string) preg_replace('/:\d+$/', '', $d), $domains), true);

        return [
            $this->check('sanctum.stateful', 'Sanctum : domaines de production uniquement', $domains !== [] && array_filter($domains, fn ($d) => $this->isLocal($d) || str_contains($d, 'ngrok')) === [],
                'SANCTUM_STATEFUL_DOMAINS ne doit contenir ni localhost, ni 127.0.0.1, ni tunnel de développement.'),
            $this->check('sanctum.frontend', 'Sanctum : domaine du frontend déclaré', $hasFrontend, 'Le domaine de FRONTEND_URL doit figurer dans SANCTUM_STATEFUL_DOMAINS (sinon les sessions du SPA échouent).', self::WARN),
        ];
    }

    /** @return array<string, string> */
    private function proxies(): array
    {
        $proxies = (array) config('security.trusted_proxies', []);

        return $this->check('proxies', 'Proxys de confiance déclarés', $proxies !== [] && array_diff($proxies, ['127.0.0.1', '::1']) !== [],
            'TRUSTED_PROXIES doit lister l\'adresse (ou la plage) du reverse proxy ; sinon IP clientes et HTTPS ne sont pas reconnus derrière lui.', self::WARN);
    }

    /** @return list<array<string, string>> */
    private function secrets(): array
    {
        $key = (string) config('security.token_encryption.key');
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $tokenKeyValid = $raw !== false && strlen((string) $raw) === 32;
        $botToken = filled(config('services.telegram.bot_token'));

        return [
            $this->check('secrets.token_key', 'Clé de chiffrement des jetons OAuth valide', $tokenKeyValid, 'TOKEN_ENCRYPTION_KEY doit être une clé de 32 octets (base64:…), propre à la production.'),
            $this->check('secrets.token_key_distinct', 'Clé des jetons distincte d\'APP_KEY', $key === '' || $key !== (string) config('app.key'), 'TOKEN_ENCRYPTION_KEY doit être différente d\'APP_KEY.'),
            $this->check('secrets.telegram_webhook', 'Webhook Telegram protégé', ! $botToken || filled(config('services.telegram.webhook_secret')), 'TELEGRAM_WEBHOOK_SECRET doit être défini quand le bot est configuré.'),
        ];
    }

    /** @return list<array<string, string>> */
    private function infrastructure(): array
    {
        return [
            $this->check('queue', 'Files asynchrones', config('queue.default') !== 'sync', 'QUEUE_CONNECTION=sync exécuterait publications et rendus dans les requêtes HTTP.'),
            $this->check('cache', 'Cache partagé (verrous des quotas)', config('cache.default') === 'redis', 'CACHE_STORE=redis requis : verrous des quotas, des jetons et de la publication partagés entre workers.'),
            $this->check('mail', 'Envoi réel des e-mails', ! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER=log ou array : les e-mails de vérification et de réinitialisation ne partent pas.', self::WARN),
        ];
    }

    /**
     * Environnement PHP (lot 3) : extensions réellement utilisées par l'application (GD est contrôlée avec le
     * réencodage des images). À vérifier dans la CLI (workers, planificateur) ET dans PHP-FPM (API).
     *
     * @return list<array<string, string>>
     */
    private function php(): array
    {
        $required = ['pdo_mysql', 'mbstring', 'openssl', 'intl', 'zip', 'fileinfo', 'ctype', 'curl', 'tokenizer', 'dom', 'xml'];
        $missing = array_values(array_filter($required, fn (string $extension) => ! extension_loaded($extension)));

        return [
            $this->check('php.version', 'PHP 8.3 ou plus récent', PHP_VERSION_ID >= 80300, 'PHP '.PHP_VERSION.' : la version 8.3 au minimum est requise (composer.json).'),
            $this->check('php.extensions', 'Extensions PHP requises', $missing === [], 'Extensions manquantes : '.implode(', ', $missing).' (base de données, chaînes, chiffrement, archives d\'export Google, détection MIME, client HTTP).'),
            $this->check('php.argon2id', 'Hachage Argon2id disponible', defined('PASSWORD_ARGON2ID'), 'PHP compilé sans Argon2 (libargon2 ou sodium) : HASH_DRIVER=argon2id impossible.'),
            $this->check('php.exif', 'Extension exif (orientation des photos)', extension_loaded('exif'), 'exif absente : les photos prises en portrait peuvent apparaître pivotées après réencodage.', self::WARN),
            $this->check('php.opcache', 'OPcache actif', extension_loaded('Zend OPcache') && (bool) ini_get('opcache.enable'.(PHP_SAPI === 'cli' ? '_cli' : '')), 'OPcache inactif : performances réduites (activer opcache dans PHP-FPM).', self::WARN),
        ];
    }

    /** @return list<array<string, string>> */
    private function media(): array
    {
        [$canReencode, $reason] = $this->imageReencoding();

        return [
            $this->check('media.scan_required', 'Analyse antivirus obligatoire', config('media.scan.required') === true, 'MEDIA_SCAN_REQUIRED doit valoir true en production.'),
            $this->check('media.scanner', 'Antivirus disponible', $this->scanner->isAvailable(), 'Aucun moteur antivirus disponible (MEDIA_SCAN_DRIVER) : avec l\'analyse obligatoire, tous les imports seront refusés.'),
            $this->check('media.gd', 'Extension GD chargée (PHP CLI)', extension_loaded('gd') && function_exists('imagecreatefromstring'), 'GD absente : les images importées ne sont pas réencodées (EXIF, GPS et fichiers polyglottes conservés). Activer extension=gd dans le php.ini de la CLI ET de PHP-FPM.'),
            $this->check('media.reencode', 'Réencodage réel des images (EXIF supprimé)', $canReencode, $reason),
        ];
    }

    /** @return list<array<string, string>> */
    private function quotas(): array
    {
        return [
            $this->check('quotas.storage', 'Quota de stockage actif', (int) config('quotas.storage.max_mb') > 0, 'QUOTA_STORAGE_MB=0 désactive le quota de stockage par utilisateur.', self::WARN),
            $this->check('quotas.disk', 'Garde d\'espace disque active', config('quotas.disk.enabled') === true, 'DISK_GUARD_ENABLED=false : plus aucune protection contre la saturation du disque.', self::WARN),
        ];
    }

    /**
     * Réencode réellement un JPEG contenant un bloc EXIF (fichier temporaire supprimé aussitôt) : prouve que
     * l'environnement PHP sait neutraliser les métadonnées, pas seulement que l'extension est déclarée.
     *
     * @return array{0: bool, 1: string}
     */
    private function imageReencoding(): array
    {
        if (! $this->images->isAvailable()) {
            return [false, 'Réencodage impossible sans GD : les images sont stockées telles quelles.'];
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'readiness');
        try {
            $image = imagecreatetruecolor(16, 16);
            ob_start();
            imagejpeg($image, null, 90);
            $jpeg = (string) ob_get_clean();
            imagedestroy($image);
            // Segment APP1 « Exif » inséré juste après le marqueur SOI.
            $exif = "Exif\0\0SoxialConnect-readiness-marker";
            file_put_contents($path, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
            $result = $this->images->process($path, 'image/jpeg');
            $stripped = $result->reencoded && ! str_contains($result->original, 'SoxialConnect-readiness-marker');

            return [$stripped, $stripped ? '' : 'Les métadonnées EXIF ne sont pas supprimées par le réencodage.'];
        } catch (Throwable $e) {
            return [false, 'Le réencodage d\'une image de test a échoué ('.$e::class.').'];
        } finally {
            @unlink($path);
        }
    }

    /** @return array{id: string, label: string, status: string, message: string} */
    private function check(string $id, string $label, bool $passed, string $failure, string $level = self::FAIL): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $passed ? self::OK : $level, 'message' => $passed ? '' : $failure];
    }

    private function isLocal(string $value): bool
    {
        return (bool) preg_match('#(^|//)(localhost|127\.0\.0\.1|\[::1\]|0\.0\.0\.0)(:\d+)?(/|$)#i', $value);
    }

    private function firstLogChannel(): string
    {
        $default = (string) config('logging.default');
        if (config("logging.channels.{$default}.driver") !== 'stack') {
            return $default;
        }

        return (string) (config("logging.channels.{$default}.channels")[0] ?? $default);
    }
}
