import { PlugZap, RefreshCw, ShieldCheck } from "lucide-react"
import { useState } from "react"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { PageHeader } from "@/components/shared/PageHeader"
import { ErrorState, ListSkeleton } from "@/components/shared/States"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { ConnectDialog } from "@/components/social/ConnectDialog"
import { ManageAccountSheet } from "@/components/social/ManageAccountSheet"
import { Button } from "@/components/ui/button"
import { useSocialAccounts } from "@/hooks/queries/social"
import { formatInt } from "@/lib/format"
import { PLATFORM_ORDER, PLATFORMS } from "@/lib/platforms"
import { ACCOUNT_STATUS } from "@/lib/status"
import type { SocialAccount } from "@/types"

function entitiesLabel(a: SocialAccount) {
  if (!a.entities.length) return "—"
  const kind = a.entities[0].kind
  return `${kind}${a.entities.length > 1 ? "s" : ""} (${a.entities.length})`
}

export default function SocialAccountsPage() {
  const { data, isLoading, isError, refetch } = useSocialAccounts()
  const [connectFor, setConnectFor] = useState<SocialAccount | null>(null)
  const [manageId, setManageId] = useState<string | null>(null)
  const accounts = [...(data ?? [])].sort((a, b) => PLATFORM_ORDER.indexOf(a.platform) - PLATFORM_ORDER.indexOf(b.platform))
  const manage = accounts.find((a) => a.id === manageId) ?? null
  const connected = accounts.filter((a) => a.status === "connected").length
  const attention = accounts.filter((a) => ["expired", "permission_required", "error"].includes(a.status)).length

  return (
    <>
      <PageHeader title="Réseaux sociaux" description="Gérez vos comptes et leurs permissions." />

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div className="surface-card flex items-center gap-4 p-5">
          <span className="inline-flex size-11 items-center justify-center rounded-xl bg-success-50 text-success-700" aria-hidden="true"><PlugZap className="size-5" /></span>
          <div>
            <p className="text-2xl font-bold text-ink">{connected} / {PLATFORM_ORDER.length}</p>
            <p className="text-xs text-ink-muted">réseaux connectés</p>
          </div>
        </div>
        <div className="surface-card flex items-center gap-4 p-5">
          <span className="inline-flex size-11 items-center justify-center rounded-xl bg-warning-50 text-warning-700" aria-hidden="true"><RefreshCw className="size-5" /></span>
          <div>
            <p className="text-2xl font-bold text-ink">{attention}</p>
            <p className="text-xs text-ink-muted">à reconnecter</p>
          </div>
        </div>
        <div className="surface-card flex items-center gap-4 p-5">
          <span className="inline-flex size-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600" aria-hidden="true"><ShieldCheck className="size-5" /></span>
          <p className="text-xs leading-relaxed text-ink-muted">Connexions officielles uniquement. Votre mot de passe n'est jamais partagé avec SocialPulse.</p>
        </div>
      </div>

      <section className="surface-card p-2 sm:p-3" aria-label="Liste des réseaux">
        {isLoading ? (
          <div className="p-4"><ListSkeleton rows={8} /></div>
        ) : isError ? (
          <ErrorState onRetry={() => refetch()} />
        ) : (
          <ul className="divide-y divide-border">
            {accounts.map((a) => {
              const cfg = PLATFORMS[a.platform]
              const needsReconnect = ["expired", "permission_required", "error"].includes(a.status)
              return (
                <li key={a.id} className="flex flex-wrap items-center gap-x-4 gap-y-3 px-3 py-4 xl:flex-nowrap">
                  <PlatformIcon platform={a.platform} size="lg" />
                  <div className="min-w-0 flex-1 xl:w-48 xl:flex-none">
                    <p className="font-semibold text-ink">{cfg.name}</p>
                    <p className="truncate text-xs text-ink-muted">
                      {a.username ?? a.displayName ?? cfg.accountRequirement}
                      {a.entities.length > 0 && <span className="xl:hidden"> · {entitiesLabel(a)}</span>}
                    </p>
                  </div>
                  <div className="order-last w-full xl:order-none xl:w-44">
                    <StatusBadge meta={ACCOUNT_STATUS[a.status]} />
                    {needsReconnect && <p className="mt-1 text-xs text-warning-700">{ACCOUNT_STATUS[a.status].hint}</p>}
                  </div>
                  <p className="hidden flex-1 text-sm text-ink-muted xl:block">{entitiesLabel(a)}</p>
                  <p className="hidden w-28 text-sm text-ink-muted 2xl:block">{a.followers !== null ? `${formatInt(a.followers)} abonnés` : ""}</p>
                  <div className="flex shrink-0 flex-wrap justify-end gap-2">
                    {a.status === "coming_soon" ? (
                      <Button variant="outline" disabled>Bientôt</Button>
                    ) : a.status === "disconnected" ? (
                      <Button variant="secondary" onClick={() => setConnectFor(a)} aria-label={`Connecter ${cfg.name}`}>Connecter</Button>
                    ) : (
                      <>
                        {needsReconnect && (
                          <Button onClick={() => setConnectFor(a)} aria-label={`Reconnecter ${cfg.name}`}>Reconnecter</Button>
                        )}
                        <Button variant="outline" onClick={() => setManageId(a.id)} aria-label={`Gérer ${cfg.name}`}>Gérer</Button>
                      </>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </section>

      <ConnectDialog account={connectFor} onOpenChange={(o) => !o && setConnectFor(null)} />
      <ManageAccountSheet account={manage} onOpenChange={(o) => !o && setManageId(null)} onReconnect={(a) => { setManageId(null); setConnectFor(a) }} />
    </>
  )
}
