import { FilePen, Pencil, Plus, Sparkles } from "lucide-react"
import { useState } from "react"
import { Link } from "react-router"
import { PostActionsMenu } from "@/components/content/PostActionsMenu"
import { PostPlatforms } from "@/components/content/PostBits"
import { postTitle } from "@/components/content/postUtils"
import { PostDetailDialog } from "@/components/content/PostDetailDialog"
import { usePostActions } from "@/components/content/usePostActions"
import { MediaThumb } from "@/components/media/MediaThumb"
import { PageHeader } from "@/components/shared/PageHeader"
import { CardsSkeleton, EmptyState, ErrorState } from "@/components/shared/States"
import { Button } from "@/components/ui/button"
import { usePosts } from "@/hooks/queries/content"
import { useMediaMap } from "@/hooks/useMediaMap"
import { formatRelative } from "@/lib/format"
import type { Post } from "@/types"

export default function DraftsPage() {
  const { data, isLoading, isError, refetch } = usePosts({ status: "draft" })
  const { actions, dialogs } = usePostActions()
  const mediaMap = useMediaMap()
  const [detail, setDetail] = useState<Post | null>(null)

  return (
    <>
      <PageHeader
        title="Brouillons"
        description="Vos publications en cours d'écriture. Reprenez-les quand vous voulez."
        actions={
          <Button asChild>
            <Link to="/content/create">
              <Plus /> Nouveau brouillon
            </Link>
          </Button>
        }
      />
      {isLoading ? (
        <CardsSkeleton count={6} className="xl:grid-cols-3" />
      ) : isError ? (
        <ErrorState onRetry={() => refetch()} />
      ) : !data?.length ? (
        <EmptyState icon={FilePen} title="Aucun brouillon" description="Quand vous enregistrez une publication sans la publier, elle apparaît ici." action={<Button asChild><Link to="/content/create"><Plus /> Commencer une publication</Link></Button>} />
      ) : (
        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {data.map((p) => (
            <li key={p.id} className="surface-card flex flex-col p-5">
              <div className="flex items-start gap-3">
                <MediaThumb media={p.mediaIds[0] ? mediaMap[p.mediaIds[0]] : undefined} className="size-12 shrink-0" showMeta={false} />
                <div className="min-w-0 flex-1">
                  <h2 className="truncate text-[15px] font-semibold">{postTitle(p)}</h2>
                  <p className="text-xs text-ink-muted">Modifié {formatRelative(p.updatedAt)}</p>
                </div>
                <PostActionsMenu post={p} actions={actions} onOpen={setDetail} />
              </div>
              <p className="mt-3 line-clamp-3 flex-1 text-sm text-ink-soft">{p.body}</p>
              <div className="mt-4 flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <PostPlatforms post={p} size="xs" />
                  {p.source === "ai" && (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-brand-600">
                      <Sparkles className="size-3.5" aria-hidden="true" /> IA
                    </span>
                  )}
                </div>
                <Button size="sm" variant="secondary" onClick={() => actions.edit(p)}>
                  <Pencil /> Continuer
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
      <PostDetailDialog post={detail} onOpenChange={(o) => !o && setDetail(null)} actions={actions} mediaMap={mediaMap} />
      {dialogs}
    </>
  )
}
