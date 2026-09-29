import { isSameDay } from "date-fns"
import type { Post } from "@/types"

export function postTitle(post: Post): string {
  return post.title?.trim() || post.body.slice(0, 60) || "Publication sans titre"
}

export function canEdit(post: Post) {
  return post.status === "draft" || post.status === "scheduled" || post.status === "failed"
}

export function postsOn(posts: Post[], day: Date) {
  return posts
    .filter((p) => p.scheduledAt && isSameDay(new Date(p.scheduledAt), day))
    .sort((a, b) => new Date(a.scheduledAt!).getTime() - new Date(b.scheduledAt!).getTime())
}
