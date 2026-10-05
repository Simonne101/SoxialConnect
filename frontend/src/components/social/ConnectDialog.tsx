import { CheckCircle2, ExternalLink, KeyRound, LoaderCircle, ShieldCheck } from "lucide-react"
import { useState } from "react"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { useConnectAccount, useStartOAuth } from "@/hooks/queries/social"
import { FeatureUnavailableError } from "@/lib/features"
import { apiErrorMessage } from "@/lib/http"
import { supportsOAuth, supportsTelegramLink } from "@/services/socialAccounts"
import { TelegramConnectDialog } from "./TelegramConnectDialog"
import { PLATFORMS } from "@/lib/platforms"
import type { SocialAccount } from "@/types"

/**
 * Connexion d'un réseau (docs/security.md §6).
 * - Réseaux livrés (YouTube, backend branché) : redirection vers la page de consentement
 *   officielle (state + PKCE), callback Laravel, tokens chiffrés côté serveur. Le retour
 *   arrive sur /social-accounts?oauth=… (message affiché par la page).
 * - Démonstration (sans backend) : connexion simulée. Autres réseaux : refus explicite.
 * Aucun token ne transite par le navigateur.
 */
export function ConnectDialog({ account, onOpenChange }: { account: SocialAccount | null; onOpenChange: (o: boolean) => void }) {
  const demoConnect = useConnectAccount()
  const oauth = useStartOAuth()
  const real = account ? supportsOAuth(account.platform) : false
  const connect = real ? oauth : demoConnect
  const [done, setDone] = useState<SocialAccount | null>(null)
  const cfg = account ? PLATFORMS[account.platform] : null
  const reconnect = account && account.status !== "disconnected"

  const close = (o: boolean) => {
    if (!o) {
      setDone(null)
      demoConnect.reset()
      oauth.reset()
    }
    onOpenChange(o)
  }

  // Telegram (backend branché) : liaison par le bot SoxialConnect, sans OAuth.
  if (account && supportsTelegramLink(account.platform)) return <TelegramConnectDialog account={account} onOpenChange={onOpenChange} />

  return (
    <Dialog open={Boolean(account)} onOpenChange={close}>
      <DialogContent className="sm:max-w-[480px]">
        {account && cfg && (
          <>
            <DialogHeader className="items-center text-center sm:text-center">
              <PlatformIcon platform={account.platform} size="xl" />
              <DialogTitle className="mt-2 text-xl">
                {done ? `${cfg.name} est connecté` : `${reconnect ? "Reconnecter" : "Connecter"} ${cfg.name}`}
              </DialogTitle>
              <DialogDescription>
                {done
                  ? "Vous pouvez maintenant publier sur ce compte depuis SoxialConnect."
                  : connect.isPending
                    ? `Autorisation en cours sur ${cfg.name}…`
                    : `Vous allez être redirigé vers ${cfg.name} pour autoriser SoxialConnect.`}
              </DialogDescription>
            </DialogHeader>

            {connect.isPending && (
              <div className="flex flex-col items-center gap-3 py-6" role="status">
                <LoaderCircle className="size-8 animate-spin text-brand-500" aria-hidden="true" />
                <p className="text-sm text-ink-muted">{real ? `Redirection vers ${cfg.name}…` : "Simulation de la page d'autorisation officielle…"}</p>
              </div>
            )}

            {!connect.isPending && !done && (
              <div className="space-y-4">
                <div className="rounded-xl bg-page p-4">
                  <p className="text-sm font-semibold text-ink">Ce qu'il vous faut</p>
                  <p className="mt-1 text-sm text-ink-soft">{cfg.accountRequirement}</p>
                </div>
                <div>
                  <p className="flex items-center gap-2 text-sm font-semibold text-ink">
                    <KeyRound className="size-4 text-brand-500" aria-hidden="true" /> Autorisations demandées
                  </p>
                  <ul className="mt-2 space-y-1.5">
                    {cfg.permissions.map((p) => (
                      <li key={p.scope} className="flex items-center gap-2 text-sm text-ink-soft">
                        <CheckCircle2 className="size-4 text-success-500" aria-hidden="true" /> {p.label}
                      </li>
                    ))}
                  </ul>
                </div>
                <p className="flex items-start gap-2 rounded-xl bg-success-50 p-3 text-xs text-success-700">
                  <ShieldCheck className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                  Connexion officielle : votre mot de passe {cfg.name} n'est jamais communiqué à SoxialConnect. Vous pouvez retirer l'accès à tout moment.
                </p>
                {connect.isError && (
                  <p role="alert" className="text-sm font-medium text-danger-700">
                    {connect.error instanceof FeatureUnavailableError ? connect.error.message : apiErrorMessage(connect.error, "La connexion a échoué. Réessayez dans un instant.")}
                  </p>
                )}
              </div>
            )}

            {done && (
              <ul className="space-y-2">
                {done.entities.map((e) => (
                  <li key={e.id} className="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5">
                    <CheckCircle2 className="size-5 text-success-500" aria-hidden="true" />
                    <span className="text-sm font-medium text-ink">{e.name}</span>
                    <span className="ml-auto text-xs text-ink-muted">{e.kind}</span>
                  </li>
                ))}
              </ul>
            )}

            <DialogFooter>
              {done ? (
                <Button className="w-full sm:w-auto" onClick={() => close(false)}>Terminer</Button>
              ) : (
                <>
                  <Button variant="outline" onClick={() => close(false)} disabled={connect.isPending}>Annuler</Button>
                  <Button
                    disabled={connect.isPending}
                    onClick={() => (real ? oauth.mutate(account.platform) : demoConnect.mutate(account.id, { onSuccess: (a) => setDone(a as SocialAccount) }))}
                  >
                    Continuer vers {cfg.name} <ExternalLink />
                  </Button>
                </>
              )}
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
