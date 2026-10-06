import { AlertCircle, CalendarClock, ChevronDown, ImagePlus, LoaderCircle, Send, Sparkles, Wand2, X } from "lucide-react"
import { type ReactNode, useEffect, useMemo, useState } from "react"
import { useForm } from "react-hook-form"
import { Link, useBlocker, useLocation, useNavigate, useParams } from "react-router"
import { toast } from "sonner"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { adaptForPlatform, improveText, suggestHashtags } from "@/components/content/composer/aiAssist"
import {
  type ComposerValues,
  EMPTY_VALUES,
  type Issue,
  type VariantDraft,
  defaultVariant,
  connectedAccounts,
  fromPost,
  platformAccount,
  telegramTextLimit,
  toDraftInput,
  validateComposer,
  variantText,
} from "@/components/content/composer/model"
import { NetworkPicker } from "@/components/content/composer/NetworkPicker"
import { VariantEditor } from "@/components/content/composer/VariantEditor"
import { VariantPreview } from "@/components/content/composer/VariantPreview"
import { ScheduleDialog } from "@/components/content/ScheduleDialog"
import { NotificationBell } from "@/components/notifications/NotificationBell"
import { MediaPickerDialog } from "@/components/media/MediaPickerDialog"
import { MediaThumb } from "@/components/media/MediaThumb"
import { ConfirmDialog } from "@/components/shared/ConfirmDialog"
import { PageLoader } from "@/components/shared/PageLoader"
import { ErrorState } from "@/components/shared/States"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Textarea } from "@/components/ui/textarea"
import { usePost, useSavePost } from "@/hooks/queries/content"
import { useSocialAccounts } from "@/hooks/queries/social"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"
import { useMediaMap } from "@/hooks/useMediaMap"
import { features, MEDIA_UNAVAILABLE, PUBLISHING_UNAVAILABLE } from "@/lib/features"
import { ApiError, apiErrorMessage } from "@/lib/http"
import { PLATFORMS } from "@/lib/platforms"
import { cn } from "@/lib/utils"
import type { PlatformKey } from "@/types"

type Mode = "draft" | "schedule" | "publish"

function StepTitle({ n, children, id }: { n: number; children: ReactNode; id?: string }) {
  return (
    <h2 id={id} className="flex items-center gap-2.5 text-[15px] font-semibold">
      <span className="inline-flex size-6 items-center justify-center rounded-full bg-brand-50 text-xs font-bold text-brand-600" aria-hidden="true">
        {n}
      </span>
      {children}
    </h2>
  )
}

export default function ComposerPage() {
  const { postId } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const prefill = (location.state ?? {}) as { body?: string; scheduledAt?: string; mediaIds?: string[] }
  const isEdit = Boolean(postId)
  useDocumentTitle(isEdit ? "Modifier la publication" : "Créer du contenu")

  const existing = usePost(postId)
  const { data: accounts = [] } = useSocialAccounts()
  const save = useSavePost()

  // Médias : identifiants de la médiathèque (le serveur vérifie propriété, statut « prêt » et quarantaine).
  const form = useForm<ComposerValues>({ defaultValues: { ...EMPTY_VALUES, body: prefill.body ?? "", mediaIds: features.media ? (prefill.mediaIds ?? []) : [] } })
  const { register, watch, setValue, reset, formState } = form
  // Lu pendant le rendu pour que React Hook Form suive isDirty (proxy d'abonnement).
  const { isDirty } = formState
  const values = watch()
  const mediaMap = useMediaMap(values.mediaIds)

  const [activeTab, setActiveTab] = useState<PlatformKey | undefined>()
  const [issues, setIssues] = useState<Issue[]>([])
  const [pickerOpen, setPickerOpen] = useState(false)
  const [scheduleOpen, setScheduleOpen] = useState(Boolean(prefill.scheduledAt))
  const [submitted, setSubmitted] = useState(false)
  const [aiBusy, setAiBusy] = useState(false)

  // Chargement d'une publication existante (mode édition).
  useEffect(() => {
    if (existing.data) {
      reset(fromPost(existing.data))
      setActiveTab(existing.data.variants[0]?.platform)
    }
  }, [existing.data, reset])

  // Garde-fou : quitter la page avec des modifications non enregistrées.
  const blocker = useBlocker(({ currentLocation, nextLocation }) => isDirty && !submitted && currentLocation.pathname !== nextLocation.pathname)

  const media = useMemo(() => values.mediaIds.map((id) => mediaMap[id]).filter(Boolean), [values.mediaIds, mediaMap])
  const tab = activeTab && values.platforms.includes(activeTab) ? activeTab : values.platforms[0]
  // Telegram : 4096 caractères, 1024 en légende d'un média.
  const maxChars = (p: PlatformKey) => (p === "telegram" ? telegramTextLimit(values.mediaIds.length) : PLATFORMS[p].maxChars)
  const limit = values.platforms.length ? Math.min(...values.platforms.map(maxChars)) : null
  const limitPlatform = values.platforms.find((p) => maxChars(p) === limit)

  const togglePlatform = (p: PlatformKey) => {
    const selected = values.platforms.includes(p)
    const next = selected ? values.platforms.filter((x) => x !== p) : [...values.platforms, p]
    setValue("platforms", next, { shouldDirty: true })
    // Compte cible fixé dès la sélection du réseau (premier compte connecté), modifiable ensuite.
    if (!selected && !values.variants[p]) setValue(`variants.${p}`, { ...defaultVariant(p), accountId: connectedAccounts(accounts, p)[0]?.id }, { shouldDirty: true })
    if (!selected) setActiveTab(p)
    setIssues([])
  }

  const updateVariant = (p: PlatformKey, patch: Partial<VariantDraft>) => {
    const current = values.variants[p] ?? defaultVariant(p)
    setValue(`variants.${p}`, { ...current, ...patch }, { shouldDirty: true })
  }

  const generateVersions = () => {
    if (!values.platforms.length) {
      toast.info("Sélectionnez d'abord les réseaux à adapter.")
      return
    }
    setAiBusy(true)
    setTimeout(() => {
      for (const p of values.platforms) {
        const adapted = adaptForPlatform(values.body, p)
        const current = values.variants[p] ?? defaultVariant(p)
        updateVariant(p, {
          customize: true,
          body: adapted.body,
          options: { ...current.options, ...(adapted.title ? { title: current.options.title || adapted.title } : {}), hashtags: current.options.hashtags?.length ? current.options.hashtags : suggestHashtags(p) },
        })
      }
      setAiBusy(false)
      toast.success("Versions adaptées à chaque réseau. Relisez-les avant de publier.")
    }, 900)
  }

  const submit = (mode: Mode, scheduledAt: string | null = null) => {
    if (mode === "publish" && !features.publishing) return
    const found = validateComposer(values, accounts, mediaMap, mode)
    setIssues(found)
    const blocking = found.filter((i) => i.level === "error")
    if (blocking.length) {
      toast.error(blocking.length === 1 ? blocking[0].message : `${blocking.length} points à corriger avant de continuer.`)
      const firstPlatform = blocking.find((i) => i.platform)?.platform
      if (firstPlatform) setActiveTab(firstPlatform)
      return
    }
    if (mode === "schedule" && !scheduledAt) {
      setScheduleOpen(true)
      return
    }
    setSubmitted(true)
    save.mutate(
      { input: toDraftInput(values, accounts, scheduledAt), mode, id: postId, lockVersion: existing.data?.lockVersion },
      {
        onSuccess: () => {
          setScheduleOpen(false)
          if (mode === "publish") {
            toast.success("Publication en file d'attente. Le résultat de l'envoi s'affichera dans vos publications.")
            navigate("/content")
          } else if (mode === "schedule") {
            toast.success("Publication programmée.")
            navigate("/content/calendar")
          } else {
            toast.success("Brouillon enregistré.")
            navigate("/content/drafts")
          }
        },
        onError: (error) => {
          setSubmitted(false)
          if (error instanceof ApiError && error.status === 422) {
            // Erreurs de validation du serveur : affichées dans l'encadré « Avant de continuer ».
            const messages = [...new Set(Object.values(error.errors).flat())]
            setIssues(messages.map((message) => ({ platform: null, level: "error", message })))
            setScheduleOpen(false)
            toast.error(messages.length === 1 ? messages[0] : `${messages.length} points à corriger avant de continuer.`)
          } else if (error instanceof ApiError && error.status === 409) {
            // Conflit de version : la publication a changé ailleurs (autre onglet, autre action).
            setScheduleOpen(false)
            toast.error(error.message, {
              action: { label: "Recharger", onClick: () => void existing.refetch() },
            })
          } else {
            toast.error(apiErrorMessage(error, "L'enregistrement a échoué. Réessayez."))
          }
        },
      },
    )
  }

  if (isEdit && existing.isLoading) return <PageLoader />
  if (isEdit && existing.isError) return <ErrorState title="Publication introuvable" description="Elle a peut-être été supprimée." onRetry={() => existing.refetch()} />

  const globalIssues = issues.filter((i) => !i.platform)
  const issuesFor = (p: PlatformKey) => issues.filter((i) => i.platform === p)
  const pending = save.isPending

  return (
    <div className="mx-auto max-w-5xl pb-36 md:pb-0">
      <header className="mb-6 flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold sm:text-[26px]">{isEdit ? "Modifier la publication" : "Créer du contenu"}</h1>
          <p className="mt-1 text-sm text-ink-muted">Rédigez votre publication et ajoutez vos médias.</p>
        </div>
        <div className="flex items-center gap-2">
          <NotificationBell className="hidden md:inline-flex" />
        <Button variant="ghost" size="icon" asChild>
          <Link to="/content" aria-label="Fermer et revenir aux publications">
            <X className="size-5" />
          </Link>
        </Button>
        </div>
      </header>

      {issues.length > 0 && (
        <div role="alert" className="mb-5 rounded-2xl border border-danger-100 bg-danger-50/60 p-4">
          <p className="flex items-center gap-2 text-sm font-semibold text-danger-700">
            <AlertCircle className="size-4" aria-hidden="true" /> Avant de continuer
          </p>
          <ul className="mt-2 list-disc space-y-1 pl-6 text-sm text-ink-soft">
            {issues.map((i, k) => (
              <li key={k}>
                {i.platform ? (
                  <button type="button" className="text-left underline-offset-2 hover:underline" onClick={() => setActiveTab(i.platform!)}>
                    {i.message}
                  </button>
                ) : (
                  i.message
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      <form onSubmit={(e) => e.preventDefault()} className="space-y-5">
        {/* 1. Texte */}
        <section className="surface-card p-5" aria-labelledby="step-text">
          <div className="mb-3 flex items-center justify-between gap-3">
            <StepTitle n={1} id="step-text">Rédigez votre message</StepTitle>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="text-brand-600"
              onClick={() => {
                setValue("body", improveText(values.body), { shouldDirty: true })
                toast.success("Texte amélioré par l'IA (simulation).")
              }}
            >
              <Wand2 /> Améliorer
            </Button>
          </div>
          <Label htmlFor="composer-body" className="sr-only">Texte de la publication</Label>
          <Textarea
            id="composer-body"
            rows={5}
            placeholder="Quoi souhaitez-vous partager aujourd'hui ?"
            className={cn("min-h-32 resize-y border-none bg-page/60 px-4 py-3 text-[15px] shadow-none", globalIssues.length && !values.body && "ring-2 ring-danger-100")}
            {...register("body")}
          />
          <div className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-2">
              <Label htmlFor="composer-title" className="shrink-0 text-xs text-ink-muted">Titre interne</Label>
              <Input id="composer-title" placeholder="Visible uniquement par vous (optionnel)" className="h-9 text-sm" {...register("title")} />
            </div>
            <span className={cn("text-xs tabular-nums", limit && values.body.length > limit ? "font-semibold text-danger-700" : "text-ink-muted")} aria-live="polite">
              {values.body.length.toLocaleString("fr-FR")}
              {limit ? ` / ${limit.toLocaleString("fr-FR")}` : " caractères"}
              {limitPlatform && <span className="ml-1">({PLATFORMS[limitPlatform].name})</span>}
            </span>
          </div>
        </section>

        {/* 2 & 3. Médias + réseaux */}
        <div className="grid grid-cols-1 gap-5 md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
          <section className="surface-card p-5" aria-labelledby="step-media">
            <StepTitle n={2} id="step-media">Ajoutez des médias</StepTitle>
            {!features.media ? (
              // Emplacement conservé : l'ajout de médias arrive avec la phase Médias.
              <div className="mt-4 flex w-full items-center gap-4 rounded-xl border border-dashed border-border bg-page/60 p-4 text-left" aria-disabled="true">
                <span className="inline-flex size-12 items-center justify-center rounded-xl bg-card text-ink-muted shadow-card" aria-hidden="true">
                  <ImagePlus className="size-6" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-semibold text-ink-soft">Ajouter des médias</span>
                  <span className="block text-xs text-ink-muted">Images, vidéos, GIF</span>
                </span>
                <span className="shrink-0 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-600">{MEDIA_UNAVAILABLE}</span>
              </div>
            ) : media.length === 0 ? (
              <button type="button" onClick={() => setPickerOpen(true)} className="mt-4 flex w-full items-center gap-4 rounded-xl border border-dashed border-brand-200 bg-brand-50/40 p-4 text-left hover:bg-brand-50">
                <span className="inline-flex size-12 items-center justify-center rounded-xl bg-card text-brand-500 shadow-card" aria-hidden="true">
                  <ImagePlus className="size-6" />
                </span>
                <span>
                  <span className="block text-sm font-semibold text-ink">Ajouter des médias</span>
                  <span className="block text-xs text-ink-muted">Images, vidéos, GIF</span>
                </span>
              </button>
            ) : (
              <div className="mt-4">
                <ul className="flex flex-wrap gap-2">
                  {media.map((m) => (
                    <li key={m.id} className="relative">
                      <MediaThumb media={m} className="size-20" />
                      <button
                        type="button"
                        onClick={() => setValue("mediaIds", values.mediaIds.filter((id) => id !== m.id), { shouldDirty: true })}
                        className="absolute -top-1.5 -right-1.5 inline-flex size-6 items-center justify-center rounded-full bg-navy-950 text-white ring-2 ring-card"
                        aria-label={`Retirer ${m.name}`}
                      >
                        <X className="size-3.5" />
                      </button>
                    </li>
                  ))}
                  <li>
                    <button type="button" onClick={() => setPickerOpen(true)} className="flex size-20 items-center justify-center rounded-lg border border-dashed border-brand-200 text-brand-500 hover:bg-brand-50" aria-label="Ajouter d'autres médias">
                      <ImagePlus className="size-6" />
                    </button>
                  </li>
                </ul>
              </div>
            )}
          </section>

          <section className="surface-card p-5" aria-labelledby="step-networks">
            <StepTitle n={3} id="step-networks">Choisissez vos réseaux</StepTitle>
            <div className="mt-4">
              <NetworkPicker selected={values.platforms} accounts={accounts} onToggle={togglePlatform} />
            </div>
          </section>
        </div>

        {/* 4. Versions par réseau */}
        <section className="surface-card p-5" aria-labelledby="step-versions">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <StepTitle n={4} id="step-versions">Versions par réseau</StepTitle>
            <Button type="button" variant="secondary" onClick={generateVersions} disabled={aiBusy}>
              {aiBusy ? <LoaderCircle className="animate-spin" /> : <Sparkles />}
              {aiBusy ? "Génération…" : "Générer avec l'IA"}
            </Button>
          </div>
          {values.platforms.length === 0 ? (
            <p className="mt-4 rounded-xl bg-page p-4 text-sm text-ink-muted">
              Choisissez au moins un réseau à l'étape 3 : vous pourrez ensuite adapter le texte et les options pour chacun.
            </p>
          ) : (
            <Tabs value={tab} onValueChange={(v) => setActiveTab(v as PlatformKey)} className="mt-4">
              <TabsList variant="line" className="overflow-x-auto">
                {values.platforms.map((p) => (
                  <TabsTrigger key={p} value={p} className="gap-2">
                    <PlatformIcon platform={p} size="xs" />
                    {PLATFORMS[p].name}
                    {issuesFor(p).some((i) => i.level === "error") && <span className="size-2 rounded-full bg-danger-500" aria-label="à corriger" />}
                  </TabsTrigger>
                ))}
              </TabsList>
              {values.platforms.map((p) => {
                const variant = values.variants[p] ?? defaultVariant(p)
                const account = platformAccount(accounts, p, variant.accountId)
                // Comptes proposés : les comptes connectés du réseau, plus le compte déjà choisi s'il ne
                // l'est plus (pour l'afficher avec son état, sans jamais en substituer un autre).
                const connected = connectedAccounts(accounts, p)
                const accountChoices = account && !connected.some((a) => a.id === account.id) ? [account, ...connected] : connected
                return (
                  <TabsContent key={p} value={p} className="pt-5">
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
                      <VariantEditor
                        platform={p}
                        variant={variant}
                        mainBody={values.body}
                        account={account}
                        accountChoices={accountChoices}
                        media={media}
                        hashtagSuggestions={suggestHashtags(p)}
                        onChange={(patch) => updateVariant(p, patch)}
                      />
                      <div className="lg:sticky lg:top-6 lg:self-start">
                        <p className="mb-2 text-xs font-semibold text-ink-muted">Aperçu {PLATFORMS[p].name}</p>
                        <VariantPreview platform={p} text={variantText(values, p)} variant={variant} media={media} accountName={account?.entities[0]?.name ?? account?.displayName ?? "Votre compte"} />
                      </div>
                    </div>
                  </TabsContent>
                )
              })}
            </Tabs>
          )}
        </section>

        {/* 5. Aperçu des versions (maquette) */}
        <section className="surface-card p-5" aria-labelledby="step-preview">
          <StepTitle n={5} id="step-preview">Aperçu des versions</StepTitle>
          {values.platforms.length === 0 ? (
            <p className="mt-3 text-sm text-ink-muted">Les formats de chaque réseau sélectionné apparaîtront ici.</p>
          ) : (
            <ul className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {values.platforms.map((p) => {
                const cfg = PLATFORMS[p]
                const errors = issuesFor(p).filter((i) => i.level === "error").length
                return (
                  <li key={p}>
                    <button type="button" onClick={() => setActiveTab(p)} className={cn("flex w-full items-start gap-3 rounded-xl border p-3.5 text-left transition-colors hover:border-brand-200", tab === p ? "border-brand-500 bg-brand-50/50" : "border-border")}>
                      <PlatformIcon platform={p} size="md" />
                      <span className="min-w-0">
                        <span className="block text-sm font-semibold text-ink">{cfg.name}</span>
                        <span className="block text-xs text-ink-muted">{cfg.preview.width} × {cfg.preview.height}</span>
                        <span className="block text-xs text-ink-muted">{cfg.preview.label}</span>
                        {errors > 0 && <span className="mt-1 block text-xs font-semibold text-danger-700">{errors} point(s) à corriger</span>}
                      </span>
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
        </section>

        {/* Actions (barre fixe en bas sur mobile) */}
        <div className="fixed inset-x-0 bottom-0 z-20 grid grid-cols-2 gap-2 border-t border-border bg-card/95 p-3 backdrop-blur md:static md:flex md:justify-end md:border-none md:bg-transparent md:p-0 md:backdrop-blur-none">
          <Button type="button" variant="outline" className="md:flex-none" disabled={pending} onClick={() => submit("draft")}>
            <span className="md:hidden">Brouillon</span>
            <span className="hidden md:inline">Enregistrer comme brouillon</span>
          </Button>
          <div className="flex md:flex-none">
            {/* Très petits écrans (< 375 px) : icône masquée pour que le bouton tienne dans sa colonne. */}
            <Button type="button" className="min-w-0 flex-1 rounded-r-none max-[374px]:px-2 md:flex-none" disabled={pending} onClick={() => submit("schedule")}>
              <CalendarClock className="max-[374px]:hidden" /> Programmer
            </Button>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" size="icon" className="rounded-l-none border-l border-brand-400" aria-label="Plus d'options de programmation" disabled={pending}>
                  <ChevronDown />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => submit("schedule")}>Choisir la date et l'heure</DropdownMenuItem>
                <DropdownMenuItem disabled>Au meilleur moment (bientôt)</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
          {features.publishing ? (
            <Button type="button" className="order-first col-span-2 md:order-none md:flex-none" disabled={pending} onClick={() => submit("publish")}>
              {pending ? <LoaderCircle className="animate-spin" /> : <Send />} Publier maintenant
            </Button>
          ) : (
            // Visible pour préparer l'UX, désactivé : aucune publication réelle avant la phase Publishing.
            <div className="order-first col-span-2 flex flex-col items-stretch gap-1 md:order-none md:flex-none md:items-center">
              <Button type="button" disabled aria-describedby="publish-unavailable">
                <Send /> Publier maintenant
              </Button>
              <span id="publish-unavailable" className="text-center text-[11px] leading-tight text-ink-muted">
                {PUBLISHING_UNAVAILABLE}
              </span>
            </div>
          )}
        </div>
      </form>

      {pickerOpen && features.media && (
        <MediaPickerDialog open onOpenChange={setPickerOpen} initial={values.mediaIds} onConfirm={(ids) => setValue("mediaIds", ids, { shouldDirty: true })} />
      )}
      {scheduleOpen && (
        <ScheduleDialog
          open
          onOpenChange={setScheduleOpen}
          initial={prefill.scheduledAt ?? existing.data?.scheduledAt ?? null}
          pending={pending}
          onConfirm={(iso) => submit("schedule", iso)}
        />
      )}
      <ConfirmDialog
        open={blocker.state === "blocked"}
        onOpenChange={(o) => !o && blocker.state === "blocked" && blocker.reset()}
        title="Quitter sans enregistrer ?"
        description="Vos modifications seront perdues. Vous pouvez aussi les enregistrer comme brouillon."
        confirmLabel="Quitter la page"
        onConfirm={() => blocker.state === "blocked" && blocker.proceed()}
      />
    </div>
  )
}
