<?php

namespace App\Support\Logging;

use Illuminate\Log\LogManager;

/**
 * Journal de secours (« emergency ») expurgé lui aussi (étape 8, lot 1) : Laravel le construit en dur, sans les
 * « tap » de config/logging.php, quand un canal configuré ne peut pas être créé ; il recevrait alors en clair
 * l'exception de création puis tous les messages du canal défaillant.
 */
class RedactingLogManager extends LogManager
{
    protected function createEmergencyLogger()
    {
        return (new RedactSecretsProcessor)(parent::createEmergencyLogger());
    }
}
