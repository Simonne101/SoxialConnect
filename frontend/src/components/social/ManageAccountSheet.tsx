import { CheckCircle2, CircleDashed, RefreshCw, Unplug, XCircle } from "lucide-react"
import { useState } from "react"
import { toast } from "sonner"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { ConfirmDialog } from "@/components/shared/ConfirmDialog"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { useDisconnectAccount, useSyncAccount } from "@/hooks/queries/social"
import { formatInt, formatRelative } from "@/lib/format"
import { CAPABILITY_LABELS, CAPABILITY_STATUS_LABELS, PLATFORMS } from "@/lib/platforms"
import { ACCOUNT_STATUS } from "@/lib/status"
import type { Capability, CapabilityStatus, SocialAccount } from "@/types"

const CAP_TONE: Record<CapabilityStatus, "success" | "neutral" | "warning" | "info"> = {
  supported: "success",
  unsupported: "neutral",
  requires_review: "warning",
  to_verify: "info",
}

export function ManageAccountSheet({
  account,
  onOpenChange,
  onReconnect,
}: {
  account: SocialAccount | null
  onOpenChange: (o: boolean) => void
  onReconnect: (a: SocialAccount) => void
}) {
  const sync = useSyncAccount()
  const disconnect = useDisconnectAccount()
  const [confirm, setConfirm] = useState(false)
  const cfg = account ? PLATFORMS[account.platform] : null

  return (
    <>
      <Sheet open={Boolean(account)} onOpenChange={onOpenChange}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          {account && cfg && (
            <>
              <SheetHeader className="border-b border-border pb-4">
                <div className="flex items-center gap-3">
                  <PlatformIcon platform={account.platform} size="lg" />
                  <div>
                    <SheetTitle className="text-lg">{cfg.name}</SheetTitle>
                    <SheetDescription>{account.displayName ?? "Compte non connecté"}</SheetDescription>
                  </div>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <StatusBadge meta={ACCOUNT_STATUS[account.status]} />
                  {account.followers !== null && <span className="text-xs text-ink-muted">{formatInt(account.followers)} abonnés</span>}
                </div>
                {account.statusMessage && <p className="mt-2 rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-700">{account.statusMessage}</p>}
              </SheetHeader>

              <div className="space-y-6 px-4 pb-6">
                <section>
                  <h3 className="text-sm font-semibold">Comptes gérés</h3>
                  <ul className="mt-2 space-y-2">
                    {account.entities.map((e) => (
                      <li key={e.id} className="flex items-center justify-between rounded-xl border border-border px-3 py-2.5 text-sm">
                        <span className="font-medium text-ink">{e.name}</span>
                        <span className="text-xs text-ink-muted">{e.kind}</span>
                      </li>
                    ))}
                  </ul>
                  <p className="mt-2 text-xs text-ink-muted">
                    Dernière synchronisation : {account.lastSyncedAt ? formatRelative(account.lastSyncedAt) : "jamais"}
                  </p>
                </section>

                <section>
                  <h3 className="text-sm font-semibold">Permissions</h3>
                  <ul className="mt-2 space-y-1.5">
                    {account.permissions.map((p) => (
                      <li key={p.scope} className="flex items-center gap-2.5 text-sm">
                        {p.status === "granted" ? (
                          <CheckCircle2 className="size-4 shrink-0 text-success-500" aria-hidden="true" />
                        ) : p.status === "declined" ? (
                          <XCircle className="size-4 shrink-0 text-danger-500" aria-hidden="true" />
                        ) : (
                          <CircleDashed className="size-4 shrink-0 text-ink-muted" aria-hidden="true" />
                        )}
                        <span className="flex-1 text-ink-soft">{p.label}</span>
                        <span className="sr-only">{p.status === "granted" ? "accordée" : p.status === "declined" ? "refusée" : "manquante"}</span>
                        {p.status === "declined" && <Badge variant="danger">Refusée</Badge>}
                      </li>
                    ))}
                  </ul>
                </section>

                <section>
                  <h3 className="text-sm font-semibold">Ce que {cfg.name} permet</h3>
                  <p className="mt-1 text-xs text-ink-muted">Selon l'API officielle — à confirmer lors de l'intégration.</p>
                  <ul className="mt-2 grid grid-cols-1 gap-1.5">
                    {(Object.keys(CAPABILITY_LABELS) as Capability[]).map((c) => (
                      <li key={c} className="flex items-center justify-between gap-2 text-sm">
                        <span className="text-ink-soft">{CAPABILITY_LABELS[c]}</span>
                        <Badge variant={CAP_TONE[cfg.capabilities[c]]}>{CAPABILITY_STATUS_LABELS[cfg.capabilities[c]]}</Badge>
                      </li>
                    ))}
                  </ul>
                </section>

                <div className="flex flex-col gap-2 border-t border-border pt-5">
                  {account.status === "connected" ? (
                    <Button
                      variant="outline"
                      disabled={sync.isPending}
                      onClick={() => sync.mutate(account.id, { onSuccess: () => toast.success(`${cfg.name} synchronisé.`) })}
                    >
                      <RefreshCw className={sync.isPending ? "animate-spin" : ""} /> {sync.isPending ? "Synchronisation…" : "Synchroniser maintenant"}
                    </Button>
                  ) : (
                    <Button onClick={() => onReconnect(account)}>
                      <RefreshCw /> Reconnecter {cfg.name}
                    </Button>
                  )}
                  <Button variant="destructive-soft" onClick={() => setConfirm(true)}>
                    <Unplug /> Déconnecter
                  </Button>
                </div>
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
      <ConfirmDialog
        open={confirm}
        onOpenChange={setConfirm}
        title={`Déconnecter ${cfg?.name ?? ""} ?`}
        description="SocialPulse ne pourra plus publier sur ce compte. Les publications programmées vers ce réseau échoueront. Vos publications déjà en ligne restent en place."
        confirmLabel="Déconnecter"
        onConfirm={() => {
          if (!account) return
          disconnect.mutate(account.id, {
            onSuccess: () => {
              toast.success(`${cfg?.name} a été déconnecté.`)
              onOpenChange(false)
            },
          })
        }}
      />
    </>
  )
}
