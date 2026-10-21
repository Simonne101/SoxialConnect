<?php

/**
 * Faux démon clamd pour les tests (étape 8, lot 6) : protocole réel (commandes « z…\0 », INSTREAM par morceaux
 * préfixés de leur longueur), sans moteur.
 *
 * Usage : php fake-clamd.php <port> <mode>
 *   mode « normal »  : PING → PONG ; INSTREAM → « stream: OK », ou « … FOUND » pour le fichier de test EICAR
 *                      (reconnu à son empreinte SHA-256, ce script ne contient pas la chaîne) et pour le marqueur
 *                      inoffensif des tests d'intégration
 *   mode « limit »   : INSTREAM refusé au-delà de 64 octets (StreamMaxLength), comme clamd
 *   mode « garbage » : réponse incompréhensible
 * Imprime « READY » une fois à l'écoute.
 */
const EICAR_SHA256 = '275a021bbfb6489e54d471899f7db9d1663fc695ec2fe2a2c4538aabf651fd0f';
const TEST_MARKER = 'SOXIALCONNECT-SCANNER-TEST-MARKER';

[$port, $mode] = [(int) ($argv[1] ?? 0), $argv[2] ?? 'normal'];
$listener = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
if ($listener === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}
fwrite(STDOUT, "READY\n");
fflush(STDOUT);

function read_exactly($client, int $length): ?string
{
    $data = '';
    while (strlen($data) < $length) {
        $part = fread($client, $length - strlen($data));
        if ($part === false || $part === '') {
            return null;
        }
        $data .= $part;
    }

    return $data;
}

function instream_reply($client, string $mode): string
{
    $content = '';
    while (($header = read_exactly($client, 4)) !== null) {
        $length = unpack('N', $header)[1];
        if ($length === 0) {
            break;
        }
        $chunk = read_exactly($client, $length);
        if ($chunk === null) {
            break;
        }
        $content .= $chunk;
        if ($mode === 'limit' && strlen($content) > 64) {
            return 'INSTREAM size limit exceeded. ERROR';
        }
    }

    return match (true) {
        hash('sha256', $content) === EICAR_SHA256 => 'stream: Eicar-Test-Signature FOUND',
        str_contains($content, TEST_MARKER) => 'stream: Sxc.Test.Marker FOUND',
        default => 'stream: OK',
    };
}

while ($client = @stream_socket_accept($listener, 600)) {
    $command = '';
    while (! str_contains($command, "\0") && ($c = fread($client, 1)) !== false && $c !== '') {
        $command .= $c;
    }
    $reply = match (true) {
        $mode === 'garbage' => '???',
        $command === "zPING\0" => 'PONG',
        $command === "zINSTREAM\0" => instream_reply($client, $mode),
        default => 'UNKNOWN COMMAND',
    };
    fwrite($client, $reply."\0");
    fclose($client);
}
