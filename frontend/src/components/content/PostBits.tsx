import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { PLATFORMS } from "@/lib/platforms"
import { POST_STATUS } from "@/lib/status"
import { cn } from "@/lib/utils"
import type { Post } from "@/types"

export function PostPlatforms({ post, size = "sm", max = 4, className }: { post: Post; size?: "xs" | "sm" | "md"; max?: number; className?: string }) {
  const platforms = [...new Set(post.variants.map((v) => v.platform))]
  const shown = platforms.slice(0, max)
  const rest = platforms.length - shown.length
  return (
    <span role="img" className={cn("flex items-center -space-x-1.5", className)} aria-label={`Réseaux : ${platforms.map((p) => PLATFORMS[p].name).join(", ")}`}>
      {shown.map((p) => (
        <PlatformIcon key={p} platform={p} size={size} className="ring-2 ring-card" />
      ))}
      {rest > 0 && (
        <span className="inline-flex size-7 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-ink-muted ring-2 ring-card">+{rest}</span>
      )}
    </span>
  )
}

export function PostStatusBadge({ post }: { post: Post }) {
  return <StatusBadge meta={POST_STATUS[post.status]} />
}
