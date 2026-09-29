// Types du domaine Clipping — alignés sur docs/database.md (video_projects, video_transcripts, video_clips).

export type VideoProjectStatus =
  | "pending"
  | "importing"
  | "analyzing"
  | "transcribing"
  | "detecting"
  | "ready"
  | "failed"
  | "cancelled"

export type AspectRatio = "9:16" | "1:1" | "16:9"

export type ClipStatus = "suggested" | "edited" | "rendering" | "rendered" | "failed" | "discarded"

export interface TranscriptSegment {
  id: string
  startMs: number
  endMs: number
  text: string
}

export interface SubtitleStyle {
  preset: "classic" | "bold" | "karaoke" | "minimal"
  position: "bottom" | "center" | "top"
}

export interface VideoClip {
  id: string
  projectId: string
  title: string
  startMs: number
  endMs: number
  score: number | null
  aspectRatio: AspectRatio
  subtitlesEnabled: boolean
  subtitleStyle: SubtitleStyle
  source: "ai" | "manual"
  status: ClipStatus
  renderProgress?: number
  reason: string | null
}

export interface VideoProject {
  id: string
  title: string
  sourceType: "upload" | "url"
  status: VideoProjectStatus
  durationMs: number | null
  language: string | null
  sizeBytes: number | null
  previewClassName: string
  transcript: TranscriptSegment[]
  clips: VideoClip[]
  createdAt: string
}
