import { AlertCircle, CalendarClock, CalendarX2, Copy, ExternalLink, Pencil, RotateCw, Trash2 } from "lucide-react"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { MediaThumb } from "@/components/media/MediaThumb"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { usePost } from "@/hooks/queries/content"
import { features, PUBLISHING_UNAVAILABLE } from "@/lib/features"
import { formatCompact, formatDate } from "@/lib/format"
import { PLATFORMS } from "@/lib/platforms"
import { POST_STATUS, VARIANT_STATUS } from "@/lib/status"
import type { MediaAsset, Post } from "@/types"
import { canEdit, canReschedule, postTitle } from "./postUtils"
import type { PostActions } from "./usePostActions"

export function PostDetailDialog({
  post: listed,
  onOpenChange,
  actions,
  mediaMap,
}: {
  post: Post | null
  onOpenChange: (open: boolean) => void
  actions: PostActions
  mediaMap: Record<string, MediaAsset>
}) {
  const close = () => onOpenChange(false)
  // Détail à jour (GET /content/posts/{id}) ; la version de la liste s'affiche en attendant.
  const detail = usePost(listed?.id)
  const post = listed ? (detail.data?.id === listed.id ? detail.data : listed) : null
  const gone = detail.isError
  return (
    <Dialog open={Boolean(listed)} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-[640px]">
        {post && (
          <>
            <DialogHeader>
              <div className="flex flex-wrap items-center gap-2">
                <StatusBadge meta={POST_STATUS[post.status]} />
                {post.source === "ai" && <span className="text-xs font-medium text-brand-600">Créée avec l'IA</span>}
                {post.source === "clip" && <span className="text-xs font-medium text-violet-600">Issue d'un clip vidéo</span>}
              </div>
              <DialogTitle className="text-xl">{postTitle(post)}</DialogTitle>
              <DialogDescription>
                {post.scheduledAt
                  ? `${post.status === "published" ? "Publiée" : "Prévue"} le ${formatDate(post.scheduledAt, "EEEE d MMMM yyyy 'à' HH:mm")}`
                  : `Brouillon modifié le ${formatDate(post.updatedAt, "d MMMM yyyy")}`}
              </DialogDescription>
            </DialogHeader>

            {gone && (
              <p role="alert" className="flex items-start gap-2 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700">
                <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" /> Cette publication est introuvable : elle a peut-être été supprimée.
              </p>
            )}

            <p className="rounded-xl bg-page p-4 text-sm leading-relaxed whitespace-pre-line text-ink-soft">{post.body}</p>

            {post.mediaIds.length > 0 && (
              <div className="flex gap-2 overflow-x-auto pb-1">
                {post.mediaIds.map((id) => (
                  <MediaThumb key={id} media={mediaMap[id]} className="size-20 shrink-0" />
                ))}
              </div>
            )}

            <section aria-labelledby="variants-title">
              <h3 id="variants-title" className="text-sm font-semibold">Statut par réseau</h3>
              <ul className="mt-2 space-y-2">
                {post.variants.map((v) => (
                  <li key={v.id} className="rounded-xl border border-border p-3">
                    <div className="flex items-center gap-3">
                      <PlatformIcon platform={v.platform} size="sm" />
                      <span className="flex-1 text-sm font-medium text-ink">{PLATFORMS[v.platform].name}</span>
                      <StatusBadge meta={VARIANT_STATUS[v.status]} />
                      {v.externalUrl && (
                        <Button variant="ghost" size="icon-xs" aria-label={`Voir sur ${PLATFORMS[v.platform].name} (démo)`} disabled>
                          <ExternalLink />
                        </Button>
                      )}
                    </div>
                    {v.errorMessage && (
                      <p className="mt-2 flex items-start gap-2 rounded-lg bg-danger-50 px-3 py-2 text-xs text-danger-700">
                        <AlertCircle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" /> {v.errorMessage}
                      </p>
                    )}
                  </li>
                ))}
              </ul>
            </section>

            {post.metrics && (
              <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {(
                  [
                    ["Impressions", post.metrics.impressions],
                    ["Portée", post.metrics.reach],
                    ["Engagement", post.metrics.engagements],
                    ["Clics", post.metrics.clicks],
                  ] as const
                ).map(([label, value]) => (
                  <div key={label} className="rounded-xl border border-border p-3">
                    <dt className="text-xs text-ink-muted">{label}</dt>
                    <dd className="text-lg font-bold text-ink">{formatCompact(value)}</dd>
                  </div>
                ))}
              </dl>
            )}

            <DialogFooter className="flex-wrap gap-2 sm:justify-between">
              <Button variant="destructive-soft" onClick={() => { close(); actions.askDelete(post) }}>
                <Trash2 /> Supprimer
              </Button>
              <div className="flex flex-wrap gap-2">
                <Button variant="outline" onClick={() => { close(); actions.duplicate(post) }}>
                  <Copy /> Dupliquer
                </Button>
                {post.status === "scheduled" && (
                  <Button variant="outline" disabled={gone} onClick={() => { close(); actions.cancel(post) }}>
                    <CalendarX2 /> Annuler
                  </Button>
                )}
                {canReschedule(post) && (
                  <Button variant="outline" disabled={gone} onClick={() => { close(); actions.askReschedule(post) }}>
                    <CalendarClock /> {post.status === "scheduled" ? "Reprogrammer" : "Programmer"}
                  </Button>
                )}
                {(post.status === "failed" || post.status === "partially_published") && (
                  <Button variant="outline" disabled={!features.publishing} title={features.publishing ? undefined : PUBLISHING_UNAVAILABLE} onClick={() => { close(); actions.retry(post) }}>
                    <RotateCw /> Réessayer
                  </Button>
                )}
                {canEdit(post) && !gone && (
                  <Button onClick={() => { close(); actions.edit(post) }}>
                    <Pencil /> Modifier
                  </Button>
                )}
              </div>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
