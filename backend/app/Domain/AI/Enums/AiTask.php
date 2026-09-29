<?php

namespace App\Domain\AI\Enums;

// Valeurs alignées sur docs/database.md (contraintes CHECK des migrations).
enum AiTask: string
{
    case Post = 'post';
    case Caption = 'caption';
    case Ideas = 'ideas';
    case Hashtags = 'hashtags';
    case Rephrase = 'rephrase';
    case Script = 'script';
    case Tone = 'tone';
    case VideoAnalysis = 'video_analysis';
    case ClipDetection = 'clip_detection';
    case Transcription = 'transcription';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
