import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import * as video from "@/services/video"
import type { ClipPatch } from "@/services/video"
import { qk } from "./keys"

const isBusy = (status: string) => !["ready", "failed", "cancelled"].includes(status)

export function useVideoProjects() {
  return useQuery({
    queryKey: qk.videoProjects.all,
    queryFn: video.listVideoProjects,
    refetchInterval: (q) => (q.state.data?.some((p) => isBusy(p.status)) ? 1000 : false),
  })
}

export function useVideoProject(id: string | null) {
  return useQuery({
    queryKey: qk.videoProjects.detail(id ?? ""),
    queryFn: () => video.getVideoProject(id as string),
    enabled: Boolean(id),
    refetchInterval: (q) => {
      const p = q.state.data
      if (!p) return false
      return isBusy(p.status) || p.clips.some((c) => c.status === "rendering") ? 700 : false
    },
  })
}

function useInvalidateVideo() {
  const qc = useQueryClient()
  return () => void qc.invalidateQueries({ queryKey: qk.videoProjects.all })
}

export function useCreateVideoProject() {
  const invalidate = useInvalidateVideo()
  return useMutation({ mutationFn: video.createVideoProject, onSuccess: invalidate })
}

export function useUpdateClip() {
  const invalidate = useInvalidateVideo()
  return useMutation({
    mutationFn: (v: { projectId: string; clipId: string; patch: ClipPatch }) =>
      video.updateClip(v.projectId, v.clipId, v.patch),
    onSuccess: invalidate,
  })
}

export function useExportClip() {
  const invalidate = useInvalidateVideo()
  return useMutation({
    mutationFn: (v: { projectId: string; clipId: string }) => video.exportClip(v.projectId, v.clipId),
    onSuccess: invalidate,
  })
}

export function useAddClip() {
  const invalidate = useInvalidateVideo()
  return useMutation({
    mutationFn: (v: { projectId: string; startMs: number }) => video.addManualClip(v.projectId, v.startMs),
    onSuccess: invalidate,
  })
}
