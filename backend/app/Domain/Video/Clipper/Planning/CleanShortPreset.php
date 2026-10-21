<?php

namespace App\Domain\Video\Clipper\Planning;

/** Préréglage V1 (ADR-051) : modèle « clean-short », 30 i/s, recadrage centré, accroche 3 s. */
final class CleanShortPreset implements RenderPreset
{
    public function name(): string
    {
        return 'clean-short';
    }

    public function template(): string
    {
        return 'clean-short';
    }

    public function fps(): int
    {
        return 30;
    }

    public function crop(): string
    {
        return 'center';
    }

    public function hookDurationMs(): int
    {
        return 3_000;
    }

    public function maxCaptionChars(): int
    {
        return 42;
    }
}
