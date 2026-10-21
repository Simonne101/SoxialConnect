<?php

namespace App\Http\Controllers\Api\V1\Clipper;

use App\Domain\AI\AiService;
use App\Domain\AI\Enums\AiTask;
use App\Domain\Social\OAuth\OAuthStateStore;
use App\Domain\Video\Clipper\Analysis\ClipAnalyzerResolver;
use App\Domain\Video\Clipper\ClipperService;
use App\Domain\Video\Clipper\Transcription\TranscriberResolver;
use App\Domain\Video\Enums\AspectRatio;
use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\Sources\DataPortability\DataPortabilityOAuth;
use App\Domain\Video\Sources\VideoSourceProviders;
use App\Domain\Video\Sources\YtDlp\YtDlpVideoSourceProvider;
use App\Domain\Video\VideoRenderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clipper\ClipperLookupRequest;
use App\Http\Requests\Clipper\ClipperProjectRequest;
use App\Http\Requests\Clipper\ClipperSourceRequest;
use App\Http\Resources\ClipperProjectResource;
use App\Models\VideoProject;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Projets du YouTube Clipper (ADR-046) : lien YouTube + vidéo source de la médiathèque, analyse,
 * passages proposés et clips. Projet d'un autre compte ou d'un autre type : 404.
 */
class ClipperProjectController extends Controller
{
    public function __construct(private readonly AiService $ai) {}

    public function index(Request $request, VideoRenderService $renders, ClipAnalyzerResolver $analyzers, TranscriberResolver $transcribers): JsonResponse
    {
        Gate::authorize('viewAny', VideoProject::class);
        $projects = VideoProject::query()
            ->where('user_id', $request->user()->getKey())
            ->where('kind', VideoProjectKind::Clipping->value)
            ->with(['sourceMedia', 'videoSource'])
            ->latest()
            ->paginate(20);

        return ApiResponse::paginated($projects, ClipperProjectResource::class, extraMeta: $this->capabilities($renders, $analyzers, $transcribers));
    }

    public function store(ClipperProjectRequest $request, ClipperService $clipper, VideoRenderService $renders, ClipAnalyzerResolver $analyzers, TranscriberResolver $transcribers): JsonResponse
    {
        $project = $clipper->createProject(
            $request->user(),
            (string) $request->validated('youtube_url'),
            $request->validated('media_id'),
            AspectRatio::from($request->validated('aspect_ratio') ?? config('video.default_aspect_ratio')),
            $request->boolean('rights_confirmed'),
        );

        return ApiResponse::ok(new ClipperProjectResource($this->loadForView($project)), 'Analyse de la vidéo lancée.', $this->capabilities($renders, $analyzers, $transcribers), 201);
    }

    /** Aperçu d'une vidéo YouTube (lien validé, titre, chaîne, miniature) avant d'analyser. */
    public function lookup(ClipperLookupRequest $request, ClipperService $clipper): JsonResponse
    {
        return ApiResponse::ok($clipper->lookup((string) $request->validated('youtube_url')));
    }

    /** Joint la vidéo source rendable (médiathèque) : mesurée, analysée, puis utilisable pour le rendu. */
    public function attachSource(ClipperSourceRequest $request, VideoProject $clippingProject, ClipperService $clipper, VideoRenderService $renders, ClipAnalyzerResolver $analyzers, TranscriberResolver $transcribers): JsonResponse
    {
        $project = $clipper->attachSource($clippingProject, (string) $request->validated('media_id'));

        return ApiResponse::ok(new ClipperProjectResource($this->loadForView($project)), 'Vidéo source ajoutée : analyse en cours.', $this->capabilities($renders, $analyzers, $transcribers));
    }

    /** Nouvelle tentative d'acquisition automatique de la source (aucune acquisition en double). */
    public function acquireSource(Request $request, VideoProject $clippingProject, ClipperService $clipper, VideoRenderService $renders, ClipAnalyzerResolver $analyzers, TranscriberResolver $transcribers): JsonResponse
    {
        Gate::authorize('update', $clippingProject);
        $request->validate(['rights_confirmed' => ['sometimes', 'boolean']]);
        $clipper->retryAcquisition($clippingProject, $request->boolean('rights_confirmed'));

        return ApiResponse::ok(new ClipperProjectResource($this->loadForView($clippingProject->refresh())), 'Recherche de la source vidéo relancée.', $this->capabilities($renders, $analyzers, $transcribers), 202);
    }

    /**
     * Consentement à l'export officiel Google de la chaîne propriétaire (ADR-054) : URL de consentement
     * Google (state à usage unique lié à l'utilisateur, au projet et au compte YouTube, PKCE S256).
     * Uniquement pour une vidéo dont la propriété a été vérifiée sur une chaîne connectée.
     */
    public function sourceConsent(Request $request, VideoProject $clippingProject, OAuthStateStore $states, DataPortabilityOAuth $oauth): JsonResponse
    {
        Gate::authorize('update', $clippingProject);
        if (! $oauth->isConfigured()) {
            return ApiResponse::error('L\'export officiel Google n\'est pas encore configuré sur ce serveur.', 409);
        }
        $source = $clippingProject->videoSource()->first();
        if ($source === null || $source->ownership_status !== SourceOwnership::Owned || $source->ownership_account_id === null) {
            return ApiResponse::error('L\'export officiel Google ne concerne que les vidéos de vos propres chaînes YouTube connectées. Pour cette vidéo, importez un fichier dont vous détenez les droits.', 409);
        }
        $issued = $states->issue($request->user(), DataPortabilityOAuth::PROVIDER, [
            'project_id' => (string) $clippingProject->getKey(),
            'account_id' => (string) $source->ownership_account_id,
        ]);

        return ApiResponse::ok(['authorization_url' => $oauth->authorizationUrl($issued['state'], $issued['verifier'])]);
    }

    /** Relance de l'analyse (après une erreur temporaire) : même projet, aucun doublon. */
    public function reanalyze(VideoProject $clippingProject, ClipperService $clipper, VideoRenderService $renders, ClipAnalyzerResolver $analyzers, TranscriberResolver $transcribers): JsonResponse
    {
        Gate::authorize('update', $clippingProject);
        $project = $clipper->reanalyze($clippingProject);

        return ApiResponse::ok(new ClipperProjectResource($this->loadForView($project)), 'Analyse relancée.', $this->capabilities($renders, $analyzers, $transcribers), 202);
    }

    /** Annule le traitement en cours (analyse, acquisition, rendus) ; les clips déjà publiés restent. */
    public function cancel(VideoProject $clippingProject, ClipperService $clipper, VideoRenderService $renders, ClipAnalyzerResolver $analyzers, TranscriberResolver $transcribers): JsonResponse
    {
        Gate::authorize('update', $clippingProject);
        $project = $clipper->cancel($clippingProject);

        return ApiResponse::ok(new ClipperProjectResource($this->loadForView($project)), 'Traitement annulé.', $this->capabilities($renders, $analyzers, $transcribers));
    }

    public function show(VideoProject $clippingProject, VideoRenderService $renders, ClipAnalyzerResolver $analyzers, TranscriberResolver $transcribers): JsonResponse
    {
        Gate::authorize('view', $clippingProject);

        return ApiResponse::ok(new ClipperProjectResource($this->loadForView($clippingProject)), meta: $this->capabilities($renders, $analyzers, $transcribers));
    }

    public function destroy(VideoProject $clippingProject, ClipperService $clipper): JsonResponse
    {
        Gate::authorize('delete', $clippingProject);
        $clipper->deleteProject($clippingProject);

        return ApiResponse::ok(null, 'Projet supprimé. Les clips déjà générés restent dans votre médiathèque.');
    }

    /** Passages proposés par intérêt décroissant, puis clips générés du plus récent au plus ancien. */
    public static function loadForView(VideoProject $project): VideoProject
    {
        return $project->load([
            'sourceMedia',
            'videoSource',
            'clips' => fn ($q) => $q->with('outputMedia')->where('status', '!=', 'discarded')->orderByDesc('score')->orderByDesc('created_at'),
        ])->loadCount(['transcripts as ready_transcripts_count' => fn ($q) => $q->where('status', 'ready')]);
    }

    /**
     * Ce que le serveur sait faire (ADR-047) : moteur FFmpeg, analyse automatique (« ai » si l'IA est
     * configurée, « local » sinon, « none » si désactivée) et transcription (« local », « ai », « none »).
     *
     * @return array{renderer_available: bool, analysis_available: bool, analysis_mode: string, url_analysis_available: bool, url_analysis_provider: ?string, automatic_source_available: bool, transcription_mode: string, transcription_engine: ?string}
     */
    private function capabilities(VideoRenderService $renders, ClipAnalyzerResolver $analyzers, TranscriberResolver $transcribers): array
    {
        $analysis = $analyzers->primaryMode();
        $transcriber = $transcribers->resolve();
        $url = $analyzers->urlAnalyzer();

        return [
            'renderer_available' => $renders->rendererAvailable(),
            'analysis_available' => $analysis !== 'none',
            'analysis_mode' => $analysis,
            // Analyse directe depuis le lien YouTube (sans fichier), fournisseur compatible requis.
            'url_analysis_available' => $url !== null,
            'url_analysis_provider' => $url !== null ? $this->ai->urlProviderNameFor(AiTask::VideoAnalysis) : null,
            // Acquisition automatique d'une source autorisée (ADR-049) : au moins un fournisseur activé.
            'automatic_source_available' => app(VideoSourceProviders::class)->configured() !== [],
            // Récupération d'une vidéo publique par yt-dlp, après attestation de droits (ADR-059).
            'video_download_available' => app(YtDlpVideoSourceProvider::class)->isConfigured(),
            'transcription_mode' => $transcriber?->mode() ?? 'none',
            'transcription_engine' => $transcriber?->engine(),
        ];
    }
}
