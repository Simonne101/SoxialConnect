import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import type { MediaAsset, MediaFilters } from "@/types"
import * as media from "@/services/media"
import { qk } from "./keys"

export function useMedia(filters: MediaFilters = {}) {
  return useQuery({
    queryKey: qk.media.list(filters),
    queryFn: () => media.listMedia(filters),
    refetchInterval: (q) =>
      q.state.data?.some((m) => m.status === "uploading" || m.status === "processing") ? 500 : false,
  })
}

export function useMediaFolders() {
  return useQuery({ queryKey: qk.media.folders, queryFn: media.listFolders })
}

export function useMediaTags() {
  return useQuery({ queryKey: qk.media.tags, queryFn: media.listTags })
}

function useInvalidateMedia() {
  const qc = useQueryClient()
  return () => void qc.invalidateQueries({ queryKey: qk.media.all })
}

export function useCreateFolder() {
  const invalidate = useInvalidateMedia()
  return useMutation({ mutationFn: media.createFolder, onSuccess: invalidate })
}

export function useUpdateMedia() {
  const invalidate = useInvalidateMedia()
  return useMutation({
    mutationFn: (v: { id: string; patch: Pick<Partial<MediaAsset>, "name" | "tags" | "folderId"> }) =>
      media.updateMedia(v.id, v.patch),
    onSuccess: invalidate,
  })
}

export function useDeleteMedia() {
  const invalidate = useInvalidateMedia()
  return useMutation({ mutationFn: media.deleteMedia, onSuccess: invalidate })
}

/**
 * Upload simulé : crée le média puis fait progresser l'envoi et le traitement.
 * En production : URL présignée + envoi direct vers S3 + job de validation (docs/architecture.md §4.6).
 */
export function useUploadMedia() {
  const invalidate = useInvalidateMedia()
  return useMutation({
    mutationFn: async (v: { files: File[]; folderId: string | null }) => {
      const accepted: MediaAsset[] = []
      const rejected: { name: string; reason: string }[] = []
      for (const file of v.files) {
        const check = media.validateFile(file)
        if (!check.ok) {
          rejected.push({ name: file.name, reason: check.reason })
          continue
        }
        const asset = await media.startUpload(file, check.type, v.folderId)
        accepted.push(asset)
        let progress = 0
        const timer = setInterval(() => {
          progress += 20
          void media.updateUploadProgress(asset.id, progress)
          if (progress >= 100) {
            clearInterval(timer)
            setTimeout(() => void media.completeProcessing(asset.id), 1200)
          }
        }, 300)
      }
      return { accepted, rejected }
    },
    onSuccess: invalidate,
  })
}
