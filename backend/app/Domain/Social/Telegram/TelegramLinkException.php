<?php

namespace App\Domain\Social\Telegram;

use RuntimeException;

/** Refus de liaison d'une chaîne ou d'un groupe : message destiné à l'utilisateur, sans détail technique. */
final class TelegramLinkException extends RuntimeException {}
