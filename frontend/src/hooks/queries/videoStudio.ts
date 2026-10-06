import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import * as studio from "@/services/videoStudio"
import { qk } from "./keys"

/** Projets Video Studio de l'utilisateur. */
export const useStudioProjects = () => useQuery({ queryKey: qk.studio.projects, queryFn: studio.listProjects })

/** Projet complet pour l'éditeur ; rafraîchi tant qu'un rendu est en file ou en cours. */
export function useStudioProject(id: string | undefined) {
  return useQuery({
    queryKey: qk.studio.project(id ?? ""),
    queryFn: () => studio.getProject(id as string),
    enabled: Boolean(id),
    retry: false,
    refetchInterval: (query) =>
      query.state.data?.project.renders.some((r) => r.status === "queued" || r.status === "rendering") ? 3000 : false,
  })
}

export function useCreateStudioProject() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: studio.createProject,
    onSuccess: () => qc.invalidateQueries({ queryKey: qk.studio.projects }),
  })
}

export function useDeleteStudioProject() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: studio.deleteProject,
    onSuccess: () => qc.invalidateQueries({ queryKey: qk.studio.projects }),
  })
}

/**
 * Écriture dans un projet ouvert (scènes, pistes, éléments, médias, rendus) : le serveur reste la
 * source de vérité, le projet est rechargé après chaque action, réussie ou refusée (version périmée).
 */
export function useStudioAction<TVars, TResult = void>(projectId: string, action: (vars: TVars) => Promise<TResult>) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: action,
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: qk.studio.project(projectId) })
      void qc.invalidateQueries({ queryKey: qk.studio.projects })
    },
  })
}
