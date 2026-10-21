<?php

namespace App\Domain\Analytics\Enums;

/** État de la collecte d'un compte ou d'une publication (table analytics_sync_states, contrainte CHECK). */
enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Aucun adaptateur de collecte pour ce réseau, ou métriques non accessibles : rien n'est simulé. */
    case Unsupported = 'unsupported';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
