import { useState } from "react"
import { useNavigate } from "react-router"
import { toast } from "sonner"
import { ConfirmDialog } from "@/components/shared/ConfirmDialog"
import {
  useCancelSchedule,
  useDeletePost,
  useDuplicatePost,
  useReschedule,
  useRetryPost,
} from "@/hooks/queries/content"
import { features, PUBLISHING_UNAVAILABLE } from "@/lib/features"
import { apiErrorMessage } from "@/lib/http"
import type { Post } from "@/types"
import { ScheduleDialog } from "./ScheduleDialog"
import { postTitle } from "./postUtils"

export interface PostActions {
  edit: (post: Post) => void
  duplicate: (post: Post) => void
  askDelete: (post: Post) => void
  cancel: (post: Post) => void
  askReschedule: (post: Post) => void
  retry: (post: Post) => void
}

/** Erreur d'API → toast lisible (validation, conflit de version, publication introuvable…). */
const onError = (fallback: string) => (error: unknown) => toast.error(apiErrorMessage(error, fallback))

/** Actions communes sur une publication (liste, brouillons, calendrier, détail) + leurs dialogues. */
export function usePostActions(onDone?: () => void) {
  const navigate = useNavigate()
  const [toDelete, setToDelete] = useState<Post | null>(null)
  const [toSchedule, setToSchedule] = useState<Post | null>(null)
  const del = useDeletePost()
  const dup = useDuplicatePost()
  const cancel = useCancelSchedule()
  const reschedule = useReschedule()
  const retry = useRetryPost()

  const actions: PostActions = {
    edit: (post) => navigate(`/content/${post.id}/edit`),
    duplicate: (post) =>
      dup.mutate(post.id, {
        onSuccess: () => toast.success("Publication dupliquée dans vos brouillons."),
        onError: onError("La duplication a échoué. Réessayez."),
      }),
    askDelete: setToDelete,
    cancel: (post) =>
      cancel.mutate(
        { id: post.id, lockVersion: post.lockVersion },
        {
          onSuccess: () => {
            toast.success("Programmation annulée. Vous pouvez la reprogrammer à tout moment.")
            onDone?.()
          },
          onError: onError("L'annulation a échoué. Réessayez."),
        },
      ),
    askReschedule: setToSchedule,
    retry: (post) => {
      if (!features.publishing) {
        toast.info(`${PUBLISHING_UNAVAILABLE}.`)
        return
      }
      retry.mutate(post.id, {
        onSuccess: () => toast.info("Nouvelle tentative de publication en cours…"),
        onError: onError("La nouvelle tentative a échoué."),
      })
    },
  }

  const dialogs = (
    <>
      <ConfirmDialog
        open={Boolean(toDelete)}
        onOpenChange={(o) => !o && setToDelete(null)}
        title="Supprimer cette publication ?"
        description={toDelete ? `« ${postTitle(toDelete)} » sera supprimée. Les publications déjà en ligne sur les réseaux ne sont pas retirées.` : ""}
        confirmLabel="Supprimer"
        onConfirm={() => {
          if (!toDelete) return
          del.mutate(toDelete.id, {
            onSuccess: () => {
              toast.success("Publication supprimée.")
              onDone?.()
            },
            onError: onError("La suppression a échoué. Réessayez."),
          })
          setToDelete(null)
        }}
      />
      {toSchedule && (
        <ScheduleDialog
          key={toSchedule.id}
          open
          onOpenChange={(o) => !o && setToSchedule(null)}
          initial={toSchedule.scheduledAt}
          confirmLabel={toSchedule.status === "scheduled" ? "Reprogrammer" : "Programmer"}
          pending={reschedule.isPending}
          onConfirm={(iso) =>
            reschedule.mutate(
              { id: toSchedule.id, scheduledAt: iso, lockVersion: toSchedule.lockVersion },
              {
                onSuccess: () => {
                  toast.success(toSchedule.status === "scheduled" ? "Publication reprogrammée." : "Publication programmée.")
                  setToSchedule(null)
                },
                onError: onError("La programmation a échoué. Réessayez."),
              },
            )
          }
        />
      )}
    </>
  )

  return { actions, dialogs }
}
