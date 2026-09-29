<?php

namespace App\Domain\Social\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
