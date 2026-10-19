<?php

namespace App\Domain\AI\Images;

use RuntimeException;

/** Échec de traitement d'une image générée (image illisible, GD absent, enregistrement). */
final class ImageGenerationFailure extends RuntimeException {}
