import type { LucideIcon } from "lucide-react"
import {
  AlertTriangle,
  CalendarPlus,
  ChevronRight,
  Clapperboard,
  ImagePlus,
  PlugZap,
  Send,
  Sparkles,
  SquarePen,
  TriangleAlert,
  XCircle,
} from "lucide-react"
import { Link } from "react-router"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { SectionCard } from "@/components/shared/SectionCard"
import { EmptyState, ListSkeleton } from "@/components/shared/States"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Button } from "@/components/ui/button"
import { useNow } from "@/hooks/useNow"
import { formatRelative, formatSlot } from "@/lib/format"
import { PLATFORMS } from "@/lib/platforms"
import { ACCOUNT_STATUS } from "@/lib/status"
import { cn } from "@/lib/utils"
import type { ActivityItem, Post, SocialAccount } from "@/types"

export function AlertsBanner({ accounts, failedPosts }: { accounts: SocialAccount[]; failedPosts: Post[] }) {
  const problems = accounts.filter((a) => a.status === "expired" || a.status === "permission_required" || a.status === "error")
  if (problems.length === 0 && failedPosts.length === 0) return null
  return (
    <section aria-labelledby="alerts-title" className="mt-6 rounded-2xl border border-warning-100 bg-warning-50/70 p-3 sm:p-4">
      <h2 id="alerts-title" className="flex items-center gap-2 text-sm font-semibold text-warning-700">
        <TriangleAlert className="size-4" aria-hidden="true" /> Actions requises ({problems.length + failedPosts.length})
      </h2>
      <ul className="mt-2.5 grid grid-cols-1 gap-2 lg:grid-cols-2">
        {problems.map((a) => (
          <li key={a.id} className="flex flex-col gap-2 rounded-xl bg-card px-3 py-2 sm:flex-row sm:items-center">
            <PlatformIcon platform={a.platform} size="sm" />
            <p className="min-w-0 flex-1 text-sm text-ink-soft">
              <span className="font-semibold text-ink">{PLATFORMS[a.platform].name} :</span> {a.statusMessage ?? ACCOUNT_STATUS[a.status].hint}
            </p>
            <Button size="xs" variant="outline" asChild className="self-start sm:self-auto">
              <Link to="/social-accounts">Reconnecter</Link>
            </Button>
          </li>
        ))}
        {failedPosts.map((p) => (
          <li key={p.id} className="flex flex-col gap-2 rounded-xl bg-card px-3 py-2 sm:flex-row sm:items-center">
            <span className="inline-flex size-7 items-center justify-center rounded-full bg-danger-50 text-danger-500" aria-hidden="true">
              <XCircle className="size-4" />
            </span>
            <p className="min-w-0 flex-1 text-sm text-ink-soft">
              <span className="font-semibold text-ink">Échec de publication :</span> « {p.title ?? p.body.slice(0, 40)} »
            </p>
            <Button size="xs" variant="outline" asChild className="self-start sm:self-auto">
              <Link to={`/content?status=${p.status}`}>Voir le détail</Link>
            </Button>
          </li>
        ))}
      </ul>
    </section>
  )
}

export function ConnectedNetworks({ accounts, isLoading }: { accounts: SocialAccount[] | undefined; isLoading: boolean }) {
  const visible = accounts?.filter((a) => a.status !== "coming_soon" && a.status !== "disconnected") ?? []
  return (
    <SectionCard title="Réseaux connectés" bodyClassName="pt-2">
      {isLoading ? (
        <ListSkeleton rows={5} />
      ) : visible.length === 0 ? (
        <EmptyState icon={PlugZap} title="Aucun réseau connecté" description="Connectez un premier réseau pour commencer à publier." action={<Button asChild><Link to="/social-accounts">Connecter un réseau</Link></Button>} className="py-8" />
      ) : (
        <ul className="divide-y divide-border">
          {visible.map((a) => (
            <li key={a.id}>
              <Link to="/social-accounts" className="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-muted/60">
                <PlatformIcon platform={a.platform} size="md" />
                <span className="flex-1 truncate text-sm font-medium text-ink">{PLATFORMS[a.platform].name}</span>
                <StatusBadge meta={ACCOUNT_STATUS[a.status]} />
                <ChevronRight className="size-4 text-ink-muted" aria-hidden="true" />
              </Link>
            </li>
          ))}
        </ul>
      )}
      <Link to="/social-accounts" className="mt-3 inline-block text-sm font-semibold text-brand-600 hover:underline">
        Gérer les réseaux
      </Link>
    </SectionCard>
  )
}

export function UpcomingPosts({ posts, isLoading }: { posts: Post[] | undefined; isLoading: boolean }) {
  const now = useNow()
  const upcoming = (posts ?? [])
    .filter((p) => p.status === "scheduled" && p.scheduledAt && new Date(p.scheduledAt).getTime() > now)
    .sort((a, b) => new Date(a.scheduledAt!).getTime() - new Date(b.scheduledAt!).getTime())
    .slice(0, 5)
  return (
    <SectionCard
      title="Prochaines publications"
      action={
        <Link to="/content/calendar" className="text-sm font-medium text-ink-muted hover:text-brand-600">
          Voir tout
        </Link>
      }
      bodyClassName="pt-2"
    >
      {isLoading ? (
        <ListSkeleton rows={4} />
      ) : upcoming.length === 0 ? (
        <EmptyState icon={CalendarPlus} title="Aucune publication programmée" description="Programmez un post pour qu'il soit publié automatiquement." action={<Button asChild><Link to="/content/create">Programmer une publication</Link></Button>} className="py-8" />
      ) : (
        <ul className="space-y-1">
          {upcoming.map((p) => (
            <li key={p.id}>
              <Link to="/content/calendar" className="-mx-2 flex items-start gap-3 rounded-lg px-2 py-2.5 hover:bg-muted/60">
                <span className="flex -space-x-2">
                  {p.variants.slice(0, 2).map((v) => (
                    <PlatformIcon key={v.id} platform={v.platform} size="md" className="ring-2 ring-card" />
                  ))}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-xs text-ink-muted">{formatSlot(p.scheduledAt!)}</span>
                  <span className="block truncate text-sm font-semibold text-brand-600">{p.title ?? p.body}</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </SectionCard>
  )
}

const ACTIVITY_ICON: Record<ActivityItem["kind"], { icon: LucideIcon; tone: string }> = {
  published: { icon: Send, tone: "bg-success-50 text-success-700" },
  scheduled: { icon: CalendarPlus, tone: "bg-info-50 text-info-700" },
  failed: { icon: AlertTriangle, tone: "bg-danger-50 text-danger-700" },
  connected: { icon: PlugZap, tone: "bg-brand-50 text-brand-600" },
  media: { icon: ImagePlus, tone: "bg-warning-50 text-warning-700" },
  clip: { icon: Clapperboard, tone: "bg-violet-50 text-violet-600" },
  ai: { icon: Sparkles, tone: "bg-brand-50 text-brand-600" },
}

export function RecentActivity({ items, isLoading }: { items: ActivityItem[] | undefined; isLoading: boolean }) {
  return (
    <SectionCard title="Activité récente" bodyClassName="pt-2">
      {isLoading ? (
        <ListSkeleton rows={4} />
      ) : (
        <ol className="space-y-1">
          {items?.map((a) => {
            const { icon: Icon, tone } = ACTIVITY_ICON[a.kind]
            return (
              <li key={a.id}>
                <Link to={a.href ?? "#"} className="-mx-2 flex items-start gap-3 rounded-lg px-2 py-2.5 hover:bg-muted/60">
                  <span className={cn("inline-flex size-9 shrink-0 items-center justify-center rounded-xl", tone)} aria-hidden="true">
                    <Icon className="size-4" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex items-baseline justify-between gap-2">
                      <span className="truncate text-sm font-semibold text-ink">{a.title}</span>
                      <span className="shrink-0 text-xs text-ink-muted">{formatRelative(a.createdAt)}</span>
                    </span>
                    <span className="block truncate text-xs text-ink-muted">{a.detail}</span>
                  </span>
                </Link>
              </li>
            )
          })}
        </ol>
      )}
    </SectionCard>
  )
}

const SHORTCUTS: { to: string; label: string; description: string; icon: LucideIcon; tone: string }[] = [
  { to: "/content/create", label: "Créer une publication", description: "Écrire et publier sur vos réseaux", icon: SquarePen, tone: "bg-brand-500 text-white" },
  { to: "/media", label: "Importer un média", description: "Ajouter photos et vidéos", icon: ImagePlus, tone: "bg-warning-50 text-warning-700" },
  { to: "/video-studio", label: "Découper une vidéo", description: "Créer des clips courts", icon: Clapperboard, tone: "bg-violet-50 text-violet-600" },
  { to: "/ai", label: "Demander à l'IA", description: "Idées, légendes, hashtags", icon: Sparkles, tone: "bg-success-50 text-success-700" },
]

export function QuickActions() {
  return (
    <SectionCard title="Que voulez-vous faire ?" bodyClassName="pt-2">
      <ul className="grid gap-2">
        {SHORTCUTS.map(({ to, label, description, icon: Icon, tone }) => (
          <li key={to}>
            <Link to={to} className="-mx-2 flex items-center gap-3 rounded-xl px-2 py-2.5 hover:bg-muted/60">
              <span className={cn("inline-flex size-10 shrink-0 items-center justify-center rounded-xl", tone)} aria-hidden="true">
                <Icon className="size-[18px]" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm font-semibold text-ink">{label}</span>
                <span className="block text-xs text-ink-muted">{description}</span>
              </span>
              <ChevronRight className="size-4 text-ink-muted" aria-hidden="true" />
            </Link>
          </li>
        ))}
      </ul>
    </SectionCard>
  )
}
