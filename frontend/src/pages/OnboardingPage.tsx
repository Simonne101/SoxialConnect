import { ArrowLeft, ArrowRight, Check, LoaderCircle, PartyPopper, SquarePen } from "lucide-react"
import { useState } from "react"
import { Link, useNavigate } from "react-router"
import { toast } from "sonner"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useConnectAccount, useSocialAccounts } from "@/hooks/queries/social"
import { FeatureUnavailableError } from "@/lib/features"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"
import { PLATFORM_ORDER, PLATFORMS } from "@/lib/platforms"
import { TIMEZONES } from "@/lib/time"
import { cn } from "@/lib/utils"

const STEPS = ["Connecter", "Personnaliser", "Bienvenue"] as const

const GOALS = ["Gagner du temps", "Publier plus régulièrement", "Développer mon audience", "Mieux comprendre mes statistiques", "Réutiliser mes vidéos"]

function Stepper({ current }: { current: number }) {
  return (
    <ol className="flex items-center gap-2 sm:gap-3" aria-label="Étapes de configuration">
      {STEPS.map((label, i) => {
        const done = i < current
        const active = i === current
        return (
          <li key={label} className="flex flex-1 items-center gap-2 last:flex-none sm:gap-3" aria-current={active ? "step" : undefined}>
            <span
              className={cn(
                "inline-flex items-center gap-2 rounded-full py-1 pr-3 pl-1 text-sm font-semibold",
                active ? "bg-brand-50 text-brand-600" : done ? "text-success-700" : "text-ink-muted",
              )}
            >
              <span
                className={cn(
                  "inline-flex size-7 items-center justify-center rounded-full text-xs",
                  active ? "bg-brand-500 text-white" : done ? "bg-success-500 text-white" : "border border-border bg-card",
                )}
              >
                {done ? <Check className="size-4" aria-hidden="true" /> : i + 1}
              </span>
              <span className={cn(!active && "hidden sm:inline")}>{label}</span>
              {done && <span className="sr-only">(terminé)</span>}
            </span>
            {i < STEPS.length - 1 && <span aria-hidden="true" className={cn("h-px flex-1", done ? "bg-success-500" : "bg-border")} />}
          </li>
        )
      })}
    </ol>
  )
}

export default function OnboardingPage() {
  useDocumentTitle("Bienvenue")
  const navigate = useNavigate()
  const [step, setStep] = useState(0)
  const [timezone, setTimezone] = useState("Europe/Paris")
  const [language, setLanguage] = useState("fr")
  const [goals, setGoals] = useState<string[]>(["Gagner du temps"])
  const { data: accounts } = useSocialAccounts()
  const connect = useConnectAccount()
  const connectedCount = accounts?.filter((a) => a.status === "connected").length ?? 0

  const handleConnect = (accountId: string, name: string) => {
    connect.mutate(accountId, {
      onSuccess: () => toast.success(`${name} est connecté.`),
      // Fonction indisponible : le message explicatif est déjà affiché par useConnectAccount.
      onError: (error) => {
        if (!(error instanceof FeatureUnavailableError)) toast.error(`La connexion à ${name} a échoué. Réessayez.`)
      },
    })
  }

  return (
    <div className="w-full max-w-4xl rounded-3xl border border-border bg-card p-6 shadow-float sm:p-10">
      <Stepper current={step} />

      {step === 0 && (
        <section className="mt-10" aria-labelledby="step-connect">
          <h1 id="step-connect" className="text-2xl font-bold sm:text-[28px]">Connectez vos réseaux sociaux</h1>
          <p className="mt-2 max-w-xl text-sm text-ink-muted sm:text-base">
            Connectez vos comptes pour gérer toutes vos plateformes depuis une seule interface. Vous pourrez en ajouter d'autres plus tard.
          </p>
          <ul className="mt-8 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-4">
            {PLATFORM_ORDER.map((key) => {
              const p = PLATFORMS[key]
              const account = accounts?.find((a) => a.platform === key)
              const isPending = connect.isPending && connect.variables === account?.id
              const connected = account?.status === "connected"
              const soon = p.availability === "coming_soon"
              return (
                <li key={key} className={cn("flex flex-col items-center gap-3 rounded-2xl border p-4 text-center transition-colors sm:p-5", connected ? "border-success-100 bg-success-50/40" : "border-border")}>
                  <PlatformIcon platform={key} size="lg" />
                  <span className="text-sm font-semibold text-ink">{p.name}</span>
                  {soon ? (
                    <span className="inline-flex h-9 items-center rounded-lg bg-muted px-3 text-xs font-medium text-ink-muted">Bientôt disponible</span>
                  ) : connected ? (
                    <span className="inline-flex h-9 items-center gap-1 rounded-lg px-3 text-sm font-semibold text-success-700">
                      <Check className="size-4" aria-hidden="true" /> Connecté
                    </span>
                  ) : (
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={!account || isPending}
                      onClick={() => account && handleConnect(account.id, p.name)}
                      aria-label={`Connecter ${p.name}`}
                    >
                      {isPending && <LoaderCircle className="animate-spin" />}
                      {isPending ? "Connexion…" : "Connecter"}
                    </Button>
                  )}
                </li>
              )
            })}
          </ul>
          <p className="mt-4 text-xs text-ink-muted">Démonstration : la connexion est simulée, aucune donnée n'est envoyée aux réseaux.</p>
        </section>
      )}

      {step === 1 && (
        <section className="mt-10" aria-labelledby="step-custom">
          <h1 id="step-custom" className="text-2xl font-bold sm:text-[28px]">Personnalisez votre espace</h1>
          <p className="mt-2 text-sm text-ink-muted sm:text-base">Ces réglages servent à programmer vos publications à la bonne heure.</p>
          <div className="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="tz" className="text-[13px] font-semibold">Fuseau horaire</Label>
              <Select value={timezone} onValueChange={setTimezone}>
                <SelectTrigger id="tz" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {TIMEZONES.map((tz) => (
                    <SelectItem key={tz} value={tz}>{tz}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="lang" className="text-[13px] font-semibold">Langue</Label>
              <Select value={language} onValueChange={setLanguage}>
                <SelectTrigger id="lang" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="fr">Français</SelectItem>
                  <SelectItem value="en" disabled>English (bientôt)</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          <fieldset className="mt-8">
            <legend className="text-[13px] font-semibold text-ink">Vos objectifs (plusieurs choix possibles)</legend>
            <div className="mt-3 flex flex-wrap gap-2">
              {GOALS.map((g) => {
                const selected = goals.includes(g)
                return (
                  <button
                    key={g}
                    type="button"
                    aria-pressed={selected}
                    onClick={() => setGoals((prev) => (selected ? prev.filter((x) => x !== g) : [...prev, g]))}
                    className={cn(
                      "inline-flex items-center gap-1.5 rounded-full border px-4 py-2 text-sm font-medium transition-colors",
                      selected ? "border-brand-500 bg-brand-50 text-brand-700" : "border-border text-ink-soft hover:border-brand-200",
                    )}
                  >
                    {selected && <Check className="size-4" aria-hidden="true" />} {g}
                  </button>
                )
              })}
            </div>
          </fieldset>
        </section>
      )}

      {step === 2 && (
        <section className="mt-10 flex flex-col items-center text-center" aria-labelledby="step-welcome">
          <span className="inline-flex size-16 items-center justify-center rounded-2xl bg-brand-gradient text-white shadow-brand" aria-hidden="true">
            <PartyPopper className="size-8" />
          </span>
          <h1 id="step-welcome" className="mt-6 text-2xl font-bold sm:text-[28px]">Bienvenue sur SoxialConnect !</h1>
          <p className="mt-2 max-w-md text-sm text-ink-muted sm:text-base">
            {connectedCount > 0
              ? `${connectedCount} réseau${connectedCount > 1 ? "x" : ""} connecté${connectedCount > 1 ? "s" : ""}. Tout est prêt pour créer votre première publication.`
              : "Votre espace est prêt. Connectez un réseau quand vous le souhaitez depuis « Réseaux sociaux »."}
          </p>
          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Button size="lg" variant="outline" asChild>
              <Link to="/dashboard">Aller au tableau de bord</Link>
            </Button>
            <Button size="lg" asChild>
              <Link to="/content/create">
                <SquarePen /> Créer ma première publication
              </Link>
            </Button>
          </div>
        </section>
      )}

      {step < 2 && (
        <div className="mt-10 flex items-center justify-between gap-3">
          <Button variant="ghost" onClick={() => (step === 0 ? navigate("/register") : setStep(step - 1))}>
            <ArrowLeft /> Retour
          </Button>
          <div className="flex items-center gap-2">
            {step === 0 && connectedCount === 0 && (
              <Button variant="ghost" onClick={() => setStep(1)}>Passer</Button>
            )}
            <Button size="lg" onClick={() => setStep(step + 1)} className="min-w-36">
              Suivant <ArrowRight />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
