import { isSameDay } from "date-fns"
import type { Post } from "@/types"

export function postTitle(post: Post): string {
  return post.title?.trim() || post.body.slice(0, 60) || "Publication sans titre"
}

/** Aligné sur Post::isEditable() (backend) : les publications publiées ne se modifient plus. */
export function canEdit(post: Post) {
  return post.status === "draft" || post.status === "scheduled" || post.status === "failed" || post.status === "cancelled"
}

/** (Re)programmable : brouillon, programmée ou annulée (ReschedulePost côté backend). */
export function canReschedule(post: Post) {
  return post.status === "draft" || post.status === "scheduled" || post.status === "cancelled"
}

export function postsOn(posts: Post[], day: Date) {
  return posts
    .filter((p) => p.scheduledAt && isSameDay(new Date(p.scheduledAt), day))
    .sort((a, b) => new Date(a.scheduledAt!).getTime() - new Date(b.scheduledAt!).getTime())
}
