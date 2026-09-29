<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Processeur Monolog branché sur les canaux de journalisation (« tap » dans config/logging.php) :
 * chaque message et son contexte passent par SecretRedactor avant d'être écrits.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    public function __construct(private readonly ?SecretRedactor $redactor = null) {}

    /** Utilisé comme « tap » : ajoute le processeur au logger du canal. */
    public function __invoke(Logger|LogRecord $target): Logger|LogRecord
    {
        if ($target instanceof LogRecord) {
            $redactor = $this->redactor ?? SecretRedactor::fromConfig();

            return $target->with(
                message: $redactor->redactString($target->message),
                context: $redactor->redact($target->context),
                extra: $redactor->redact($target->extra),
            );
        }

        $target->getLogger()->pushProcessor(new self(SecretRedactor::fromConfig()));

        return $target;
    }
}
