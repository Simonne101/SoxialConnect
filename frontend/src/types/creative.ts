// AI Creative Studio (ADR-046) : YouTube Clipper et génération d'images, alignés sur
// backend/app/Http/Resources/ClipperProjectResource.php, VideoClipResource.php et
// AiImageGenerationResource.php. Temps en millisecondes entières.
import type { MediaAsset } from "./media"

export type ClipAspectRatio = "9:16" | "1:1" | "16:9"
export type ClipperProjectStatus = "pending" | "analyzing" | "transcribing" | "detecting" | "ready" | "failed" | "cancelled"
export type ClipperClipStatus = "suggested" | "edited" | "rendering" | "rendered" | "failed" | "discarded"
/** Étape d'un rendu en cours (ADR-047). */
export type ClipRenderStage = "preparing" | "rendering" | "validating" | "publishing"

/** Sous-étape réelle d'un rendu en cours : découpe FFmpeg, composition Remotion (avec %), secours FFmpeg… */
export interface ClipRenderProgress {
  step: "preparing" | "cutting" | "composing" | "fallback" | "validating" | "publishing"
  percent: number | null
}
export type ClipSubtitlePosition = "bottom" | "center" | "top"
export type ClipSubtitleSize = "small" | "medium" | "large"
export interface ClipSubtitleStyle {
  position: ClipSubtitlePosition
  size: ClipSubtitleSize
}
/** « ai » : IA ; « local » : analyse locale sans IA ; « none » : aucune analyse automatique. */
export type ClipAnalysisMode = "ai" | "local" | "none"

/** Critères notés par l'IA (Clip Intelligence Engine, ADR-051). */
export interface ClipScoreBreakdown {
  hook: number
  value: number
  autonomy: number
  emotion: number
  rhythm: number
  quality: number
}

export interface ClipperClip {
  id: string
  title: string
  topic: string | null
  reason: string | null
  startMs: number
  endMs: number
  durationMs: number
  aspectRatio: ClipAspectRatio
  /** « ai » : proposé par l'IA ; « auto » : proposé par l'analyse locale (sans IA) ; « manual » : choisi. */
  source: "ai" | "auto" | "manual"
  status: ClipperClipStatus
  renderStage: ClipRenderStage | null
  renderProgress: ClipRenderProgress | null
  /** Rendu demandé, pas encore pris en charge par un worker : attente en file (ms). */
  queuedMs: number | null
  subtitles: boolean
  subtitleStyle: ClipSubtitleStyle | null
  /** Score global 0-100, calculé par le backend à partir des critères (ADR-051). */
  score: number | null
  /** Accroche affichable proposée par l'IA (absente pour l'analyse locale et les passages manuels). */
  hook: string | null
  /** Confiance de l'IA dans ce choix, 0-100. */
  confidence: number | null
  /** Notes par critère (0-100), base du score global. */
  scoreBreakdown: ClipScoreBreakdown | null
  errorCode: string | null
  errorMessage: string | null
  /** Clip publié : média de la médiathèque (URLs signées). */
  output: MediaAsset | null
  /** Moteur réellement utilisé pour le MP4 publié (null : clip non prêt, ou antérieur à cette information). */
  render: ClipRenderInfo | null
  createdAt: string
  finishedAt: string | null
}

/** Moteur du rendu publié : Remotion (composition), ou rendu de secours FFmpeg après un échec de Remotion. */
export interface ClipRenderInfo {
  engine: "remotion" | "ffmpeg" | null
  /** Son vérifié avant publication ; « source_without_audio » / « source_silent » : la source elle-même est muette. */
  audio: "ok" | "source_without_audio" | "source_silent" | null
  /** Remotion a échoué : clip produit par FFmpeg, sous-titres compris mais sans l'accroche. */
  fallback: boolean
  /** Accroche incrustée dans la vidéo. */
  hook: boolean
}

export interface ClipperProject {
  id: string
  title: string
  status: ClipperProjectStatus
  youtubeUrl: string
  youtubeVideoId: string | null
  youtubeAuthor: string | null
  /** Miniature (CDN d'images de YouTube uniquement). */
  youtubeThumbnail: string | null
  /** Vidéo source (fichier de la médiathèque) jointe : le rendu est possible. */
  renderable: boolean
  aspectRatio: ClipAspectRatio
  durationMs: number | null
  errorCode: string | null
  errorMessage: string | null
  /** Ce que l'analyse a réellement fait (null tant qu'elle n'est pas terminée). */
  analysis: {
    mode: ClipAnalysisMode
    /** « youtube_url » : passages proposés depuis le lien ; « file » : depuis la vidéo source. */
    input: "youtube_url" | "file"
    transcription: "local" | "ai" | null
    transcriptionEngine: string | null
    fallback: string | null
    /** Passages issus du lien recalés sur la source réelle (ADR-049). */
    recalibrated: number | null
    notes: string[]
  } | null
  /** Acquisition de la source vidéo (ADR-049) : statut réel, jamais supposé. */
  sourceStatus: ClipperSourceStatus
  /** Transcription disponible : sous-titres possibles. */
  hasTranscript: boolean
  /** Règle métier : 60 000 ms maximum par clip. */
  maxClipMs: number
  minClipMs: number
  source: MediaAsset | null
  clips: ClipperClip[]
  createdAt: string
}

export type ClipperSourceState = "pending" | "resolving" | "waiting_for_provider" | "ready" | "failed" | "expired" | "unavailable"

export interface ClipperSourceStatus {
  status: ClipperSourceState
  ownership: "unverified" | "owned" | "not_owned"
  /** Voie d'acquisition : export officiel, fournisseur sous contrat, yt-dlp (ADR-059) ou import de l'utilisateur. */
  method: "data_portability" | "external" | "import" | "library" | "yt_dlp" | null
  errorCode: string | null
  errorMessage: string | null
  purgeAt: string | null
  /** L'utilisateur a attesté détenir les droits ou l'autorisation d'utiliser la vidéo. */
  rightsAttested: boolean
  /** Récupération automatique d'une vidéo publique (yt-dlp) active sur le serveur. */
  downloadAvailable: boolean
}

/** Capacités du serveur : moteur FFmpeg et analyse IA configurés. */
export interface ClipperCapabilities {
  rendererAvailable: boolean
  analysisAvailable: boolean
  analysisMode: ClipAnalysisMode
  /** Analyse directe depuis le lien YouTube (fournisseur compatible, ex. Gemini). */
  urlAnalysisAvailable: boolean
  urlAnalysisProvider: string | null
  /** Au moins un fournisseur d'acquisition automatique de la source est activé (ADR-049). */
  automaticSourceAvailable: boolean
  /** Récupération d'une vidéo publique (yt-dlp) après attestation de droits (ADR-059). */
  videoDownloadAvailable: boolean
  transcriptionMode: "local" | "ai" | "none"
  transcriptionEngine: string | null
}

/** Vidéo YouTube identifiée depuis son lien (aperçu avant analyse). */
export interface YouTubeVideoPreview {
  videoId: string
  url: string
  title: string
  author: string | null
  thumbnail: string | null
}

export type ImageAspectRatio = "1:1" | "4:5" | "9:16" | "16:9"

export interface AiImageGeneration {
  id: string
  status: "pending" | "processing" | "succeeded" | "failed"
  prompt: string | null
  aspectRatio: ImageAspectRatio | null
  width: number | null
  height: number | null
  generatedWidth: number | null
  generatedHeight: number | null
  errorCode: string | null
  errorMessage: string | null
  /** Image enregistrée : média de la médiathèque. */
  media: MediaAsset | null
  createdAt: string
}
