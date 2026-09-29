<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Enums\PostFormat;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Models\Post;
use App\Support\Validation\Timezones;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Création / modification d'une publication (future API du Composer).
 * Tous les identifiants reçus sont vérifiés comme appartenant à l'utilisateur (anti-IDOR) :
 * un compte social ou un média d'un autre utilisateur est traité comme inexistant (422).
 */
class SavePostRequest extends FormRequest
{
    /** Clés d'options autorisées par variante (docs/api-integration.md ; types frontend VariantOptions). */
    private const OPTION_KEYS = 'title,description,hashtags,destinationUrl,board,privacy,allowComments,allowDuet,allowStitch,commercialContent,youtubeVisibility,madeForKids,coverMediaId,thumbnailMediaId,firstComment,accountEntityId';

    /**
     * Autorisation AVANT validation : une mise à jour du post d'un autre compte répond 404,
     * jamais 422 (sinon la réponse révélerait l'existence du post — IDOR).
     */
    public function authorize(): Response
    {
        $post = $this->route('post');

        return $post instanceof Post ? Gate::inspect('update', $post) : Gate::inspect('create', Post::class);
    }

    public function rules(): array
    {
        $userId = $this->user()->getKey();
        $schedule = $this->input('mode') === 'schedule';

        return [
            // « publish » (publication immédiate) arrive avec la phase Publishing.
            'mode' => ['required', Rule::in(['draft', 'schedule'])],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['present', 'nullable', 'string', 'max:63206'],
            'timezone' => ['sometimes', 'string', Rule::in(Timezones::identifiers())],
            'scheduled_at' => [Rule::requiredIf($schedule), 'nullable', 'date', $schedule ? 'after:now' : 'nullable'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],

            'media_ids' => ['sometimes', 'array', 'max:20'],
            'media_ids.*' => [
                'uuid',
                'distinct',
                Rule::exists('media', 'id')->where(fn ($q) => $q
                    ->where('user_id', $userId)
                    ->where('status', MediaStatus::Ready->value)
                    ->where('scan_status', 'clean')
                    ->whereNull('deleted_at')),
            ],

            'targets' => [$schedule ? 'required' : 'sometimes', 'array', 'max:20'],
            'targets.*.social_account_id' => [
                'required',
                'uuid',
                Rule::exists('social_accounts', 'id')->where(function ($q) use ($userId, $schedule) {
                    $q->where('user_id', $userId)->whereNull('deleted_at');
                    // Programmer exige un compte connecté ; un brouillon peut viser un compte à reconnecter.
                    if ($schedule) {
                        $q->where('status', SocialAccountStatus::Connected->value);
                    }
                }),
            ],
            'targets.*.format' => ['required', Rule::enum(PostFormat::class)],
            'targets.*.body' => ['nullable', 'string', 'max:63206'],
            'targets.*.options' => ['sometimes', 'array:'.self::OPTION_KEYS],
            'targets.*.options.hashtags' => ['sometimes', 'array', 'max:30'],
            'targets.*.options.hashtags.*' => ['string', 'max:100', 'regex:/^[\pL\pN_]+$/u'],
            'targets.*.options.destinationUrl' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'targets.*.options.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'targets.*.options.privacy' => ['sometimes', 'nullable', Rule::in(['public', 'followers', 'friends', 'private'])],
            'targets.*.options.youtubeVisibility' => ['sometimes', 'nullable', Rule::in(['public', 'unlisted', 'private'])],
        ];
    }

    public function messages(): array
    {
        return [
            'targets.*.social_account_id.exists' => 'Ce compte social est introuvable ou n\'est pas connecté.',
            'media_ids.*.exists' => 'Ce média est introuvable ou n\'est pas encore prêt.',
            'scheduled_at.after' => 'Choisissez une date et une heure à venir.',
            'scheduled_at.required' => 'Indiquez la date de programmation.',
            'targets.required' => 'Choisissez au moins un réseau social.',
        ];
    }

    /** Un texte ou un média est nécessaire (hors brouillon vide). */
    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->input('mode') === 'schedule' && blank($this->input('body')) && empty($this->input('media_ids'))) {
                    $validator->errors()->add('body', 'Ajoutez un texte ou un média.');
                }
            },
        ];
    }
}
