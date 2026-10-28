import { useQueryClient } from "@tanstack/react-query"
import { MailCheck, PlugZap, RefreshCw, ShieldCheck } from "lucide-react"
import { useEffect, useState } from "react"
import { useSearchParams } from "react-router"
import { toast } from "sonner"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { PageHeader } from "@/components/shared/PageHeader"
import { ErrorState, ListSkeleton } from "@/components/shared/States"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { ConnectDialog } from "@/components/social/ConnectDialog"
import { ManageAccountSheet } from "@/components/social/ManageAccountSheet"
import { Button } from "@/components/ui/button"
import { useResendVerification } from "@/hooks/queries/auth"
import { qk } from "@/hooks/queries/keys"
import { useMe } from "@/hooks/queries/misc"
import { useSocialAccounts } from "@/hooks/queries/social"
import { apiErrorMessage, isApiEnabled } from "@/lib/http"
import { formatInt } from "@/lib/format"
import { PLATFORM_ORDER, PLATFORMS } from "@/lib/platforms"
import { ACCOUNT_STATUS } from "@/lib/status"
import { placeholderAccount, supportsOAuth, supportsTelegramLink } from "@/services/socialAccounts"
import type { SocialAccount } from "@/types"

/** Codes renvoyés par les callbacks OAuth (backend) → message lisible. Jamais de détail technique. */
const OAUTH_ERRORS: Record<string, (network: string) => string> = {
  ACCESS_DENIED: (n) => `Autorisation annulée sur ${n} : aucun compte n'a été connecté.`,
  SCOPE_DENIED: (n) => `La publication de vidéos n'a pas été autorisée sur ${n}. Reconnectez-vous en acceptant toutes les autorisations.`,
  PUBLISH_SCOPE_DENIED: (n) => `La permission de publier n'a pas été accordée sur ${n}. Reconnectez-vous en acceptant toutes les autorisations.`,
  INVALID_STATE: () => "La demande de connexion a expiré ou n'est plus valide. Relancez la connexion.",
  NO_CHANNEL: () => "Ce compte Google n'a pas de chaîne YouTube. Créez une chaîne puis reconnectez-vous.",
  NOT_CONFIGURED: (n) => `La connexion ${n} n'est pas encore configurée sur le serveur.`,
  OAUTH_CLIENT_INVALID: (n) => `La configuration OAuth ${n} du serveur est invalide.`,
  NETWORK_ERROR: (n) => `${n} est momentanément injoignable. Réessayez dans un instant.`,
  TOKEN_REVOKED: (n) => `${n} a refusé l'autorisation (lien expiré). Relancez la connexion.`,
  API_ACCESS_DENIED: (n) => `${n} refuse l'accès à son API pour SoxialConnect (offre ou crédits API). Contactez l'administrateur.`,
  RATE_LIMITED: (n) => `${n} limite temporairement les requêtes. Réessayez dans quelques minutes.`,
  NO_PAGES: () => "Aucune Page Facebook n'a été partagée avec SoxialConnect. Reconnectez-vous et sélectionnez au moins une Page (les profils personnels ne sont pas pris en charge).",
  NO_PUBLISHABLE_PAGE: () => "Votre rôle sur les Pages sélectionnées ne permet pas d'y publier : il faut être administrateur, éditeur ou créateur de contenu de la Page.",
  NOT_PROFESSIONAL_ACCOUNT: () => "Ce compte Instagram est un compte personnel. Passez-le en compte professionnel (Business ou Créateur) dans les paramètres d'Instagram, puis reconnectez-le.",
  PERMISSION_DENIED: (n) => `${n} refuse l'accès demandé. Reconnectez-vous en acceptant toutes les autorisations.`,
}

function entitiesLabel(a: SocialAccount) {
  if (!a.entities.length) return "—"
  const kind = a.entities[0].kind
  return `${kind}${a.entities.length > 1 ? "s" : ""} (${a.entities.length})`
}

export default function SocialAccountsPage() {
  const { data, isLoading, isError, error, refetch } = useSocialAccounts()
  const me = useMe()
  const resend = useResendVerification()
  // Adresse non vérifiée : la page reste utilisable, mais connecter un réseau exige la vérification.
  const unverified = isApiEnabled && me.data?.emailVerified === false
  const [connectFor, setConnectFor] = useState<SocialAccount | null>(null)
  // Ajout d'un compte supplémentaire (réseau déjà connecté) : consignes de choix du compte affichées.
  const [addingAnother, setAddingAnother] = useState(false)
  const [manageId, setManageId] = useState<string | null>(null)
  const [params, setParams] = useSearchParams()
  const qc = useQueryClient()

  // Retour du consentement OAuth (callback Laravel) : message, rafraîchissement, URL nettoyée.
  useEffect(() => {
    const provider = params.get("oauth")
    if (!provider) return
    const name = provider in PLATFORMS ? PLATFORMS[provider as keyof typeof PLATFORMS].name : "Le réseau"
    // Facebook : plusieurs Pages peuvent être connectées en une seule autorisation.
    const count = Number(params.get("count") ?? 0)
    if (params.get("status") === "connected") toast.success(count > 1 ? `${count} Pages ${name} sont connectées.` : `${name} est connecté.`, { id: "oauth-result" })
    else toast.error(OAUTH_ERRORS[params.get("code") ?? ""]?.(name) ?? `La connexion à ${name} a échoué. Réessayez.`, { id: "oauth-result" })
    void qc.invalidateQueries({ queryKey: qk.socialAccounts })
    setParams({}, { replace: true })
  }, [params, setParams, qc])

  // Backend branché : un emplacement « à connecter » pour chaque réseau sans compte. La liste des
  // réseaux vient de la configuration (PLATFORM_ORDER), jamais de la présence d'un compte connecté.
  const listed = [...(data ?? [])]
  if (isApiEnabled && data) {
    for (const platform of PLATFORM_ORDER) if (!listed.some((a) => a.platform === platform)) listed.push(placeholderAccount(platform))
  }
  const accounts = listed.sort((a, b) => PLATFORM_ORDER.indexOf(a.platform) - PLATFORM_ORDER.indexOf(b.platform))
  const manage = accounts.find((a) => a.id === manageId) ?? null
  // Réseaux connectés (Telegram peut compter plusieurs chaînes ou groupes : un seul réseau).
  const connected = new Set(accounts.filter((a) => a.status === "connected").map((a) => a.platform)).size
  // « Ajouter » : sur la première ligne de chaque réseau connectable qui a déjà un compte
  // (plusieurs chaînes YouTube, plusieurs destinations Telegram…).
  const canAdd = (a: SocialAccount) => supportsOAuth(a.platform) || supportsTelegramLink(a.platform)
  const firstRowIds = new Set(PLATFORM_ORDER.map((p) => accounts.find((a) => a.platform === p && !a.id.startsWith("new:") && canAdd(a))?.id).filter(Boolean))
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
          <p className="text-xs leading-relaxed text-ink-muted">Connexions officielles uniquement. Votre mot de passe n'est jamais partagé avec SoxialConnect.</p>
        </div>
      </div>

      {unverified && (
        <div role="status" className="mb-6 flex flex-col gap-3 rounded-2xl border border-warning-100 bg-warning-50 p-4 sm:flex-row sm:items-center">
          <MailCheck className="size-5 shrink-0 text-warning-700" aria-hidden="true" />
          <p className="flex-1 text-sm text-warning-700">
            Vérifiez votre adresse e-mail ({me.data?.email}) pour connecter un réseau social : cliquez sur le lien reçu par e-mail.
          </p>
          <Button
            variant="outline"
            size="sm"
            disabled={resend.isPending || resend.isSuccess}
            onClick={() => resend.mutate(undefined, {
              onSuccess: () => toast.success("Un nouveau lien de vérification vient d'être envoyé."),
              onError: (e) => toast.error(apiErrorMessage(e, "L'envoi a échoué. Réessayez dans un instant.")),
            })}
          >
            {resend.isSuccess ? "Lien envoyé" : "Renvoyer l'e-mail"}
          </Button>
        </div>
      )}

      {!isLoading && !isError && connected === 0 && (
        <p className="mb-3 text-sm text-ink-muted">Aucun compte connecté pour le moment : choisissez un réseau ci-dessous puis cliquez sur « Connecter ».</p>
      )}

      <section className="surface-card p-2 sm:p-3" aria-label="Liste des réseaux">
        {isLoading ? (
          <div className="p-4"><ListSkeleton rows={8} /></div>
        ) : isError ? (
          <ErrorState description={apiErrorMessage(error, "Vérifiez votre connexion puis réessayez.")} onRetry={() => refetch()} />
        ) : (
          <ul className="divide-y divide-border">
            {accounts.map((a) => {
              const cfg = PLATFORMS[a.platform]
              const needsReconnect = ["expired", "permission_required", "error"].includes(a.status)
              return (
                <li key={a.id} className="flex flex-wrap items-center gap-x-4 gap-y-3 px-3 py-4 xl:flex-nowrap">
                  <PlatformIcon platform={a.platform} size="lg" />
                  <div className="min-w-0 flex-1 basis-32 xl:w-48 xl:flex-none">
                    <p className="font-semibold text-ink">{cfg.name}</p>
                    <p className="truncate text-xs text-ink-muted">
                      {a.platform === "telegram" && a.displayName
                        ? `${a.displayName}${a.username ? ` · ${a.username}` : ""} · ${a.accountType === "group" ? "Groupe" : "Chaîne"}`
                        : a.username ?? a.displayName ?? cfg.accountRequirement}
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
                        {isApiEnabled && firstRowIds.has(a.id) && (
                          <Button
                            variant="secondary"
                            onClick={() => { setAddingAnother(true); setConnectFor(placeholderAccount(a.platform)) }}
                            aria-label={a.platform === "telegram" ? "Ajouter une autre chaîne ou un autre groupe Telegram" : `Ajouter un autre compte ${cfg.name}`}
                          >
                            Ajouter
                          </Button>
                        )}
                      </>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </section>

      <ConnectDialog account={connectFor} addingAnother={addingAnother} onOpenChange={(o) => { if (!o) { setConnectFor(null); setAddingAnother(false) } }} />
      <ManageAccountSheet account={manage} onOpenChange={(o) => !o && setManageId(null)} onReconnect={(a) => { setManageId(null); setConnectFor(a) }} />
    </>
  )
}
