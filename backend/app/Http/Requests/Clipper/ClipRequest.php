<?php

namespace App\Http\Requests\Clipper;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Clipper\SubtitleTrack;
use App\Domain\Video\Enums\AspectRatio;
use App\Models\VideoClip;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Génération d'un clip : passage choisi (POST /clipper/projects/{project}/clips) ou proposition de
 * l'analyse (POST /clipper/clips/{clip}/render, bornes facultatives). Durée ≤ 60 s refusée dès la
 * requête, puis revérifiée par le service, le job et FFprobe.
 */
class ClipRequest extends FormRequest
{
    public function authorize(): Response
    {
        $clip = $this->route('clip');

        return Gate::inspect('update', $clip instanceof VideoClip ? $clip : $this->route('clippingProject'));
    }

    public function rules(): array
    {
        $required = $this->route('clip') instanceof VideoClip ? 'sometimes' : 'required';

        return [
            'start_ms' => [$required, 'integer', 'min:0', 'max:86400000'],
            'end_ms' => [$required, 'integer', 'min:1', 'max:86400000'],
            'aspect_ratio' => ['sometimes', Rule::enum(AspectRatio::class)],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subtitles' => ['sometimes', 'boolean'],
            'subtitle_style' => ['sometimes', 'nullable', 'array:position,size'],
            'subtitle_style.position' => ['sometimes', Rule::in(SubtitleTrack::POSITIONS)],
            'subtitle_style.size' => ['sometimes', Rule::in(SubtitleTrack::SIZES)],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $clip = $this->route('clip');
            $start = $this->has('start_ms') ? $this->integer('start_ms') : ($clip instanceof VideoClip ? $clip->start_ms : null);
            $end = $this->has('end_ms') ? $this->integer('end_ms') : ($clip instanceof VideoClip ? $clip->end_ms : null);
            if ($start === null || $end === null) {
                return;
            }
            $violation = ClipLimits::violation($start, $end);
            if ($violation !== null) {
                $validator->errors()->add('end_ms', $violation);
            }
        }];
    }
}
