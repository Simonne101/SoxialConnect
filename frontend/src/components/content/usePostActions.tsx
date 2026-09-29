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
      }),
    askDelete: setToDelete,
    cancel: (post) =>
      cancel.mutate(post.id, {
        onSuccess: () => {
          toast.success("Programmation annulée : la publication est repassée en brouillon.")
          onDone?.()
        },
      }),
    askReschedule: setToSchedule,
    retry: (post) =>
      retry.mutate(post.id, {
        onSuccess: () => toast.info("Nouvelle tentative de publication en cours…"),
      }),
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
          confirmLabel="Reprogrammer"
          pending={reschedule.isPending}
          onConfirm={(iso) =>
            reschedule.mutate(
              { id: toSchedule.id, scheduledAt: iso },
              {
                onSuccess: () => {
                  toast.success("Publication reprogrammée.")
                  setToSchedule(null)
                },
              },
            )
          }
        />
      )}
    </>
  )

  return { actions, dialogs }
}
