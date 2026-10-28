<?php

use App\Domain\Video\Clipper\Planning\CleanShortPreset;
use App\Domain\Video\Sources\DataPortability\DataPortabilityVideoSourceProvider;
use App\Domain\Video\Sources\YtDlp\YtDlpVideoSourceProvider;

/*
 * Video Studio — projets « composition » (ADR-042). Préparation uniquement : aucun rendu n'est
 * exécuté avant la phase « Rendu ». FFmpeg et FFprobe ne sont PAS requis pour démarrer
 * l'application : leurs chemins ne sont lus qu'au moment d'un rendu.
 */

return [

    /* Moteur de rendu, enregistré sur chaque rendu (seul moteur : FFmpeg, ADR-045). */
    'renderer' => env('VIDEO_RENDERER') ?: 'ffmpeg',

    'binaries' => [
        // Nom dans le PATH ou chemin absolu (ex. C:\ffmpeg\bin\ffmpeg.exe) ; vide = valeur par défaut.
        'ffmpeg' => env('VIDEO_FFMPEG_BINARY') ?: 'ffmpeg',
        'ffprobe' => env('VIDEO_FFPROBE_BINARY') ?: 'ffprobe',
    ],

    /*
     * Limites V1 d'un projet (appliquées par les services Video ; la base garantit déjà les bornes
     * par scène). Elles bornent la taille d'un projet et le coût d'un rendu : vidéos sociales
     * courtes (90 s), quelques calques (8 pistes), un montage lisible (100 éléments par piste).
     */
    'limits' => [
        'max_duration_ms' => (int) env('VIDEO_MAX_DURATION_MS', 90_000),
        'max_scenes' => (int) env('VIDEO_MAX_SCENES', 20),
        'max_tracks' => (int) env('VIDEO_MAX_TRACKS', 8),
        'max_items_per_track' => (int) env('VIDEO_MAX_ITEMS_PER_TRACK', 100),
        'max_assets' => (int) env('VIDEO_MAX_ASSETS', 100),
        // 100 ms : en dessous, un élément n'occupe que 2 ou 3 images à 24-30 i/s.
        'item_min_duration_ms' => 100,
        'scene_min_duration_ms' => 500,
        'scene_max_duration_ms' => 30_000,
        'max_width' => (int) env('VIDEO_MAX_WIDTH', 1920),
        'max_height' => (int) env('VIDEO_MAX_HEIGHT', 1920),
    ],

    /* Dimensions de sortie par format (pixels, H.264 exige des dimensions paires). */
    'formats' => [
        '9:16' => ['width' => 1080, 'height' => 1920],
        '1:1' => ['width' => 1080, 'height' => 1080],
        '16:9' => ['width' => 1920, 'height' => 1080],
    ],
    'default_aspect_ratio' => '9:16',

    'fps' => [
        'default' => (int) env('VIDEO_DEFAULT_FPS', 30),
        'allowed' => [24, 25, 30],
    ],

    /*
     * Police des textes (drawtext). Vide : première police trouvée parmi les candidates (Windows,
     * puis Linux). Aucune police trouvée et un texte à rendre : le rendu échoue (FONT_UNAVAILABLE).
     */
    'font_file' => env('VIDEO_FONT_FILE') ?: null,
    'font_candidates' => [
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    ],

    /* Encodage du MP4 final : H.264 (yuv420p) + AAC, lecture web immédiate (+faststart). */
    'encoding' => [
        'video_preset' => env('VIDEO_X264_PRESET', 'veryfast'),
        'video_crf' => (int) env('VIDEO_X264_CRF', 20),
        'audio_bitrate' => '160k',
        'audio_sample_rate' => 48_000,
    ],

    /* Délai de détection des binaires (`-version`, secondes) : un premier lancement peut être lent. */
    'binary_check_timeout_seconds' => (int) env('VIDEO_BINARY_CHECK_TIMEOUT', 20),

    /* Délai maximal d'une analyse FFprobe (secondes). */
    // 120 s : une analyse prend < 1 s, mais 30 s ont été dépassées sous forte charge (rendu Remotion en cours),
    // rejetant à tort des fichiers valides (2026-10-13). Le délai ne borne qu'un blocage.
    'probe_timeout_seconds' => (int) env('VIDEO_PROBE_TIMEOUT', 120),

    'render' => [
        // Durée maximale d'un rendu (secondes) : timeout de RenderVideoJob, inférieur à
        // queue.connections.redis-video.retry_after (sinon un rendu en cours serait relancé).
        'timeout_seconds' => (int) env('VIDEO_RENDER_TIMEOUT', 1800),
        'connection' => env('VIDEO_QUEUE_CONNECTION', 'redis-video'),
        'queue' => env('VIDEO_QUEUE', 'video'),
    ],

    /*
     * Fichiers de travail d'un rendu : un dossier par rendu, supprimé à la fin ; une purge
     * planifiée (video:purge-temp) supprime les dossiers plus anciens que retention_hours.
     * Par défaut dans storage/ (disque du projet), jamais dans le dossier temporaire système.
     */
    /*
     * YouTube Clipper (ADR-046). La durée maximale d'un clip (60 s) n'est PAS réglable : c'est une
     * règle métier (App\Domain\Video\Clipper\ClipLimits), vérifiée aussi par la base.
     */
    'clipper' => [
        // Nombre de passages proposés par l'analyse.
        'suggestions' => (int) env('CLIPPER_SUGGESTIONS', 5),

        /*
         * Clip Intelligence Engine (ADR-051) : poids des critères notés par l'IA (0-100 chacun). Le score
         * global (0-100) est la moyenne pondérée calculée par ClipScorer ; c'est le SEUL endroit où ces
         * poids sont définis. Ils sont normalisés (leur somme n'a pas besoin de valoir 1).
         */
        'scoring' => [
            'weights' => [
                'hook' => 0.30,
                'value' => 0.25,
                'autonomy' => 0.15,
                'emotion' => 0.15,
                'rhythm' => 0.10,
                'quality' => 0.05,
            ],
        ],
        // Deux passages dont le chevauchement dépasse cette part du plus court sont des doublons :
        // seul le mieux noté est conservé (ClipDeduplicator).
        'dedupe_overlap' => (float) env('CLIPPER_DEDUPE_OVERLAP', 0.5),

        // Composition des clips (ADR-052) : « remotion » (FFmpeg découpe et recadre, Remotion compose
        // accroche et sous-titres) ou « ffmpeg » (moteur FFmpeg seul, sous-titres drawtext). Remotion non
        // installé : repli automatique sur FFmpeg. show_hook : accroche de l'IA affichée en début de clip.
        'composer' => env('CLIPPER_COMPOSER', 'remotion'),
        'show_hook' => (bool) env('CLIPPER_SHOW_HOOK', true),
        // Échec de la composition Remotion (manque de mémoire, délai…) : repli sur le rendu FFmpeg seul (sous-titres
        // incrustés, sans accroche), tracé dans les métadonnées. false : le clip échoue (REMOTION_RENDER_FAILED…).
        'remotion_fallback' => (bool) env('CLIPPER_REMOTION_FALLBACK', true),
        // Double envoi d'une même demande (même lien, fichier et format) dans cette fenêtre : le projet déjà
        // créé est renvoyé, aucun doublon. 0 = désactivé.
        'create_dedupe_seconds' => (int) env('CLIPPER_CREATE_DEDUPE_SECONDS', 10),

        // Préréglages de rendu du Clipper (ClipPlanner, ADR-051) : nom → classe RenderPreset.
        // V1 : clean-short ; youtube-shorts, instagram-reels, tiktok s'ajouteront ici.
        'presets' => [
            'clean-short' => CleanShortPreset::class,
        ],
        // Durée minimale d'un clip (ms).
        'min_clip_ms' => 3_000,
        // Durée maximale d'une vidéo source analysée (coût et taille de la transcription).
        'max_source_minutes' => (int) env('CLIPPER_MAX_SOURCE_MINUTES', 120),
        // Audio envoyé à la transcription : mono, faible débit (2 h ≈ 22 Mo à 24 kbit/s).
        'audio_bitrate' => '24k',
        'audio_sample_rate' => 16_000,
        // Longueur maximale de la transcription transmise au modèle d'analyse (caractères).
        'max_transcript_chars' => (int) env('CLIPPER_MAX_TRANSCRIPT_CHARS', 120_000),
        // Vérification du lien YouTube (oEmbed officiel) : délai (s).
        'oembed_timeout' => 8,

        /*
         * Transcription (ADR-047) : auto = whisper.cpp local s'il est installé, sinon le fournisseur
         * IA s'il est configuré, sinon aucune ; local | ai | none pour forcer. Aucune n'est obligatoire :
         * sans transcription, l'analyse locale découpe sur les pauses et le passage manuel reste possible.
         */
        'transcription' => env('CLIPPER_TRANSCRIPTION', 'auto'),

        /* Analyse des passages : auto = IA si configurée, sinon heuristique locale ; ai | local | none. */
        'analysis' => env('CLIPPER_ANALYSIS', 'auto'),

        /*
         * whisper.cpp (transcription locale, sans API) : binaire whisper-cli et modèle ggml. Par défaut
         * dans storage/app/whisper (hors dépôt). Durée maximale transcrite localement (le processeur
         * transcrit à peu près en temps réel) ; au-delà, l'analyse se fait sur les pauses.
         */
        'whisper' => [
            'binary' => env('WHISPER_CPP_BINARY') ?: storage_path('app/whisper/bin/Release/whisper-cli.exe'),
            'model' => env('WHISPER_CPP_MODEL') ?: storage_path('app/whisper/models/ggml-base.bin'),
            'language' => env('WHISPER_CPP_LANGUAGE', 'auto'),
            'threads' => (int) env('WHISPER_CPP_THREADS', 4),
            'max_minutes' => (int) env('WHISPER_CPP_MAX_MINUTES', 20),
            'timeout' => (int) env('WHISPER_CPP_TIMEOUT', 1500),
        ],

        // Détection des pauses (FFmpeg silencedetect) pour l'analyse locale.
        'silence' => ['noise_db' => -35, 'min_seconds' => 0.6],
    ],

    /*
     * Acquisition de la source vidéo du Clipper (ADR-049) : obtenir, par une voie AUTORISÉE, le fichier
     * rendable correspondant à un lien YouTube. Aucun téléchargeur non officiel. Les fournisseurs sont
     * essayés dans cet ordre ; un fournisseur externe sous contrat s'ajoute ici (VideoSourceProvider).
     */
    'sources' => [
        'providers' => [
            DataPortabilityVideoSourceProvider::class,
            YtDlpVideoSourceProvider::class,
        ],
        // Fichier acquis automatiquement : taille maximale et conservation avant purge (les fichiers
        // importés par l'utilisateur depuis sa médiathèque ne sont jamais purgés).
        'max_bytes' => (int) env('CLIPPER_SOURCE_MAX_BYTES', 4 * 1024 * 1024 * 1024),
        'retention_days' => (int) env('CLIPPER_SOURCE_RETENTION_DAYS', 7),
        // Relances bornées d'une acquisition en attente (archive en préparation) ou en échec temporaire.
        'max_attempts' => (int) env('CLIPPER_SOURCE_MAX_ATTEMPTS', 30),
        // Réutilisation d'un fichier de la même vidéo déjà fourni par l'utilisateur dans un autre projet
        // (ADR-053) : sans copie, sans import, avant tout fournisseur externe.
        'reuse_library' => (bool) env('CLIPPER_REUSE_LIBRARY', true),

        /*
         * Google Data Portability API (ADR-054) : export officiel des vidéos de la chaîne DU PROPRIÉTAIRE,
         * seule voie automatique autorisée pour obtenir le fichier d'une vidéo YouTube. Comptes Google de
         * l'EEE, de Suisse et du Royaume-Uni uniquement. Projet Google Cloud en « Testing » : 100
         * utilisateurs de test, autorisation valable 7 jours ; publication : vérification Google et
         * évaluation de sécurité (scopes restreints). Client OAuth : celui de YouTube par défaut (même
         * projet Google Cloud, API Data Portability activée), ou un client dédié.
         */
        'data_portability' => [
            'enabled' => (bool) env('DATA_PORTABILITY_ENABLED', false),
            'client_id' => env('DATA_PORTABILITY_CLIENT_ID') ?: env('YOUTUBE_CLIENT_ID') ?: null,
            'client_secret' => env('DATA_PORTABILITY_CLIENT_SECRET') ?: env('YOUTUBE_CLIENT_SECRET') ?: null,
            // Retour OAuth (route web) ; en local, relayé par Vite (/oauth) : à déclarer dans Google Cloud.
            'redirect' => env('DATA_PORTABILITY_REDIRECT_URI') ?: rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/oauth/data-portability/callback',
            'base_url' => env('DATA_PORTABILITY_BASE_URL', 'https://dataportability.googleapis.com'),
            // Groupes de ressources exportés (1 scope chacun) : vidéos publiques et non répertoriées.
            'resources' => array_values(array_filter(array_map('trim', explode(',', (string) env('DATA_PORTABILITY_RESOURCES', 'youtube.public_videos,youtube.unlisted_videos'))))),
            'poll_seconds' => (int) env('DATA_PORTABILITY_POLL_SECONDS', 300),
            'timeout' => (int) env('DATA_PORTABILITY_TIMEOUT', 30),
            // Archives téléchargées (URL signées Cloud Storage) : taille cumulée et délai par fichier.
            'archive_max_bytes' => (int) env('DATA_PORTABILITY_ARCHIVE_MAX_BYTES', 20 * 1024 * 1024 * 1024),
            'download_timeout' => (int) env('DATA_PORTABILITY_DOWNLOAD_TIMEOUT', 1500),
        ],

        /*
         * yt-dlp (ADR-059) : récupération d'une vidéo YouTube PUBLIQUE ou non répertoriée pour laquelle
         * l'utilisateur a attesté détenir les droits ou l'autorisation. Désactivé par défaut : les
         * conditions d'utilisation de YouTube restreignent le téléchargement, l'activation relève de
         * l'éditrice du produit. Aucun cookie, aucun compte, extracteur YouTube uniquement.
         * binary : exécutable autonome officiel (github.com/yt-dlp/yt-dlp/releases), empreinte SHA-256
         * vérifiée à l'installation ; `php artisan clipper:ytdlp-check` contrôle l'installation.
         */
        'yt_dlp' => [
            'enabled' => (bool) env('CLIPPER_YTDLP_ENABLED', false),
            'binary' => env('YT_DLP_BINARY') ?: storage_path('app/yt-dlp/'.(PHP_OS_FAMILY === 'Windows' ? 'yt-dlp.exe' : 'yt-dlp')),
            // Délai maximal du téléchargement (secondes) ; les métadonnées sont bornées à 120 s.
            'timeout' => (int) env('YT_DLP_TIMEOUT', 1200),
            'socket_timeout' => (int) env('YT_DLP_SOCKET_TIMEOUT', 30),
            // Délai de la vérification `yt-dlp --version` : l'exécutable autonome se décompresse à chaque
            // lancement (4 à 8 s à vide, bien plus sous charge) ; un délai trop court le déclarait indisponible.
            'check_timeout' => (int) env('YT_DLP_CHECK_TIMEOUT', 60),
            // Hauteur maximale téléchargée (le rendu vertical 1080x1920 n'exige pas davantage).
            'max_height' => (int) env('YT_DLP_MAX_HEIGHT', 1080),
            // Moteur JavaScript requis par l'extracteur YouTube (node, deno…).
            'js_runtime' => env('YT_DLP_JS_RUNTIME', 'node'),
            // Relais réseau de yt-dlp (http://, https://, socks5://, socks5h://, identifiants acceptés) : YouTube
            // refuse les adresses des hébergeurs (« Sign in to confirm you're not a bot »). Vide = connexion
            // directe. Secret : jamais journalisé ni renvoyé (identifiants masqués dans les extraits d'erreur).
            'proxy' => env('YT_DLP_PROXY'),
        ],
    ],

    /*
     * Composition Remotion du Clipper (ADR-051), isolée du Video Studio : paquet Node `renderer/` à la
     * racine du dépôt. browser_executable : Chrome local (évite tout téléchargement de navigateur).
     * Licence Remotion : gratuite pour un particulier ou une entreprise de 3 salariés au plus ;
     * au-delà, licence entreprise obligatoire (remotion.dev/license).
     */
    'remotion' => [
        'path' => env('REMOTION_RENDERER_PATH') ?: base_path('../renderer'),
        'node_binary' => env('REMOTION_NODE_BINARY', 'node'),
        'browser_executable' => env('REMOTION_BROWSER_EXECUTABLE') ?: null,
        // Onglets de rendu parallèles. 1 par défaut : mesuré le 2026-10-13 (4 cœurs, 8 Go, ~0,2-0,9 Go libres),
        // 120 images 1080x1920 → 1 onglet 120 s, 2 onglets 144 s, 3 onglets 356 s (mémoire saturée). À
        // augmenter seulement sur un serveur doté de mémoire libre (≥ 1 Go par onglet supplémentaire).
        'concurrency' => (int) env('REMOTION_CONCURRENCY', 1),
        // Moteur graphique de Chrome pour le rendu (angle = GPU sous Windows) ; vide = défaut de Remotion.
        'gl' => env('REMOTION_GL') ?: null,
        'timeout' => (int) env('REMOTION_TIMEOUT', 1500),
        // Délai de chaque étape Remotion (démarrage du navigateur, extraction d'une image…), en secondes.
        // Remotion utilise 30 s par défaut, dépassé sur une machine chargée (ADR-057) ; borné 10-600 s.
        'step_timeout' => (int) env('REMOTION_STEP_TIMEOUT', 120),
    ],

    'temp_path' => env('VIDEO_TEMP_PATH') ?: storage_path('app/private/tmp/video'),
    'temp_retention_hours' => (int) env('VIDEO_TEMP_RETENTION_HOURS', 24),

];
