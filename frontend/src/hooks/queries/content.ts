import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import type { PostDraftInput, PostFilters } from "@/types"
import * as posts from "@/services/posts"
import { qk } from "./keys"

/** Liste paginée ; la page précédente reste affichée pendant le chargement de la suivante. */
export function usePosts(filters: PostFilters = {}) {
  return useQuery({
    queryKey: qk.posts.list(filters),
    queryFn: () => posts.listPosts(filters),
    placeholderData: keepPreviousData,
    // Rafraîchit tant qu'une publication est en cours (statut asynchrone du worker).
    refetchInterval: (query) =>
      query.state.data?.items.some((p) => p.status === "processing") ? 1500 : false,
  })
}

/** Publications programmées sur une période (calendrier), toutes pages confondues. */
export function useCalendarPosts(filters: Omit<PostFilters, "page" | "perPage"> & { from: string; to: string }) {
  return useQuery({
    queryKey: qk.posts.range(filters),
    queryFn: () => posts.listPostsInRange(filters),
    placeholderData: keepPreviousData,
  })
}

export function usePost(id: string | undefined) {
  return useQuery({
    queryKey: qk.posts.detail(id ?? ""),
    queryFn: () => posts.getPost(id as string),
    enabled: Boolean(id),
    // Une publication introuvable (404) ne se réessaie pas.
    retry: false,
    // Envoi en cours : le statut est rafraîchi jusqu'au résultat du worker.
    refetchInterval: (query) => (query.state.data?.status === "processing" ? 1500 : false),
  })
}

/** Historique des tentatives d'envoi (rafraîchi tant que la publication est en cours). */
export function usePostAttempts(id: string | undefined, processing = false) {
  return useQuery({
    queryKey: qk.posts.attempts(id ?? ""),
    queryFn: () => posts.listAttempts(id as string),
    enabled: Boolean(id),
    retry: false,
    refetchInterval: processing ? 1500 : false,
  })
}

function useInvalidatePosts() {
  const qc = useQueryClient()
  return () => {
    void qc.invalidateQueries({ queryKey: qk.posts.all })
    void qc.invalidateQueries({ queryKey: qk.dashboard })
  }
}

export function useSavePost() {
  const invalidate = useInvalidatePosts()
  return useMutation({
    mutationFn: (v: { input: PostDraftInput; mode: posts.SaveMode; id?: string; lockVersion?: number }) =>
      posts.savePost(v.input, v.mode, v.id, v.lockVersion),
    onSuccess: invalidate,
  })
}

export function useDeletePost() {
  const invalidate = useInvalidatePosts()
  return useMutation({ mutationFn: posts.deletePost, onSuccess: invalidate })
}

export function useDuplicatePost() {
  const invalidate = useInvalidatePosts()
  return useMutation({ mutationFn: posts.duplicatePost, onSuccess: invalidate })
}

export function useCancelSchedule() {
  const invalidate = useInvalidatePosts()
  return useMutation({
    mutationFn: (v: { id: string; lockVersion?: number }) => posts.cancelSchedule(v.id, v.lockVersion),
    // Succès ou conflit (409) : la liste est rechargée pour refléter l'état réel.
    onSettled: invalidate,
  })
}

export function useReschedule() {
  const invalidate = useInvalidatePosts()
  return useMutation({
    mutationFn: (v: { id: string; scheduledAt: string; lockVersion?: number }) => posts.reschedule(v.id, v.scheduledAt, v.lockVersion),
    onSettled: invalidate,
  })
}

export function useRetryPost() {
  const invalidate = useInvalidatePosts()
  return useMutation({
    mutationFn: (v: { id: string; lockVersion?: number; confirmUnknown?: boolean }) => posts.retryFailed(v.id, v.lockVersion, v.confirmUnknown),
    onSettled: invalidate,
  })
}
