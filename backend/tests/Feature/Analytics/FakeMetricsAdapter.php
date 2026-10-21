<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Contracts\MetricsAdapter;
use App\Domain\Analytics\Data\MetricsResult;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/** Adaptateur de test : réponses scriptées, aucune plateforme réelle. */
class FakeMetricsAdapter implements MetricsAdapter
{
    public static ?MetricsResult $result = null;

    public static ?\Throwable $exception = null;

    public static int $calls = 0;

    public static function reset(): void
    {
        self::$result = null;
        self::$exception = null;
        self::$calls = 0;
    }

    public function fetchAccountMetrics(SocialAccount $account, CarbonImmutable $from, CarbonImmutable $to): MetricsResult
    {
        return $this->answer();
    }

    public function fetchPostMetrics(SocialAccount $account, PostVariant $variant): MetricsResult
    {
        return $this->answer();
    }

    private function answer(): MetricsResult
    {
        self::$calls++;
        if (self::$exception !== null) {
            throw self::$exception;
        }

        return self::$result ?? new MetricsResult([], [], CarbonImmutable::now(), CarbonImmutable::now());
    }
}
