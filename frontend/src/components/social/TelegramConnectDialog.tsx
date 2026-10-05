import { useQuery, useQueryClient } from "@tanstack/react-query"
import { CheckCircle2, Copy, ExternalLink, LoaderCircle, ShieldCheck } from "lucide-react"
import { useEffect, useState } from "react"
import { toast } from "sonner"
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
import { qk } from "@/hooks/queries/keys"
import { useTelegramLink } from "@/hooks/queries/social"
import { apiErrorMessage } from "@/lib/http"
import { PLATFORMS } from "@/lib/platforms"
import { listSocialAccounts, type TelegramLink } from "@/services/socialAccounts"
import type { SocialAccount } from "@/types"

/** Liaisons Telegram connues au moment où le lien est créé : identifiant → date de connexion. */
type Snapshot = Record<string, string | null>

const telegramSnapshot = (accounts: SocialAccount[] | undefined): Snapshot =>
  Object.fromEntries((accounts ?? []).filter((a) => a.platform === "telegram").map((a) => [a.id, a.connectedAt]))

/**
 * Connexion d'une chaîne ou d'un groupe Telegram (bot partagé SoxialConnect, sans OAuth) :
 * 1. le serveur crée un lien t.me/<bot>?start=<code> (usage unique, 10 min) ;
 * 2. dans Telegram : « Démarrer », puis choix de la chaîne ou du groupe (bouton officiel), le bot
 *    y est ajouté comme administrateur ;
 * 3. le webhook enregistre la destination ; cette fenêtre la détecte en relisant la liste des comptes.
 * Aucun token ne transite par le navigateur.
 */
export function TelegramConnectDialog({ account, onOpenChange }: { account: SocialAccount | null; onOpenChange: (o: boolean) => void }) {
  const qc = useQueryClient()
  const link = useTelegramLink()
  const [pending, setPending] = useState<{ link: TelegramLink; before: Snapshot } | null>(null)
  const [now, setNow] = useState(() => Date.now())
  const expired = pending ? new Date(pending.link.expiresAt).getTime() <= now : false
  const waiting = Boolean(pending) && !expired

  // Attente de la liaison : liste des comptes relue toutes les 3 s (aucune donnée sensible).
  const accounts = useQuery({ queryKey: qk.socialAccounts, queryFn: listSocialAccounts, refetchInterval: waiting ? 3000 : false })
  const linked = pending
    ? (accounts.data ?? []).filter((a) => a.platform === "telegram" && a.status === "connected" && pending.before[a.id] !== a.connectedAt)
    : []
  const done = linked.length > 0

  useEffect(() => {
    if (!waiting) return
    const timer = window.setInterval(() => setNow(Date.now()), 5000)
    return () => window.clearInterval(timer)
  }, [waiting])

  const start = () =>
    link.mutate(undefined, {
      onSuccess: (data) => {
        setNow(Date.now())
        setPending({ link: data, before: telegramSnapshot(qc.getQueryData<SocialAccount[]>(qk.socialAccounts)) })
      },
    })

  const close = (o: boolean) => {
    if (!o) {
      setPending(null)
      link.reset()
      void qc.invalidateQueries({ queryKey: qk.socialAccounts })
    }
    onOpenChange(o)
  }

  const copy = async () => {
    if (!pending) return
    try {
      await navigator.clipboard.writeText(pending.link.linkUrl)
      toast.success("Lien copié. Ouvrez-le sur l'appareil où Telegram est installé.")
    } catch {
      toast.error("La copie a échoué. Sélectionnez le lien manuellement.")
    }
  }

  const reconnect = account && account.status !== "disconnected"
  const name = PLATFORMS.telegram.name

  return (
    <Dialog open={Boolean(account)} onOpenChange={close}>
      <DialogContent className="sm:max-w-[500px]">
        <DialogHeader className="items-center text-center sm:text-center">
          <PlatformIcon platform="telegram" size="xl" />
          <DialogTitle className="mt-2 text-xl">
            {done ? "Destination Telegram connectée" : reconnect ? `Reconnecter ${account?.displayName ?? name}` : `Connecter ${name}`}
          </DialogTitle>
          <DialogDescription>
            {done
              ? "Vous pouvez maintenant publier sur cette destination depuis SoxialConnect."
              : "Reliez une chaîne ou un groupe dont vous êtes administrateur. Le bot SoxialConnect y publiera vos messages."}
          </DialogDescription>
        </DialogHeader>

        {done ? (
          <ul className="space-y-2">
            {linked.map((a) => (
              <li key={a.id} className="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5">
                <CheckCircle2 className="size-5 text-success-500" aria-hidden="true" />
                <span className="min-w-0 flex-1 truncate text-sm font-medium text-ink">{a.displayName}</span>
                <span className="text-xs text-ink-muted">{a.accountType === "group" ? "Groupe" : "Chaîne"}</span>
              </li>
            ))}
          </ul>
        ) : pending && !expired ? (
          <div className="space-y-4">
            <ol className="space-y-2 rounded-xl bg-page p-4 text-sm text-ink-soft">
              <li><span className="font-semibold text-ink">1.</span> Ouvrez Telegram avec le bouton ci-dessous, puis appuyez sur « Démarrer ».</li>
              <li><span className="font-semibold text-ink">2.</span> Choisissez « Choisir une chaîne » ou « Choisir un groupe », puis la destination.</li>
              <li><span className="font-semibold text-ink">3.</span> Acceptez d'ajouter @{pending.link.botUsername} comme administrateur (droit de publier pour une chaîne).</li>
            </ol>
            <Button asChild className="w-full">
              <a href={pending.link.linkUrl} target="_blank" rel="noopener noreferrer">
                Ouvrir Telegram <ExternalLink />
              </a>
            </Button>
            <div className="flex items-center gap-2 rounded-lg border border-border px-3 py-2">
              <span className="min-w-0 flex-1 truncate text-xs text-ink-muted">{pending.link.linkUrl}</span>
              <Button type="button" variant="ghost" size="sm" onClick={copy} aria-label="Copier le lien de connexion Telegram">
                <Copy /> Copier
              </Button>
            </div>
            <p className="flex items-center justify-center gap-2 text-sm text-ink-muted" role="status">
              <LoaderCircle className="size-4 animate-spin text-brand-500" aria-hidden="true" /> En attente de la liaison dans Telegram…
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            <div className="rounded-xl bg-page p-4">
              <p className="text-sm font-semibold text-ink">Ce qu'il vous faut</p>
              <p className="mt-1 text-sm text-ink-soft">{PLATFORMS.telegram.accountRequirement}</p>
            </div>
            {expired && <p role="alert" className="text-sm font-medium text-warning-700">Le lien a expiré (10 minutes). Générez-en un nouveau.</p>}
            <p className="flex items-start gap-2 rounded-xl bg-success-50 p-3 text-xs text-success-700">
              <ShieldCheck className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
              Mécanisme officiel de Telegram : vous choisissez la destination dans l'application, aucun mot de passe ni code n'est saisi dans SoxialConnect. Vous pouvez retirer le bot à tout moment.
            </p>
            {link.isError && (
              <p role="alert" className="text-sm font-medium text-danger-700">
                {apiErrorMessage(link.error, "La création du lien a échoué. Réessayez dans un instant.")}
              </p>
            )}
          </div>
        )}

        <DialogFooter>
          {done ? (
            <>
              <Button variant="outline" onClick={() => { setPending(null); link.reset() }}>Relier une autre destination</Button>
              <Button onClick={() => close(false)}>Terminer</Button>
            </>
          ) : pending && !expired ? (
            <Button variant="outline" onClick={() => close(false)}>Fermer</Button>
          ) : (
            <>
              <Button variant="outline" onClick={() => close(false)} disabled={link.isPending}>Annuler</Button>
              <Button onClick={start} disabled={link.isPending}>
                {link.isPending && <LoaderCircle className="animate-spin" />}
                {expired ? "Générer un nouveau lien" : "Générer le lien de connexion"}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
