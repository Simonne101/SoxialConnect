import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import type { PostDraftInput, PostFilters } from "@/types"
import * as posts from "@/services/posts"
import { qk } from "./keys"

export function usePosts(filters: PostFilters = {}) {
  return useQuery({
    queryKey: qk.posts.list(filters),
    queryFn: () => posts.listPosts(filters),
    // Rafraîchit tant qu'une publication est en cours (statut asynchrone du worker).
    refetchInterval: (query) =>
      query.state.data?.some((p) => p.status === "processing") ? 1500 : false,
  })
}

export function usePost(id: string | undefined) {
  return useQuery({
    queryKey: qk.posts.detail(id ?? ""),
    queryFn: () => posts.getPost(id as string),
    enabled: Boolean(id),
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
    mutationFn: (v: { input: PostDraftInput; mode: "draft" | "schedule" | "publish"; id?: string }) =>
      posts.savePost(v.input, v.mode, v.id),
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
  return useMutation({ mutationFn: posts.cancelSchedule, onSuccess: invalidate })
}

export function useReschedule() {
  const invalidate = useInvalidatePosts()
  return useMutation({
    mutationFn: (v: { id: string; scheduledAt: string }) => posts.reschedule(v.id, v.scheduledAt),
    onSuccess: invalidate,
  })
}

export function useRetryPost() {
  const invalidate = useInvalidatePosts()
  return useMutation({ mutationFn: posts.retryFailed, onSuccess: invalidate })
}
