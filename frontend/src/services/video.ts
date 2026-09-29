// Service Clipping — futur : /api/v1/video-projects (workers FFmpeg + IA côté serveur).
// Ici : pipeline simulé, aucune vidéo n'est traitée.
import type { AspectRatio, SubtitleStyle, VideoClip, VideoProject, VideoProjectStatus } from "@/types"
import { uid } from "@/mocks/helpers"
import { mockVideoProjects } from "@/mocks/videos"
import { delay, notFound, store } from "./mockStore"

export async function listVideoProjects(): Promise<VideoProject[]> {
  await delay(300)
  return structuredClone(store.videoProjects)
}

export async function getVideoProject(id: string): Promise<VideoProject> {
  await delay(200)
  const p = store.videoProjects.find((x) => x.id === id)
  return p ? structuredClone(p) : notFound("Projet vidéo")
}

const PIPELINE: VideoProjectStatus[] = ["importing", "analyzing", "transcribing", "detecting", "ready"]

/** Crée un projet et fait avancer le pipeline simulé toutes les ~1,6 s. */
export async function createVideoProject(file: { name: string; size: number }): Promise<VideoProject> {
  await delay(300)
  const template = mockVideoProjects[0]
  const id = uid("vp")
  const project: VideoProject = {
    id,
    title: file.name.replace(/\.[^.]+$/, ""),
    sourceType: "upload",
    status: "importing",
    durationMs: null,
    language: null,
    sizeBytes: file.size,
    previewClassName: "thumb-6",
    transcript: [],
    clips: [],
    createdAt: new Date().toISOString(),
  }
  store.videoProjects.unshift(project)
  PIPELINE.slice(1).forEach((status, i) => {
    setTimeout(() => {
      const p = store.videoProjects.find((x) => x.id === id)
      if (!p) return
      p.status = status
      if (status === "transcribing") {
        p.durationMs = template.durationMs
        p.language = "fr"
      }
      if (status === "detecting") p.transcript = structuredClone(template.transcript)
      if (status === "ready") {
        p.clips = template.clips.map((c) => ({ ...structuredClone(c), id: uid("clip"), projectId: id, status: "suggested" }))
      }
    }, 1600 * (i + 1))
  })
  return structuredClone(project)
}

export interface ClipPatch {
  title?: string
  startMs?: number
  endMs?: number
  aspectRatio?: AspectRatio
  subtitlesEnabled?: boolean
  subtitleStyle?: SubtitleStyle
  status?: VideoClip["status"]
}

function findClip(projectId: string, clipId: string): VideoClip {
  const project = store.videoProjects.find((p) => p.id === projectId) ?? notFound("Projet vidéo")
  return project.clips.find((c) => c.id === clipId) ?? notFound("Clip")
}

export async function updateClip(projectId: string, clipId: string, patch: ClipPatch): Promise<VideoClip> {
  await delay(200)
  const clip = findClip(projectId, clipId)
  Object.assign(clip, patch)
  if (clip.status === "suggested" && (patch.startMs !== undefined || patch.endMs !== undefined || patch.title !== undefined)) {
    clip.status = "edited"
  }
  return structuredClone(clip)
}

/** Simule le rendu FFmpeg côté worker (progression puis « rendered »). */
export async function exportClip(projectId: string, clipId: string): Promise<VideoClip> {
  await delay(200)
  const clip = findClip(projectId, clipId)
  clip.status = "rendering"
  clip.renderProgress = 0
  const tick = setInterval(() => {
    clip.renderProgress = Math.min(100, (clip.renderProgress ?? 0) + 20)
    if (clip.renderProgress >= 100) {
      clearInterval(tick)
      clip.status = "rendered"
      clip.renderProgress = undefined
    }
  }, 600)
  return structuredClone(clip)
}

export async function addManualClip(projectId: string, startMs: number): Promise<VideoClip> {
  await delay(200)
  const project = store.videoProjects.find((p) => p.id === projectId) ?? notFound("Projet vidéo")
  const clip: VideoClip = {
    id: uid("clip"),
    projectId,
    title: "Nouveau clip",
    startMs,
    endMs: Math.min(startMs + 30_000, project.durationMs ?? startMs + 30_000),
    score: null,
    aspectRatio: "9:16",
    subtitlesEnabled: true,
    subtitleStyle: { preset: "classic", position: "bottom" },
    source: "manual",
    status: "edited",
    reason: null,
  }
  project.clips.push(clip)
  return structuredClone(clip)
}
