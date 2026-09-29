import { ArrowLeft, Inbox, Plus } from "lucide-react"
import { useState, type ReactNode } from "react"
import { Link } from "react-router"
import { Logo } from "@/components/brand/Logo"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { StatCard } from "@/components/shared/StatCard"
import { EmptyState } from "@/components/shared/States"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { FilterTabs } from "@/components/shared/FilterTabs"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"
import { PLATFORM_ORDER } from "@/lib/platforms"
import { ACCOUNT_STATUS, POST_STATUS } from "@/lib/status"

// Reproduit la planche « Design System » de la maquette, à partir des tokens réels (index.css).
const COLORS = [
  { name: "Primaire", token: "--color-brand-500", cls: "bg-brand-500" },
  { name: "Secondaire", token: "--color-violet-500", cls: "bg-violet-500" },
  { name: "Succès", token: "--color-success-500", cls: "bg-success-500" },
  { name: "Avertissement", token: "--color-warning-500", cls: "bg-warning-500" },
  { name: "Erreur", token: "--color-danger-500", cls: "bg-danger-500" },
  { name: "Neutre", token: "--color-neutral-500", cls: "bg-neutral-500" },
  { name: "Barre latérale", token: "--color-navy-900", cls: "bg-navy-900" },
  { name: "Encre", token: "--color-ink", cls: "bg-ink" },
]

const TYPE = [
  { label: "Titre 48", cls: "text-5xl font-extrabold" },
  { label: "Titre 32", cls: "text-[32px] font-bold" },
  { label: "Titre 24", cls: "text-2xl font-bold" },
  { label: "Sous-titre 16", cls: "text-base font-semibold" },
  { label: "Texte 14", cls: "text-sm" },
  { label: "Légende 12", cls: "text-xs text-ink-muted" },
]

function DemoTabs() {
  const [v, setV] = useState("all")
  return (
    <FilterTabs
      className="mt-6 border-b border-border"
      label="Démonstration d'onglets"
      value={v}
      onChange={setV}
      items={[
        { value: "all", label: "Tous" },
        { value: "images", label: "Images" },
        { value: "videos", label: "Vidéos" },
      ]}
    />
  )
}

function Block({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="surface-card p-6" aria-labelledby={`ds-${title}`}>
      <h2 id={`ds-${title}`} className="mb-5 text-lg font-bold">{title}</h2>
      {children}
    </section>
  )
}

export default function DesignSystemPage() {
  useDocumentTitle("Design system")
  return (
    <div className="min-h-dvh bg-page">
      <header className="border-b border-border bg-card">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
          <Logo />
          <Button variant="ghost" asChild>
            <Link to="/"><ArrowLeft /> Retour au site</Link>
          </Button>
        </div>
      </header>
      <main className="mx-auto max-w-6xl space-y-6 px-4 py-10 sm:px-6">
        <div>
          <h1 className="text-3xl font-bold">Design System</h1>
          <p className="mt-1 text-ink-muted">Couleurs, typographie, composants et états de SocialPulse — source : maquette, tokens centralisés dans <code className="rounded bg-muted px-1.5 py-0.5 text-sm">src/index.css</code>.</p>
        </div>

        <Block title="Couleurs principales">
          <ul className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            {COLORS.map((c) => (
              <li key={c.token}>
                <div className={`h-16 rounded-xl ${c.cls}`} aria-hidden="true" />
                <p className="mt-2 text-sm font-semibold text-ink">{c.name}</p>
                <p className="font-mono text-xs text-ink-muted">{c.token}</p>
              </li>
            ))}
          </ul>
        </Block>

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Block title="Typographie">
            <p className="mb-4 text-4xl font-bold">Inter <span className="text-ink-muted">Aa</span></p>
            <ul className="space-y-3">
              {TYPE.map((t) => (
                <li key={t.label} className="flex items-baseline justify-between gap-4 border-b border-border pb-2 last:border-none">
                  <span className={`truncate ${t.cls}`}>SocialPulse</span>
                  <span className="shrink-0 text-xs text-ink-muted">{t.label}</span>
                </li>
              ))}
            </ul>
            <p className="mt-4 font-script text-3xl text-ink-soft">Créez. Planifiez. Publiez.</p>
          </Block>

          <Block title="Composants">
            <div className="flex flex-wrap gap-3">
              <Button>Bouton principal</Button>
              <Button variant="outline">Bouton secondaire</Button>
              <Button variant="secondary">Connecter</Button>
              <Button variant="ghost">Discret</Button>
              <Button variant="destructive">Supprimer</Button>
              <Button disabled>Désactivé</Button>
              <Button size="icon" aria-label="Ajouter"><Plus /></Button>
            </div>
            <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="ds-input" className="text-[13px] font-semibold">Champ de saisie</Label>
                <Input id="ds-input" placeholder="Texte ici…" />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="ds-invalid" className="text-[13px] font-semibold">Champ en erreur</Label>
                <Input id="ds-invalid" aria-invalid defaultValue="adresse@invalide" />
                <p className="text-xs font-medium text-danger-700">Saisissez une adresse e-mail valide.</p>
              </div>
            </div>
            <div className="mt-6 flex flex-wrap items-center gap-6">
              <span className="flex items-center gap-2"><Switch id="ds-toggle" defaultChecked /><Label htmlFor="ds-toggle">Toggle</Label></span>
              <span className="flex items-center gap-2"><Checkbox id="ds-check" defaultChecked /><Label htmlFor="ds-check">Checkbox</Label></span>
            </div>
            <DemoTabs />
          </Block>
        </div>

        <Block title="États">
          <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
            <div>
              <p className="mb-3 text-sm font-semibold text-ink-muted">Comptes sociaux</p>
              <div className="flex flex-wrap gap-2">
                {Object.values(ACCOUNT_STATUS).map((s) => <StatusBadge key={s.label} meta={s} />)}
              </div>
            </div>
            <div>
              <p className="mb-3 text-sm font-semibold text-ink-muted">Publications</p>
              <div className="flex flex-wrap gap-2">
                {Object.values(POST_STATUS).map((s) => <StatusBadge key={s.label} meta={s} />)}
              </div>
            </div>
          </div>
          <div className="mt-6 flex flex-wrap gap-2">
            {(["secondary", "success", "warning", "danger", "info", "neutral", "violet"] as const).map((v) => <Badge key={v} variant={v}>{v}</Badge>)}
          </div>
        </Block>

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Block title="Plateformes">
            <div className="flex flex-wrap gap-3">
              {PLATFORM_ORDER.map((p) => <PlatformIcon key={p} platform={p} size="lg" title={p} />)}
            </div>
          </Block>
          <Block title="Carte KPI">
            <StatCard label="Total d'impressions" value="48,2 k" change={32} changeLabel="vs hier" />
          </Block>
        </div>

        <Block title="État vide">
          <EmptyState icon={Inbox} title="Aucune publication" description="Les états vides expliquent la situation et proposent l'action utile." action={<Button><Plus /> Créer une publication</Button>} />
        </Block>
      </main>
    </div>
  )
}
