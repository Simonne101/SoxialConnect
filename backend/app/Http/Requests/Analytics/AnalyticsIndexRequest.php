<?php

namespace App\Http\Requests\Analytics;

use App\Domain\Analytics\Enums\Granularity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filtres de GET /analytics, limités à ce qui est réellement stocké : période (jours), compte, réseau,
 * publication, portée (compte ou publications) et granularité. Période bornée à 400 jours.
 */
class AnalyticsIndexRequest extends FormRequest
{
    public const MAX_SPAN_DAYS = 400;

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'social_account_id' => ['sometimes', 'uuid'],
            'platform' => ['sometimes', 'string', Rule::exists('social_platforms', 'key')],
            'post_variant_id' => ['sometimes', 'uuid'],
            'scope' => ['sometimes', Rule::in(['account', 'post'])],
            'granularity' => ['sometimes', Rule::in(Granularity::values())],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            [$from, $to] = [$this->input('from'), $this->input('to')];
            if (! $validator->errors()->isEmpty() || ! is_string($from) || ! is_string($to)) {
                return;
            }
            if ($from > $to) {
                $validator->errors()->add('from', 'La date de début doit précéder la date de fin.');
            } elseif ((strtotime($to) - strtotime($from)) / 86400 + 1 > self::MAX_SPAN_DAYS) {
                $validator->errors()->add('to', 'La période ne peut pas dépasser '.self::MAX_SPAN_DAYS.' jours.');
            }
        }];
    }
}
