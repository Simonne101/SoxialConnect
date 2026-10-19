<?php

namespace App\Domain\Media\Security;

use Illuminate\Support\Facades\Log;

/**
 * Antivirus ClamAV par le démon clamd (étape 8, lot 6) : protocole natif « INSTREAM » sur TCP ou socket Unix,
 * sans dépendance PHP ni accès de clamd aux fichiers de l'application (le contenu lui est envoyé par morceaux).
 *
 * Échec fermé : toute réponse inattendue, dépassement de délai ou limite de taille de clamd (StreamMaxLength)
 * donne ScanResult::error() — jamais « sain ». La réponse « FOUND » donne la signature détectée.
 */
final class ClamAvScanner implements MediaSecurityScanner
{
    private const CHUNK_BYTES = 1024 * 1024;

    /**
     * @param  string  $address  « tcp://hôte:port » ou « unix:///chemin/clamd.sock »
     */
    public function __construct(
        private readonly string $address,
        private readonly float $connectTimeout = 2.0,
        private readonly float $scanTimeout = 120.0,
    ) {}

    /** @param array{host?: ?string, port?: int|string|null, socket?: ?string, connect_timeout?: float|int|string, scan_timeout?: float|int|string} $config */
    public static function fromConfig(array $config): self
    {
        $socket = trim((string) ($config['socket'] ?? ''));
        $address = $socket !== '' ? 'unix://'.$socket : sprintf('tcp://%s:%d', $config['host'] ?? '127.0.0.1', (int) ($config['port'] ?? 3310));

        return new self($address, (float) ($config['connect_timeout'] ?? 2), (float) ($config['scan_timeout'] ?? 120));
    }

    public function isAvailable(): bool
    {
        $connection = $this->connect();
        if ($connection === null) {
            return false;
        }
        try {
            return $this->command($connection, 'PING') === 'PONG';
        } finally {
            fclose($connection);
        }
    }

    public function scan(string $localPath): ScanResult
    {
        $file = @fopen($localPath, 'rb');
        if ($file === false) {
            return ScanResult::error();
        }
        $connection = $this->connect();
        if ($connection === null) {
            fclose($file);

            return ScanResult::error();
        }

        try {
            stream_set_timeout($connection, (int) ceil($this->scanTimeout));
            if (! $this->write($connection, "zINSTREAM\0")) {
                return ScanResult::error();
            }
            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK_BYTES);
                if ($chunk === false) {
                    return ScanResult::error();
                }
                if ($chunk === '') {
                    continue;
                }
                if (! $this->write($connection, pack('N', strlen($chunk)).$chunk)) {
                    // clamd ferme la connexion au-delà de StreamMaxLength : la réponse explique pourquoi.
                    return $this->interpret($this->read($connection));
                }
            }
            $this->write($connection, pack('N', 0));

            return $this->interpret($this->read($connection));
        } finally {
            fclose($file);
            fclose($connection);
        }
    }

    /** « stream: OK » → sain ; « stream: <signature> FOUND » → infecté ; tout le reste → erreur. */
    private function interpret(?string $reply): ScanResult
    {
        if ($reply === 'stream: OK') {
            return ScanResult::clean();
        }
        if ($reply !== null && preg_match('/^stream: (.+) FOUND$/', $reply, $m) === 1) {
            return ScanResult::infected(mb_substr($m[1], 0, 200));
        }
        Log::warning('media.scan_error', ['engine' => 'clamav', 'reply' => $reply === null ? null : mb_substr($reply, 0, 200)]);

        return ScanResult::error();
    }

    /** @return resource|null */
    private function connect()
    {
        $connection = @stream_socket_client($this->address, $errno, $error, $this->connectTimeout);
        if ($connection === false) {
            return null;
        }
        stream_set_timeout($connection, (int) max(1, ceil($this->connectTimeout)));

        return $connection;
    }

    /** @param resource $connection */
    private function command($connection, string $command): ?string
    {
        return $this->write($connection, "z{$command}\0") ? $this->read($connection) : null;
    }

    /** @param resource $connection */
    private function write($connection, string $bytes): bool
    {
        $length = strlen($bytes);
        for ($written = 0; $written < $length; $written += $sent) {
            $sent = @fwrite($connection, substr($bytes, $written));
            if ($sent === false || $sent === 0) {
                return false;
            }
        }

        return true;
    }

    /** Réponse terminée par « \0 » (commandes préfixées par « z »). @param resource $connection */
    private function read($connection): ?string
    {
        $reply = '';
        while (! feof($connection) && ! str_contains($reply, "\0")) {
            $part = fread($connection, 8192);
            if ($part === false || ($part === '' && (stream_get_meta_data($connection)['timed_out'] ?? false))) {
                return null;
            }
            $reply .= $part;
        }

        $reply = trim(strstr($reply, "\0", true) ?: $reply);

        return $reply === '' ? null : $reply;
    }
}
