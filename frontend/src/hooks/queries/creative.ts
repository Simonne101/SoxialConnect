import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { ApiError } from "@/lib/http"
import * as creative from "@/services/creative"
import type { ClipperProject } from "@/types"
import { qk } from "./keys"

const ANALYZING: ClipperProject["status"][] = ["pending", "analyzing", "transcribing", "detecting"]

/** Analyse ou génération de clip en cours : le projet est relu régulièrement. */
const isBusy = (p: ClipperProject | undefined) => Boolean(p && (ANALYZING.includes(p.status) || p.clips.some((c) => c.status === "rendering")))
/** Source vidéo en cours d'acquisition (ADR-049) : relue moins souvent (un fournisseur peut prendre des heures). */
const isAcquiring = (p: ClipperProject | undefined) => Boolean(p && ["pending", "resolving", "waiting_for_provider"].includes(p.sourceStatus.status))

export const useClipperProjects = () =>
  useQuery({
    queryKey: qk.clipper.projects,
    queryFn: creative.listClipperProjects,
    refetchInterval: (query) => (query.state.data?.projects.some((p) => ANALYZING.includes(p.status)) ? 5000 : false),
  })

export function useClipperProject(id: string | undefined) {
  return useQuery({
    queryKey: qk.clipper.project(id ?? ""),
    queryFn: () => creative.getClipperProject(id as string),
    enabled: Boolean(id),
    // Erreur passagère (serveur occupé, réseau) : deux nouveaux essais ; jamais pour un projet absent ou interdit.
    retry: (failures, error) => failures < 2 && !(error instanceof ApiError && [401, 403, 404].includes(error.status)),
    // Le suivi continue tant que le DERNIER état connu est en cours, même après un échec de rafraîchissement.
    refetchInterval: (query) => (isBusy(query.state.data?.project) ? 3000 : isAcquiring(query.state.data?.project) ? 10_000 : false),
  })
}

export function useCreateClipperProject() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: creative.createClipperProject,
    onSuccess: () => qc.invalidateQueries({ queryKey: qk.clipper.projects }),
  })
}

export function useAttachClipperSource(projectId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (mediaId: string) => creative.attachClipperSource(projectId, mediaId),
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: qk.clipper.project(projectId) })
      void qc.invalidateQueries({ queryKey: qk.clipper.projects })
    },
  })
}

export function useReanalyzeClipper(projectId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => creative.reanalyzeClipperProject(projectId),
    onSettled: () => void qc.invalidateQueries({ queryKey: qk.clipper.project(projectId) }),
  })
}

export function useCancelClipper(projectId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => creative.cancelClipperProject(projectId),
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: qk.clipper.project(projectId) })
      void qc.invalidateQueries({ queryKey: qk.clipper.projects })
    },
  })
}

export function useAcquireClipperSource(projectId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (rightsConfirmed: boolean = false) => creative.acquireClipperSource(projectId, rightsConfirmed),
    onSettled: () => void qc.invalidateQueries({ queryKey: qk.clipper.project(projectId) }),
  })
}

/** Redirige vers l'écran de consentement Google (export officiel de la chaîne). */
export function useStartPortabilityConsent(projectId: string) {
  return useMutation({ mutationFn: () => creative.startPortabilityConsent(projectId) })
}

export function useDeleteClipperProject() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: creative.deleteClipperProject,
    onSuccess: () => qc.invalidateQueries({ queryKey: qk.clipper.projects }),
  })
}

/** Demande d'un clip (proposé ou manuel) : le projet est rechargé, et la médiathèque à la fin. */
export function useRequestClip(projectId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (vars: { suggestionId?: string } & Partial<creative.ClipInput>) =>
      vars.suggestionId
        ? creative.renderSuggestion(vars.suggestionId, vars)
        : creative.createClip(projectId, { startMs: vars.startMs ?? 0, endMs: vars.endMs ?? 0, aspectRatio: vars.aspectRatio, title: vars.title, subtitles: vars.subtitles, subtitleStyle: vars.subtitleStyle }),
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: qk.clipper.project(projectId) })
      void qc.invalidateQueries({ queryKey: qk.media.all })
    },
  })
}

/** Historique des images ; relu tant qu'une génération est en file ou en cours. */
export const useImageGenerations = () =>
  useQuery({
    queryKey: qk.aiImages,
    queryFn: creative.listImageGenerations,
    refetchInterval: (query) => (query.state.data?.generations.some((g) => g.status === "pending" || g.status === "processing") ? 3000 : false),
  })

export function useGenerateImage() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: creative.generateImage,
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: qk.aiImages })
      void qc.invalidateQueries({ queryKey: qk.media.all })
    },
  })
}
