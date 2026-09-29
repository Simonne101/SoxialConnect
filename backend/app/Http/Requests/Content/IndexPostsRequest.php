<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtres de la liste des publications (liste, brouillons, calendrier via from/to). */
class IndexPostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** « ?status=draft » est accepté comme « ?status[]=draft ». */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => [$this->input('status')]]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(PostStatus::class)],
            'platform' => ['sometimes', 'string', 'max:32', 'exists:social_platforms,key'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
