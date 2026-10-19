import { AlertTriangle, Info, X } from "lucide-react"
import { useId, useState, type KeyboardEvent } from "react"
import { Link } from "react-router"
import { MediaThumb } from "@/components/media/MediaThumb"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import { PLATFORMS } from "@/lib/platforms"
import { ACCOUNT_STATUS } from "@/lib/status"
import { cn } from "@/lib/utils"
import { xWeightedLength } from "@/lib/xText"
import type { MediaAsset, PlatformKey, SocialAccount, VariantOptions } from "@/types"
import { accountLabel, telegramTextLimit, type VariantDraft } from "./model"

// Tableaux Pinterest fictifs (futur : GET boards via l'adaptateur Pinterest).
const PINTEREST_BOARDS = ["Inspiration créative", "Moodboard automne", "Conseils créateurs"]

function Counter({ value, max }: { value: number; max: number }) {
  const over = value > max
  return (
    <span className={cn("text-xs tabular-nums", over ? "font-semibold text-danger-700" : "text-ink-muted")} aria-live="polite">
      {value.toLocaleString("fr-FR")} / {max.toLocaleString("fr-FR")}
    </span>
  )
}

function HashtagInput({ value, onChange, suggestions }: { value: string[]; onChange: (tags: string[]) => void; suggestions: string[] }) {
  const [draft, setDraft] = useState("")
  const id = useId()
  const add = (raw: string) => {
    const tags = raw
      .split(/[\s,]+/)
      .map((t) => t.replace(/^#/, "").trim())
      .filter(Boolean)
    if (tags.length) onChange([...new Set([...value, ...tags])].slice(0, 30))
    setDraft("")
  }
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" || e.key === "," || e.key === " ") {
      e.preventDefault()
      add(draft)
    } else if (e.key === "Backspace" && !draft && value.length) {
      onChange(value.slice(0, -1))
    }
  }
  return (
    <div className="space-y-2">
      <Label htmlFor={id} className="text-[13px] font-semibold">Hashtags</Label>
      <div className="flex min-h-11 flex-wrap items-center gap-1.5 rounded-lg border border-input bg-card px-2.5 py-1.5 focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/20">
        {value.map((t) => (
          <span key={t} className="inline-flex items-center gap-1 rounded-full bg-brand-50 py-0.5 pr-1 pl-2.5 text-xs font-medium text-brand-700">
            #{t}
            <button type="button" onClick={() => onChange(value.filter((x) => x !== t))} className="rounded-full p-0.5 hover:bg-brand-100" aria-label={`Retirer #${t}`}>
              <X className="size-3" />
            </button>
          </span>
        ))}
        <input
          id={id}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={onKey}
          onBlur={() => draft && add(draft)}
          placeholder={value.length ? "" : "Tapez un mot puis Entrée"}
          className="min-w-24 flex-1 bg-transparent py-1 text-sm outline-none placeholder:text-muted-foreground"
        />
      </div>
      {suggestions.filter((s) => !value.includes(s)).length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5 text-xs">
          <span className="text-ink-muted">Suggestions :</span>
          {suggestions
            .filter((s) => !value.includes(s))
            .map((s) => (
              <button key={s} type="button" onClick={() => onChange([...value, s])} className="rounded-full border border-dashed border-brand-200 px-2 py-0.5 text-brand-600 hover:bg-brand-50">
                + #{s}
              </button>
            ))}
        </div>
      )}
    </div>
  )
}

interface Props {
  platform: PlatformKey
  variant: VariantDraft
  mainBody: string
  /** Comptes choisis (destinations), dans l'ordre du choix. */
  selected: SocialAccount[]
  /** Comptes proposés pour ce réseau (connectés, plus ceux choisis qui ne le sont plus). */
  accountChoices?: SocialAccount[]
  media: MediaAsset[]
  hashtagSuggestions: string[]
  onChange: (patch: Partial<VariantDraft>) => void
}

/** Panneau d'édition d'une variante : champs propres à chaque plateforme (docs UX-09). */
export function VariantEditor({ platform, variant, mainBody, selected, accountChoices = [], media, hashtagSuggestions, onChange }: Props) {
  const cfg = PLATFORMS[platform]
  const fields = cfg.variantFields
  const uid = useId()
  const setOpt = (patch: Partial<VariantOptions>) => onChange({ options: { ...variant.options, ...patch } })
  const textLabel = fields.includes("description") ? "Description" : "Texte de la publication"
  const text = variant.customize ? variant.body : mainBody
  const hashtagsLen = (variant.options.hashtags ?? []).reduce((n, t) => n + t.length + 2, 0)
  // X : décompte de X (emoji et CJK = 2, URL = 23) sur le texte réellement envoyé, hashtags compris.
  const tags = variant.options.hashtags ?? []
  const sent = tags.length ? `${text.trimEnd()}\n\n${tags.map((t) => `#${t}`).join(" ")}` : text
  const length = platform === "x" ? xWeightedLength(sent) : text.length + hashtagsLen
  const videos = media.filter((m) => m.type === "video")
  const images = media.filter((m) => m.type === "image")
  // Une seule destination : ses sous-entités (ex. pages) restent sélectionnables comme avant.
  const account = selected.length === 1 ? selected[0] : undefined
  const notReady = selected.filter((a) => a.status !== "connected")
  const connectedIds = accountChoices.filter((a) => a.status === "connected").map((a) => a.id)
  const toggleAccount = (id: string, checked: boolean) =>
    onChange({ accountIds: checked ? [...variant.accountIds.filter((x) => x !== id), id] : variant.accountIds.filter((x) => x !== id) })

  return (
    <div className="space-y-5">
      {notReady.map((a) => (
        <p key={a.id} role="alert" className="flex items-start gap-2 rounded-xl bg-warning-50 px-3.5 py-3 text-sm text-warning-700">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <span>
            <span className="font-semibold">{accountLabel(a)}</span> : {a.statusMessage ?? ACCOUNT_STATUS[a.status].hint}{" "}
            <Link to="/social-accounts" className="font-semibold underline">Reconnecter</Link> ou décochez ce compte.
          </span>
        </p>
      ))}

      {accountChoices.length > 1 && (
        <fieldset className="space-y-2">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <legend className="text-[13px] font-semibold text-ink">
              {platform === "telegram" ? "Chaînes et groupes de destination" : "Comptes de destination"}
              <span className="ml-1.5 font-normal text-ink-muted">({variant.accountIds.length} sur {accountChoices.length})</span>
            </legend>
            <div className="flex gap-1">
              <Button type="button" variant="ghost" size="sm" disabled={connectedIds.every((id) => variant.accountIds.includes(id))} onClick={() => onChange({ accountIds: [...new Set([...variant.accountIds, ...connectedIds])] })}>
                Tout sélectionner
              </Button>
              <Button type="button" variant="ghost" size="sm" disabled={variant.accountIds.length === 0} onClick={() => onChange({ accountIds: [] })}>
                Aucun
              </Button>
            </div>
          </div>
          <p className="text-xs text-ink-muted">Une publication distincte est envoyée et suivie pour chaque compte coché.</p>
          <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {accountChoices.map((a) => {
              const checked = variant.accountIds.includes(a.id)
              const ready = a.status === "connected"
              return (
                <li key={a.id}>
                  <Label htmlFor={`${uid}-acc-${a.id}`} className={cn("flex cursor-pointer items-center gap-2.5 rounded-lg border border-border px-3 py-2.5 text-sm font-normal has-data-[state=checked]:border-brand-500 has-data-[state=checked]:bg-brand-50", !ready && !checked && "cursor-not-allowed opacity-60")}>
                    <Checkbox id={`${uid}-acc-${a.id}`} checked={checked} disabled={!ready && !checked} onCheckedChange={(c) => toggleAccount(a.id, c === true)} />
                    <span className="min-w-0 flex-1 truncate">{accountLabel(a)}</span>
                    {!ready && <span className="shrink-0 text-xs text-warning-700">{ACCOUNT_STATUS[a.status].label}</span>}
                  </Label>
                </li>
              )
            })}
          </ul>
        </fieldset>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label className="text-[13px] font-semibold" htmlFor={`${uid}-account`}>{platform === "telegram" ? "Chaîne ou groupe" : "Compte"}</Label>
          {accountChoices.length > 1 ? (
            // Plusieurs comptes : choix par cases à cocher ci-dessus ; résumé ici.
            <p id={`${uid}-account`} className={cn("flex h-10 items-center rounded-lg border border-border bg-muted/50 px-3 text-sm", selected.length ? "text-ink-soft" : "text-danger-700")}>
              {selected.length === 0 ? "Aucun compte coché" : selected.length === 1 ? accountLabel(selected[0]) : `${selected.length} comptes`}
            </p>
          ) : account && account.entities.length > 1 ? (
            <Select value={variant.options.accountEntityId ?? account.entities[0].id} onValueChange={(v) => setOpt({ accountEntityId: v })}>
              <SelectTrigger id={`${uid}-account`} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {account.entities.map((e) => (
                  <SelectItem key={e.id} value={e.id}>
                    {e.kind} · {e.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : (
            <p id={`${uid}-account`} className="flex h-10 items-center rounded-lg border border-border bg-muted/50 px-3 text-sm text-ink-soft">
              {account?.entities[0]?.name ?? (account ? accountLabel(account) : accountChoices[0] ? "Aucun compte coché" : "Aucun compte connecté")}
            </p>
          )}
        </div>
        {cfg.formats.length > 1 && (
          <div className="space-y-1.5">
            <Label className="text-[13px] font-semibold" htmlFor={`${uid}-format`}>Format</Label>
            <Select value={variant.format} onValueChange={(v) => onChange({ format: v as VariantDraft["format"] })}>
              <SelectTrigger id={`${uid}-format`} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {cfg.formats.map((f) => (
                  <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
      </div>

      {fields.includes("title") && (
        <div className="space-y-1.5">
          <div className="flex items-center justify-between">
            <Label htmlFor={`${uid}-title`} className="text-[13px] font-semibold">
              Titre {platform === "youtube" && <span className="text-danger-700">*</span>}
            </Label>
            <Counter value={variant.options.title?.length ?? 0} max={100} />
          </div>
          <Input id={`${uid}-title`} value={variant.options.title ?? ""} maxLength={120} onChange={(e) => setOpt({ title: e.target.value })} placeholder={platform === "youtube" ? "Titre de la vidéo" : "Titre de l'épingle"} />
        </div>
      )}

      <div className="space-y-2">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <Label htmlFor={`${uid}-body`} className="text-[13px] font-semibold">{textLabel}</Label>
          <div className="flex items-center gap-2">
            <Switch id={`${uid}-custom`} checked={variant.customize} onCheckedChange={(c) => onChange({ customize: c, body: c && !variant.body ? mainBody : variant.body })} />
            <Label htmlFor={`${uid}-custom`} className="text-xs font-normal text-ink-soft">Personnaliser pour {cfg.name}</Label>
          </div>
        </div>
        <Textarea
          id={`${uid}-body`}
          value={text}
          readOnly={!variant.customize}
          onChange={(e) => onChange({ body: e.target.value })}
          rows={5}
          className={cn(!variant.customize && "bg-muted/50 text-ink-soft")}
          aria-describedby={`${uid}-body-hint`}
        />
        <div className="flex items-center justify-between gap-2">
          <p id={`${uid}-body-hint`} className="text-xs text-ink-muted">
            {variant.customize ? `Texte spécifique à ${cfg.name}.` : "Identique au texte principal. Activez « Personnaliser » pour le modifier."}
          </p>
          <Counter value={length} max={platform === "telegram" ? telegramTextLimit(media.length) : cfg.maxChars} />
        </div>
      </div>

      {fields.includes("hashtags") && (
        <HashtagInput value={variant.options.hashtags ?? []} onChange={(tags) => setOpt({ hashtags: tags })} suggestions={hashtagSuggestions} />
      )}

      {fields.includes("destinationUrl") && (
        <div className="space-y-1.5">
          <Label htmlFor={`${uid}-url`} className="text-[13px] font-semibold">Lien de destination (optionnel)</Label>
          <Input id={`${uid}-url`} type="url" inputMode="url" placeholder="https://" value={variant.options.destinationUrl ?? ""} onChange={(e) => setOpt({ destinationUrl: e.target.value })} />
        </div>
      )}

      {fields.includes("board") && (
        <div className="space-y-1.5">
          <Label htmlFor={`${uid}-board`} className="text-[13px] font-semibold">
            Tableau <span className="text-danger-700">*</span>
          </Label>
          <Select value={variant.options.board ?? ""} onValueChange={(b) => setOpt({ board: b })}>
            <SelectTrigger id={`${uid}-board`} className="w-full">
              <SelectValue placeholder="Choisir un tableau" />
            </SelectTrigger>
            <SelectContent>
              {PINTEREST_BOARDS.map((b) => (
                <SelectItem key={b} value={b}>{b}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {fields.includes("privacy") && (
        <fieldset className="space-y-2">
          <legend className="text-[13px] font-semibold text-ink">
            Qui peut voir cette vidéo ? <span className="text-danger-700">*</span>
          </legend>
          <p className="text-xs text-ink-muted">TikTok demande que ce choix soit fait par vous, à chaque publication.</p>
          <RadioGroup value={variant.options.privacy ?? ""} onValueChange={(v) => setOpt({ privacy: v as VariantOptions["privacy"] })} className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {(
              [
                ["public", "Tout le monde"],
                ["followers", "Mes abonnés"],
                ["friends", "Amis (abonnements mutuels)"],
                ["private", "Moi uniquement"],
              ] as const
            ).map(([value, label]) => (
              <Label key={value} htmlFor={`${uid}-privacy-${value}`} className="flex cursor-pointer items-center gap-2.5 rounded-lg border border-border px-3 py-2.5 text-sm font-normal has-data-[state=checked]:border-brand-500 has-data-[state=checked]:bg-brand-50">
                <RadioGroupItem id={`${uid}-privacy-${value}`} value={value} /> {label}
              </Label>
            ))}
          </RadioGroup>
        </fieldset>
      )}

      {fields.includes("interactions") && (
        <fieldset className="space-y-2">
          <legend className="text-[13px] font-semibold text-ink">Interactions autorisées</legend>
          {(
            [
              ["allowComments", "Commentaires"],
              ["allowDuet", "Duo"],
              ["allowStitch", "Collage (Stitch)"],
            ] as const
          ).map(([key, label]) => (
            <div key={key} className="flex items-center justify-between rounded-lg border border-border px-3 py-2">
              <Label htmlFor={`${uid}-${key}`} className="text-sm font-normal">{label}</Label>
              <Switch id={`${uid}-${key}`} checked={Boolean(variant.options[key])} onCheckedChange={(c) => setOpt({ [key]: c })} />
            </div>
          ))}
        </fieldset>
      )}

      {fields.includes("commercialContent") && (
        <div className="flex items-start justify-between gap-3 rounded-lg border border-border px-3 py-2.5">
          <div>
            <Label htmlFor={`${uid}-commercial`} className="text-sm font-medium">Contenu commercial</Label>
            <p className="text-xs text-ink-muted">Activez si la vidéo fait la promotion d'une marque, d'un produit ou d'un service.</p>
          </div>
          <Switch id={`${uid}-commercial`} checked={Boolean(variant.options.commercialContent)} onCheckedChange={(c) => setOpt({ commercialContent: c })} />
        </div>
      )}

      {fields.includes("youtubeVisibility") && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor={`${uid}-yt-vis`} className="text-[13px] font-semibold">Visibilité</Label>
            <Select value={variant.options.youtubeVisibility ?? "private"} onValueChange={(v) => setOpt({ youtubeVisibility: v as VariantOptions["youtubeVisibility"] })}>
              <SelectTrigger id={`${uid}-yt-vis`} className="w-full" aria-describedby={`${uid}-yt-vis-hint`}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="public">Publique</SelectItem>
                <SelectItem value="unlisted">Non répertoriée</SelectItem>
                <SelectItem value="private">Privée</SelectItem>
              </SelectContent>
            </Select>
            <p id={`${uid}-yt-vis-hint`} className="text-xs text-ink-muted">
              Envoyée telle quelle à YouTube. Si YouTube applique une autre visibilité, l'historique des envois le signale.
            </p>
          </div>
          <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2 sm:mt-6">
            <Label htmlFor={`${uid}-kids`} className="text-sm font-normal">Conçue pour les enfants</Label>
            <Switch id={`${uid}-kids`} checked={Boolean(variant.options.madeForKids)} onCheckedChange={(c) => setOpt({ madeForKids: c })} />
          </div>
        </div>
      )}

      {(fields.includes("cover") || fields.includes("thumbnail")) && (
        <div className="space-y-2">
          <p className="text-[13px] font-semibold text-ink">{fields.includes("thumbnail") ? "Miniature" : "Couverture"}</p>
          {(fields.includes("thumbnail") ? images : [...images, ...videos]).length === 0 ? (
            <p className="flex items-center gap-2 text-xs text-ink-muted">
              <Info className="size-3.5" aria-hidden="true" /> Ajoutez une image à la publication pour la choisir comme {fields.includes("thumbnail") ? "miniature" : "couverture"}.
            </p>
          ) : (
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Choisir l'image">
              {(fields.includes("thumbnail") ? images : [...images, ...videos]).map((m) => {
                const key = fields.includes("thumbnail") ? "thumbnailMediaId" : "coverMediaId"
                const active = variant.options[key] === m.id
                return (
                  <button key={m.id} type="button" role="radio" aria-checked={active} aria-label={m.name} onClick={() => setOpt({ [key]: m.id })} className={cn("rounded-lg p-0.5", active ? "ring-2 ring-brand-500" : "ring-1 ring-border")}>
                    <MediaThumb media={m} className="size-14" showMeta={false} />
                  </button>
                )
              })}
            </div>
          )}
        </div>
      )}

      {fields.includes("firstComment") && (
        <div className="space-y-1.5">
          <Label htmlFor={`${uid}-comment`} className="text-[13px] font-semibold">Premier commentaire (optionnel)</Label>
          <Textarea id={`${uid}-comment`} rows={2} value={variant.options.firstComment ?? ""} onChange={(e) => setOpt({ firstComment: e.target.value })} placeholder="Ex. : le lien vers votre site" />
        </div>
      )}

      <p className="flex items-start gap-2 text-xs text-ink-muted">
        <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        {cfg.mediaHint}. Limites indicatives, vérifiées lors de la publication.
      </p>
    </div>
  )
}
