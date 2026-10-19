// Service Content.
// - Backend branché (VITE_API_URL défini) : /api/v1/content/posts (Laravel).
// - Sinon : données de démonstration en mémoire, même contrat.
// Publier / Réessayer passent par le moteur de publication du backend (file « publishing ») :
// sans intégration officielle active, chaque envoi échoue explicitement (NOT_CONFIGURED).
import type {
  PlatformKey,
  Post,
  PostDraftInput,
  PostFilters,
  PostFormat,
  PostPage,
  PostSource,
  PostStatus,
  PostVariant,
  PublicationAttempt,
  AttemptStatus,
  AttemptTrigger,
  VariantOptions,
  VariantStatus,
} from "@/types"
import { ApiError, http, isApiEnabled } from "@/lib/http"
import { browserTimezone } from "@/lib/time"
import { uid } from "@/mocks/helpers"
import { delay, notFound, store } from "./mockStore"

export type SaveMode = "draft" | "schedule" | "publish"

const DEFAULT_PER_PAGE = 25
const ALL_STATUSES: PostStatus[] = ["draft", "scheduled", "processing", "partially_published", "published", "failed", "cancelled"]

// --- Conversion API ↔ frontend ------------------------------------------------------------------
// Format de backend/app/Http/Resources/PostResource.php et PostVariantResource.php.

interface ApiVariant {
  id: string
  social_account_id: string
  platform: PlatformKey | null
  format: PostFormat
  body: string | null
  options: VariantOptions | []
  status: VariantStatus
  scheduled_at: string | null
  published_at: string | null
  external_url: string | null
  attempts_count?: number
  error_code?: string | null
  error_message: string | null
}

interface ApiAttempt {
  id: string
  post_variant_id: string
  social_account_id: string | null
  attempt_number: number
  trigger: AttemptTrigger
  status: AttemptStatus
  retryable: boolean
  error_code: string | null
  error_message: string | null
  external_post_id: string | null
  started_at: string | null
  finished_at: string | null
  warnings?: { code: string; message: string }[]
}

interface ApiPost {
  id: string
  title: string | null
  body: string | null
  status: PostStatus
  scheduled_at: string | null
  published_at: string | null
  timezone: string
  source: PostSource
  lock_version: number
  media_ids?: string[]
  variants?: ApiVariant[]
  created_at: string
  updated_at: string
}

interface ApiPageMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
  status_counts?: Partial<Record<PostStatus, number>>
}

/** Un objet JSON vide est sérialisé « [] » par PHP : on le normalise en {}. */
const asOptions = (o: VariantOptions | [] | null | undefined): VariantOptions => (o && !Array.isArray(o) ? o : {})

export function toPost(p: ApiPost): Post {
  return {
    id: p.id,
    title: p.title,
    body: p.body ?? "",
    status: p.status,
    scheduledAt: p.scheduled_at,
    publishedAt: p.published_at,
    timezone: p.timezone,
    source: p.source,
    mediaIds: p.media_ids ?? [],
    variants: (p.variants ?? [])
      .filter((v) => v.platform !== null)
      .map((v) => ({
        id: v.id,
        postId: p.id,
        platform: v.platform as PlatformKey,
        socialAccountId: v.social_account_id,
        format: v.format,
        body: v.body,
        options: asOptions(v.options),
        status: v.status,
        scheduledAt: v.scheduled_at,
        publishedAt: v.published_at,
        externalUrl: v.external_url,
        errorCode: v.error_code ?? null,
        errorMessage: v.error_message,
        attemptsCount: v.attempts_count ?? 0,
      })),
    metrics: null, // statistiques : phase Analytics
    lockVersion: p.lock_version,
    createdAt: p.created_at,
    updatedAt: p.updated_at,
  }
}

function toPayload(input: PostDraftInput, mode: Exclude<SaveMode, "publish">, lockVersion?: number) {
  return {
    mode,
    title: input.title,
    body: input.body,
    timezone: input.timezone,
    scheduled_at: mode === "schedule" ? input.scheduledAt : null,
    media_ids: input.mediaIds,
    targets: input.targets.map((t) => ({
      social_account_id: t.socialAccountId,
      format: t.format,
      body: t.body,
      options: t.options,
    })),
    ...(lockVersion !== undefined ? { lock_version: lockVersion } : {}),
  }
}

function toQuery(f: PostFilters): string {
  const q = new URLSearchParams()
  const statuses = f.status === undefined || f.status === "all" ? [] : Array.isArray(f.status) ? f.status : [f.status]
  for (const s of statuses) q.append("status[]", s)
  if (f.platform && f.platform !== "all") q.set("platform", f.platform)
  if (f.search?.trim()) q.set("search", f.search.trim())
  if (f.from) q.set("from", f.from)
  if (f.to) q.set("to", f.to)
  q.set("page", String(f.page ?? 1))
  q.set("per_page", String(f.perPage ?? DEFAULT_PER_PAGE))
  return q.toString()
}

const emptyCounts = (): Record<PostStatus, number> =>
  Object.fromEntries(ALL_STATUSES.map((s) => [s, 0])) as Record<PostStatus, number>

// --- Lecture ------------------------------------------------------------------------------------

export async function listPosts(filters: PostFilters = {}): Promise<PostPage> {
  if (isApiEnabled) {
    const res = await http.get<ApiPost[]>(`/content/posts?${toQuery(filters)}`)
    const meta = res!.meta as unknown as ApiPageMeta
    return {
      items: res!.data.map(toPost),
      page: meta.current_page,
      perPage: meta.per_page,
      total: meta.total,
      lastPage: meta.last_page,
      statusCounts: { ...emptyCounts(), ...meta.status_counts },
    }
  }
  return mockList(filters)
}

/** Toutes les publications d'une période (calendrier) : parcourt les pages de 100. */
export async function listPostsInRange(filters: Omit<PostFilters, "page" | "perPage"> & { from: string; to: string }): Promise<Post[]> {
  const items: Post[] = []
  const MAX_PAGES = 10 // garde-fou : 1 000 publications par période affichée
  for (let page = 1; page <= MAX_PAGES; page++) {
    const res = await listPosts({ ...filters, page, perPage: 100 })
    items.push(...res.items)
    if (page >= res.lastPage) break
  }
  return items
}

export async function getPost(id: string): Promise<Post> {
  if (isApiEnabled) {
    const res = await http.get<ApiPost>(`/content/posts/${encodeURIComponent(id)}`)
    return toPost(res!.data)
  }
  await delay(200)
  const post = store.posts.find((p) => p.id === id)
  return post ? structuredClone(post) : notFound("Publication")
}

// --- Écriture -----------------------------------------------------------------------------------

export async function savePost(input: PostDraftInput, mode: SaveMode, id?: string, lockVersion?: number): Promise<Post> {
  if (isApiEnabled) {
    // « Publier maintenant » = enregistrement en brouillon, puis mise en file par le moteur de
    // publication. Le résultat réel (publié, échec) arrive ensuite, jamais simulé ici.
    const body = toPayload(input, mode === "publish" ? "draft" : mode, id ? lockVersion : undefined)
    const res = id
      ? await http.put<ApiPost>(`/content/posts/${encodeURIComponent(id)}`, body)
      : await http.post<ApiPost>("/content/posts", body)
    const saved = toPost(res!.data)
    return mode === "publish" ? publishNow(saved.id, saved.lockVersion) : saved
  }
  return mockSave(input, mode, id, lockVersion)
}

export async function deletePost(id: string): Promise<void> {
  if (isApiEnabled) {
    await http.delete(`/content/posts/${encodeURIComponent(id)}`)
    return
  }
  await delay(300)
  const index = store.posts.findIndex((p) => p.id === id)
  if (index === -1) notFound("Publication")
  store.posts.splice(index, 1)
}

export async function duplicatePost(id: string): Promise<Post> {
  if (isApiEnabled) {
    const res = await http.post<ApiPost>(`/content/posts/${encodeURIComponent(id)}/duplicate`)
    return toPost(res!.data)
  }
  await delay(300)
  const source = store.posts.find((p) => p.id === id) ?? notFound("Publication")
  const copyId = uid("post")
  const now = new Date().toISOString()
  const copy: Post = {
    ...structuredClone(source),
    id: copyId,
    title: source.title ? `${source.title} (copie)` : "Copie",
    status: "draft",
    scheduledAt: null,
    publishedAt: null,
    metrics: null,
    lockVersion: 0,
    createdAt: now,
    updatedAt: now,
    variants: source.variants.map((v) => ({
      ...v,
      id: uid("var"),
      postId: copyId,
      status: "draft",
      scheduledAt: null,
      publishedAt: null,
      externalUrl: null,
      errorCode: null,
      errorMessage: null,
      attemptsCount: 0,
    })),
  }
  store.posts.unshift(copy)
  return structuredClone(copy)
}

/** Met une publication en file d'envoi (moteur de publication du backend). */
export async function publishNow(id: string, lockVersion?: number): Promise<Post> {
  if (isApiEnabled) {
    const res = await http.post<ApiPost>(`/content/posts/${encodeURIComponent(id)}/publish`, lockVersion !== undefined ? { lock_version: lockVersion } : {})
    return toPost(res!.data)
  }
  await delay(300)
  const post = mockLocked(id, lockVersion)
  for (const v of post.variants) {
    v.status = "queued"
    v.errorCode = null
    v.errorMessage = null
  }
  post.status = "processing"
  touch(post)
  simulatePublication(post.id)
  return structuredClone(post)
}

/** Annule une programmation ou un envoi en file : statut « cancelled », date conservée (docs/database.md §3.3). */
export async function cancelSchedule(id: string, lockVersion?: number): Promise<Post> {
  if (isApiEnabled) {
    const res = await http.post<ApiPost>(`/content/posts/${encodeURIComponent(id)}/cancel`, lockVersion !== undefined ? { lock_version: lockVersion } : {})
    return toPost(res!.data)
  }
  await delay(300)
  const post = mockLocked(id, lockVersion)
  if (post.status !== "scheduled" && post.status !== "processing") throw new ApiError(409, "Seule une publication programmée ou en file d'attente peut être annulée.")
  if (post.variants.some((v) => v.status === "publishing")) throw new ApiError(409, "Une publication est en cours d'envoi : elle ne peut plus être annulée. Attendez son résultat.")
  for (const v of post.variants) if (v.status === "scheduled" || v.status === "draft" || v.status === "queued") v.status = "cancelled"
  post.status = aggregateStatus(post.variants, "cancelled")
  touch(post)
  return structuredClone(post)
}

/** Programme (brouillon, annulée) ou reprogramme (programmée) une publication. */
export async function reschedule(id: string, scheduledAt: string, lockVersion?: number): Promise<Post> {
  if (isApiEnabled) {
    const res = await http.post<ApiPost>(`/content/posts/${encodeURIComponent(id)}/reschedule`, {
      scheduled_at: scheduledAt,
      // Même règle qu'à la création : la date est choisie dans le fuseau du navigateur.
      timezone: browserTimezone(),
      ...(lockVersion !== undefined ? { lock_version: lockVersion } : {}),
    })
    return toPost(res!.data)
  }
  await delay(300)
  const post = mockLocked(id, lockVersion)
  if (!["draft", "scheduled", "cancelled"].includes(post.status)) throw new ApiError(409, "Cette publication ne peut pas être reprogrammée.")
  if (post.variants.length === 0) throw new ApiError(422, "Choisissez au moins un réseau social avant de programmer.", { targets: ["Choisissez au moins un réseau social avant de programmer."] })
  post.scheduledAt = scheduledAt
  post.timezone = browserTimezone()
  post.status = "scheduled"
  for (const v of post.variants) {
    v.status = "scheduled"
    v.scheduledAt = scheduledAt
    v.errorMessage = null
  }
  touch(post)
  return structuredClone(post)
}

/** Message du backend quand la dernière tentative a un résultat inconnu (confirmation requise). */
export const UNKNOWN_OUTCOME_HINT = "résultat de la dernière tentative est inconnu"

/** « Réessayer » : nouvelle tentative pour les variantes en échec (l'historique est conservé). */
export async function retryFailed(id: string, lockVersion?: number, confirmUnknown = false): Promise<Post> {
  if (isApiEnabled) {
    const res = await http.post<ApiPost>(`/content/posts/${encodeURIComponent(id)}/retry`, {
      ...(lockVersion !== undefined ? { lock_version: lockVersion } : {}),
      ...(confirmUnknown ? { confirm_unknown: true } : {}),
    })
    return toPost(res!.data)
  }
  await delay(300)
  const post = mockLocked(id, lockVersion)
  if (post.status !== "failed" && post.status !== "partially_published") throw new ApiError(409, "Seule une publication en échec peut être relancée.")
  for (const v of post.variants) {
    if (v.status === "failed") {
      v.status = "queued"
      v.errorCode = null
      v.errorMessage = null
    }
  }
  post.status = "processing"
  touch(post)
  simulatePublication(post.id)
  return structuredClone(post)
}

/** Historique des tentatives d'envoi d'une publication, de la plus récente à la plus ancienne. */
export async function listAttempts(id: string): Promise<PublicationAttempt[]> {
  if (isApiEnabled) {
    const res = await http.get<ApiAttempt[]>(`/content/posts/${encodeURIComponent(id)}/attempts`)
    return res!.data.map((a) => ({
      id: a.id,
      variantId: a.post_variant_id,
      socialAccountId: a.social_account_id,
      attemptNumber: a.attempt_number,
      trigger: a.trigger,
      status: a.status,
      retryable: a.retryable,
      errorCode: a.error_code,
      errorMessage: a.error_message,
      externalPostId: a.external_post_id,
      startedAt: a.started_at,
      finishedAt: a.finished_at,
      warnings: a.warnings ?? [],
    }))
  }
  await delay(150)
  const post = store.posts.find((p) => p.id === id) ?? notFound("Publication")
  const variantIds = new Set(post.variants.map((v) => v.id))
  return structuredClone(mockAttempts.filter((a) => variantIds.has(a.variantId)))
}

// --- Démonstration (sans backend) ---------------------------------------------------------------

/** Statut agrégé du post à partir de ses variantes (docs/database.md §3.3). */
export function aggregateStatus(variants: PostVariant[], fallback: PostStatus): PostStatus {
  if (variants.length === 0) return fallback
  const s = variants.map((v) => v.status)
  const all = (x: VariantStatus) => s.every((v) => v === x)
  if (all("draft")) return "draft"
  if (all("cancelled")) return "cancelled"
  if (s.some((v) => v === "publishing" || v === "queued")) return "processing"
  if (all("published")) return "published"
  if (all("failed")) return "failed"
  if (s.includes("published") && s.includes("failed")) return "partially_published"
  if (s.includes("scheduled")) return "scheduled"
  return fallback
}

async function mockList(filters: PostFilters): Promise<PostPage> {
  await delay()
  const q = filters.search?.trim().toLowerCase()
  const statuses = filters.status === undefined || filters.status === "all" ? null : Array.isArray(filters.status) ? filters.status : [filters.status]
  const from = filters.from ? new Date(filters.from).getTime() : null
  const to = filters.to ? new Date(filters.to).getTime() : null
  const scoped = store.posts
    .filter((p) => (filters.platform && filters.platform !== "all" ? p.variants.some((v) => v.platform === filters.platform) : true))
    .filter((p) => (q ? `${p.title ?? ""} ${p.body}`.toLowerCase().includes(q) : true))
    .filter((p) => {
      if (from === null && to === null) return true
      if (!p.scheduledAt) return false
      const t = new Date(p.scheduledAt).getTime()
      return (from === null || t >= from) && (to === null || t <= to)
    })
  const counts = emptyCounts()
  for (const p of scoped) counts[p.status]++
  const matching = scoped
    .filter((p) => (statuses ? statuses.includes(p.status) : true))
    .sort((a, b) => new Date(b.scheduledAt ?? b.updatedAt).getTime() - new Date(a.scheduledAt ?? a.updatedAt).getTime())
  const perPage = filters.perPage ?? DEFAULT_PER_PAGE
  const page = filters.page ?? 1
  return {
    items: matching.slice((page - 1) * perPage, page * perPage).map((p) => structuredClone(p)),
    page,
    perPage,
    total: matching.length,
    lastPage: Math.max(1, Math.ceil(matching.length / perPage)),
    statusCounts: counts,
  }
}

function mockLocked(id: string, lockVersion?: number): Post {
  const post = store.posts.find((p) => p.id === id) ?? notFound("Publication")
  if (lockVersion !== undefined && lockVersion !== post.lockVersion) {
    throw new ApiError(409, "Cette publication a été modifiée entre-temps. Rechargez-la avant d'enregistrer.")
  }
  return post
}

function touch(post: Post) {
  post.lockVersion++
  post.updatedAt = new Date().toISOString()
}

function buildVariants(postId: string, input: PostDraftInput, status: VariantStatus): PostVariant[] {
  return input.targets.map((t) => ({
    id: uid("var"),
    postId,
    platform: t.platform,
    socialAccountId: t.socialAccountId,
    format: t.format,
    body: t.body,
    options: t.options,
    status,
    scheduledAt: input.scheduledAt,
    publishedAt: null,
    externalUrl: null,
    errorCode: null,
    errorMessage: null,
    attemptsCount: 0,
  }))
}

const MODE_STATUS: Record<SaveMode, { post: PostStatus; variant: VariantStatus }> = {
  draft: { post: "draft", variant: "draft" },
  schedule: { post: "scheduled", variant: "scheduled" },
  publish: { post: "processing", variant: "queued" },
}

async function mockSave(input: PostDraftInput, mode: SaveMode, id?: string, lockVersion?: number): Promise<Post> {
  await delay(500)
  const now = new Date().toISOString()
  const status = MODE_STATUS[mode]
  const existing = id ? mockLocked(id, lockVersion) : undefined
  const postId = existing?.id ?? uid("post")
  const scheduledAt = mode === "publish" ? now : mode === "schedule" ? input.scheduledAt : null
  const post: Post = {
    id: postId,
    title: input.title,
    body: input.body,
    status: status.post,
    scheduledAt,
    publishedAt: null,
    timezone: input.timezone,
    source: existing?.source ?? "manual",
    mediaIds: input.mediaIds,
    variants: buildVariants(postId, { ...input, scheduledAt }, status.variant),
    metrics: null,
    lockVersion: existing ? existing.lockVersion + 1 : 0,
    createdAt: existing?.createdAt ?? now,
    updatedAt: now,
  }
  if (existing) Object.assign(existing, post)
  else store.posts.unshift(post)

  if (mode === "publish") simulatePublication(postId)
  return structuredClone(post)
}

/** Message de l'adaptateur par défaut du backend (NotConfiguredPublisher) : rien n'est publié. */
const NOT_CONFIGURED_MESSAGE = "La publication n'a pas pu être envoyée : aucune intégration active n'est disponible pour ce réseau. Rien n'a été publié."

/** Démonstration : historique des tentatives, en mémoire. */
const mockAttempts: PublicationAttempt[] = []

/**
 * Démonstration : reproduit le moteur du backend sans intégration active (jamais en mode API).
 * Chaque variante passe « en file » → « publication… » → « échec » (NOT_CONFIGURED) avec une
 * tentative enregistrée. Aucune publication réussie n'est simulée.
 */
function simulatePublication(postId: string) {
  setTimeout(() => {
    const post = store.posts.find((p) => p.id === postId)
    if (!post) return
    for (const v of post.variants) if (v.status === "queued") v.status = "publishing"
    post.status = aggregateStatus(post.variants, post.status)
  }, 800)
  setTimeout(() => {
    const post = store.posts.find((p) => p.id === postId)
    if (!post) return
    const now = new Date().toISOString()
    for (const v of post.variants) {
      if (v.status !== "publishing") continue
      const account = store.socialAccounts.find((a) => a.id === v.socialAccountId)
      const disconnected = !account || account.status !== "connected"
      v.status = "failed"
      v.errorCode = disconnected ? "ACCOUNT_DISCONNECTED" : "NOT_CONFIGURED"
      v.errorMessage = disconnected
        ? `${account?.statusMessage ?? "Le compte n'est pas connecté."} Reconnectez-le puis réessayez. Rien n'a été publié.`
        : NOT_CONFIGURED_MESSAGE
      v.attemptsCount++
      mockAttempts.unshift({
        id: uid("att"),
        variantId: v.id,
        socialAccountId: v.socialAccountId,
        attemptNumber: v.attemptsCount,
        trigger: v.attemptsCount > 1 ? "retry" : "manual",
        status: "failed",
        retryable: false,
        errorCode: v.errorCode,
        errorMessage: v.errorMessage,
        externalPostId: null,
        startedAt: now,
        finishedAt: now,
        warnings: [],
      })
    }
    post.status = aggregateStatus(post.variants, post.status)
  }, 2000)
}
