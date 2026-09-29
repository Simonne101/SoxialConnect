import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import type { MediaFilters } from "@/types"
import * as media from "@/services/media"
import { qk } from "./keys"

/**
 * Les URLs de médias sont signées et expirent (15 min par défaut) : la liste est rafraîchie
 * avant expiration (docs/architecture.md — ne pas les garder en cache au-delà de leur validité).
 */
const URL_REFRESH_MS = 10 * 60 * 1000

export function useMedia(filters: MediaFilters = {}, options: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: qk.media.list(filters),
    queryFn: () => media.listMedia(filters),
    placeholderData: keepPreviousData,
    enabled: options.enabled ?? true,
    // Rafraîchit tant qu'un média est en cours de traitement (queue « media »).
    refetchInterval: (q) =>
      q.state.data?.items.some((m) => m.status === "uploading" || m.status === "processing") ? 2000 : URL_REFRESH_MS,
  })
}

/** Médias précis (vignettes des publications) — jamais toute la médiathèque. */
export function useMediaByIds(ids: string[]) {
  const sorted = [...new Set(ids)].sort()
  return useQuery({
    queryKey: qk.media.byIds(sorted),
    queryFn: () => media.getMediaByIds(sorted),
    enabled: sorted.length > 0,
    placeholderData: keepPreviousData,
    refetchInterval: URL_REFRESH_MS,
  })
}

export function useMediaFolders() {
  return useQuery({ queryKey: qk.media.folders, queryFn: media.listFolders })
}

export function useMediaTags() {
  return useQuery({ queryKey: qk.media.tags, queryFn: media.listTags })
}

export function useInvalidateMedia() {
  const qc = useQueryClient()
  return () => void qc.invalidateQueries({ queryKey: qk.media.all })
}

export function useCreateFolder() {
  const invalidate = useInvalidateMedia()
  return useMutation({ mutationFn: media.createFolder, onSuccess: invalidate })
}

export function useRenameFolder() {
  const invalidate = useInvalidateMedia()
  return useMutation({ mutationFn: media.renameFolder, onSuccess: invalidate })
}

export function useDeleteFolder() {
  const invalidate = useInvalidateMedia()
  return useMutation({ mutationFn: media.deleteFolder, onSuccess: invalidate })
}

export function useUpdateMedia() {
  const invalidate = useInvalidateMedia()
  return useMutation({
    mutationFn: (v: { id: string; patch: { name?: string; folderId?: string | null } }) => media.updateMedia(v.id, v.patch),
    onSuccess: invalidate,
  })
}

export function useMediaTag() {
  const invalidate = useInvalidateMedia()
  return useMutation({
    mutationFn: (v: { id: string; tag: string; action: "add" | "remove" }) =>
      v.action === "add" ? media.addTag(v.id, v.tag) : media.removeTag(v.id, v.tag),
    onSuccess: invalidate,
  })
}

export function useDeleteMedia() {
  const invalidate = useInvalidateMedia()
  return useMutation({
    mutationFn: (v: { ids: string[]; names?: Record<string, string> }) => media.deleteMedia(v.ids, v.names),
    onSuccess: invalidate,
  })
}
