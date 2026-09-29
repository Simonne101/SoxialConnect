<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Origine de la SPA déclarée dans SANCTUM_STATEFUL_DOMAINS (phpunit.xml). */
    protected const SPA_ORIGIN = 'http://localhost:5173';

    /**
     * Requêtes « comme la SPA » : Sanctum active alors la session (authentification par cookie).
     * La protection CSRF est désactivée automatiquement par Laravel pendant les tests.
     */
    protected function fromSpa(): static
    {
        return $this->withHeaders([
            'Origin' => self::SPA_ORIGIN,
            'Referer' => self::SPA_ORIGIN.'/',
            'Accept' => 'application/json',
        ]);
    }
}
