import { CalendarClock, CalendarX2, Copy, Eye, MoreHorizontal, Pencil, RotateCw, Trash2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { features, PUBLISHING_UNAVAILABLE } from "@/lib/features"
import type { Post } from "@/types"
import { canEdit, canReschedule, postTitle } from "./postUtils"
import type { PostActions } from "./usePostActions"

export function PostActionsMenu({ post, actions, onOpen }: { post: Post; actions: PostActions; onOpen?: (post: Post) => void }) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon-sm" aria-label={`Actions pour « ${postTitle(post)} »`}>
          <MoreHorizontal className="size-5" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        {onOpen && (
          <DropdownMenuItem onSelect={() => onOpen(post)}>
            <Eye /> Voir le détail
          </DropdownMenuItem>
        )}
        {canEdit(post) && (
          <DropdownMenuItem onSelect={() => actions.edit(post)}>
            <Pencil /> Modifier
          </DropdownMenuItem>
        )}
        <DropdownMenuItem onSelect={() => actions.duplicate(post)}>
          <Copy /> Dupliquer
        </DropdownMenuItem>
        {canReschedule(post) && (
          <DropdownMenuItem onSelect={() => actions.askReschedule(post)}>
            <CalendarClock /> {post.status === "scheduled" ? "Reprogrammer" : "Programmer"}
          </DropdownMenuItem>
        )}
        {post.status === "scheduled" && (
          <DropdownMenuItem onSelect={() => actions.cancel(post)}>
            <CalendarX2 /> Annuler la programmation
          </DropdownMenuItem>
        )}
        {(post.status === "failed" || post.status === "partially_published") && (
          <DropdownMenuItem disabled={!features.publishing} onSelect={() => actions.retry(post)}>
            <RotateCw /> Réessayer la publication
            {!features.publishing && <span className="sr-only"> ({PUBLISHING_UNAVAILABLE})</span>}
          </DropdownMenuItem>
        )}
        <DropdownMenuSeparator />
        <DropdownMenuItem variant="destructive" onSelect={() => actions.askDelete(post)}>
          <Trash2 /> Supprimer
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
