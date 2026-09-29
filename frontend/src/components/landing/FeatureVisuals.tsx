// Mini-interfaces illustrant les fonctionnalités sur la landing, construites avec les composants
// du design system (aucune capture d'écran ni image externe).
import { CheckCircle2, Clock, LoaderCircle, Sparkles } from "lucide-react"
import type { ReactNode } from "react"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { cn } from "@/lib/utils"
import type { PlatformKey } from "@/types"

function Frame({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn("surface-card relative w-full overflow-hidden p-5 shadow-float", className)} aria-hidden="true">
      {children}
    </div>
  )
}

export function ComposerVisual() {
  const variants: { p: PlatformKey; dims: string; label: string }[] = [
    { p: "instagram", dims: "1080 × 1080", label: "Image carrée" },
    { p: "tiktok", dims: "1080 × 1920", label: "Vidéo verticale" },
    { p: "facebook", dims: "1200 × 630", label: "Lien / Image" },
    { p: "linkedin", dims: "1200 × 627", label: "Image paysage" },
  ]
  return (
    <Frame>
      <div className="rounded-xl border border-border bg-page/60 p-4 text-sm text-ink-soft">
        Publier régulièrement ne suffit pas : voici 5 leviers simples pour booster votre visibilité 🚀
        <div className="mt-3 flex justify-between text-xs text-ink-muted">
          <span className="inline-flex items-center gap-1 text-brand-600">
            <Sparkles className="size-3.5" /> Amélioré avec l'IA
          </span>
          <span>98 / 2200</span>
        </div>
      </div>
      <p className="mt-4 text-xs font-semibold text-ink">Aperçu des versions</p>
      <div className="mt-2 grid grid-cols-2 gap-2.5">
        {variants.map((v) => (
          <div key={v.p} className="flex items-center gap-2.5 rounded-xl border border-border p-2.5">
            <PlatformIcon platform={v.p} size="sm" />
            <div className="min-w-0">
              <p className="truncate text-xs font-semibold text-ink">{v.dims}</p>
              <p className="truncate text-[11px] text-ink-muted">{v.label}</p>
            </div>
          </div>
        ))}
      </div>
    </Frame>
  )
}

export function CalendarVisual() {
  const days = ["Lun", "Mar", "Mer", "Jeu", "Ven", "Sam", "Dim"]
  const events: Record<number, { p: PlatformKey; t: string; tone: string }[]> = {
    0: [{ p: "instagram", t: "09:00", tone: "bg-info-50" }],
    1: [{ p: "youtube", t: "14:30", tone: "bg-info-50" }, { p: "linkedin", t: "18:00", tone: "bg-success-50" }],
    3: [{ p: "tiktok", t: "11:30", tone: "bg-info-50" }],
    4: [{ p: "facebook", t: "14:00", tone: "bg-success-50" }],
    5: [{ p: "pinterest", t: "12:00", tone: "bg-muted" }],
  }
  return (
    <Frame>
      <div className="mb-3 flex items-center justify-between">
        <p className="text-sm font-semibold text-ink">Cette semaine</p>
        <div className="flex gap-1 rounded-lg bg-muted p-1 text-[11px] font-medium">
          <span className="rounded-md px-2 py-0.5 text-ink-muted">Mois</span>
          <span className="rounded-md bg-card px-2 py-0.5 text-brand-600 shadow-sm">Semaine</span>
          <span className="rounded-md px-2 py-0.5 text-ink-muted">Jour</span>
        </div>
      </div>
      <div className="grid grid-cols-7 gap-1.5">
        {days.map((d, i) => (
          <div key={d} className="flex min-h-[132px] flex-col gap-1.5 rounded-lg bg-page/70 p-1.5">
            <span className="text-center text-[11px] font-medium text-ink-muted">{d}</span>
            {(events[i] ?? []).map((e) => (
              <span key={e.t + e.p} className={cn("flex flex-col items-center gap-1 rounded-md py-1.5", e.tone)}>
                <PlatformIcon platform={e.p} size="xs" />
                <span className="text-[10px] font-semibold text-ink-soft">{e.t}</span>
              </span>
            ))}
          </div>
        ))}
      </div>
    </Frame>
  )
}

export function PublishVisual() {
  const rows: { p: PlatformKey; name: string; state: "done" | "progress" | "scheduled" }[] = [
    { p: "instagram", name: "Instagram", state: "done" },
    { p: "facebook", name: "Facebook", state: "done" },
    { p: "tiktok", name: "TikTok", state: "progress" },
    { p: "youtube", name: "YouTube", state: "scheduled" },
  ]
  return (
    <Frame>
      <p className="text-sm font-semibold text-ink">Conseils pour booster votre visibilité</p>
      <p className="text-xs text-ink-muted">Publication multi-réseaux</p>
      <ul className="mt-4 space-y-2.5">
        {rows.map((r) => (
          <li key={r.p} className="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5">
            <PlatformIcon platform={r.p} size="sm" />
            <span className="flex-1 text-sm font-medium text-ink">{r.name}</span>
            {r.state === "done" && (
              <span className="inline-flex items-center gap-1 text-xs font-semibold text-success-700">
                <CheckCircle2 className="size-4" /> Publié
              </span>
            )}
            {r.state === "progress" && (
              <span className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600">
                <LoaderCircle className="size-4 animate-spin" /> En cours
              </span>
            )}
            {r.state === "scheduled" && (
              <span className="inline-flex items-center gap-1 text-xs font-semibold text-info-700">
                <Clock className="size-4" /> Demain 14:30
              </span>
            )}
          </li>
        ))}
      </ul>
    </Frame>
  )
}

export function MediaVisual() {
  return (
    <Frame>
      <div className="grid grid-cols-4 gap-2">
        {[1, 2, 3, 4, 5, 6, 12, 8].map((n, i) => (
          <div key={i} className={cn("aspect-square rounded-lg", `thumb-${n}`)} />
        ))}
      </div>
      <div className="mt-4 rounded-xl border border-border p-3">
        <div className="flex items-center justify-between text-xs">
          <span className="font-semibold text-ink">Clips détectés</span>
          <span className="rounded-full bg-violet-50 px-2 py-0.5 font-semibold text-violet-600">6 clips</span>
        </div>
        <div className="relative mt-3 h-8 rounded-md bg-page">
          {[
            [8, 10, "bg-brand-500"],
            [30, 12, "bg-violet-500"],
            [55, 9, "bg-brand-400"],
            [78, 11, "bg-violet-500"],
          ].map(([left, width, color], i) => (
            <span key={i} className={cn("absolute top-1 bottom-1 rounded", String(color))} style={{ left: `${left}%`, width: `${width}%` }} />
          ))}
        </div>
        <div className="mt-2 flex gap-1.5 text-[11px] text-ink-muted">
          <span className="rounded bg-muted px-1.5 py-0.5">9:16</span>
          <span className="rounded bg-muted px-1.5 py-0.5">1:1</span>
          <span className="rounded bg-muted px-1.5 py-0.5">16:9</span>
          <span className="ml-auto">Sous-titres automatiques</span>
        </div>
      </div>
    </Frame>
  )
}

export function AIVisual() {
  return (
    <Frame className="space-y-3">
      <div className="ml-auto max-w-[80%] rounded-2xl rounded-br-md bg-brand-500 px-4 py-2.5 text-sm text-white">
        Écris une légende pour annoncer mon live de vendredi
      </div>
      <div className="max-w-[88%] rounded-2xl rounded-bl-md border border-border bg-page/60 px-4 py-3 text-sm text-ink-soft">
        <span className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-brand-600">
          <Sparkles className="size-3.5" /> Assistant IA
        </span>
        Rendez-vous vendredi à 18h pour un live 100 % questions-réponses ✨ Posez vos questions en commentaire dès maintenant 👇
      </div>
      <div className="flex flex-wrap gap-1.5">
        {["#live", "#creation", "#conseils", "#questionsreponses"].map((h) => (
          <span key={h} className="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700">
            {h}
          </span>
        ))}
      </div>
    </Frame>
  )
}

export function AnalyticsVisual() {
  const current = "M0 70 L40 58 L80 62 L120 40 L160 48 L200 28 L240 34 L280 14"
  const previous = "M0 78 L40 72 L80 74 L120 64 L160 60 L200 58 L240 50 L280 46"
  return (
    <Frame>
      <div className="grid grid-cols-3 gap-2.5">
        {[
          ["Impressions", "324,8 k", "+32 %"],
          ["Engagement", "21,4 k", "+18 %"],
          ["Followers", "+1,2 k", "+12 %"],
        ].map(([l, v, c]) => (
          <div key={l} className="rounded-xl border border-border p-2.5">
            <p className="text-[11px] text-ink-muted">{l}</p>
            <p className="text-base font-bold text-ink">{v}</p>
            <p className="text-[11px] font-semibold text-success-700">{c}</p>
          </div>
        ))}
      </div>
      <svg viewBox="0 0 280 90" className="mt-4 h-28 w-full" preserveAspectRatio="none">
        <defs>
          <linearGradient id="lv-fill" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0" stopColor="var(--color-brand-500)" stopOpacity="0.25" />
            <stop offset="1" stopColor="var(--color-brand-500)" stopOpacity="0" />
          </linearGradient>
        </defs>
        <path d={`${current} L280 90 L0 90 Z`} fill="url(#lv-fill)" />
        <path d={previous} fill="none" stroke="var(--color-brand-200)" strokeWidth="2" strokeDasharray="4 4" />
        <path d={current} fill="none" stroke="var(--color-brand-500)" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </Frame>
  )
}
