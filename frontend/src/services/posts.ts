// Service Content — futur : /api/v1/posts. Contrat identique, implémentation mockée.
import type { Post, PostDraftInput, PostFilters, PostStatus, PostVariant, VariantStatus } from "@/types"
import { uid } from "@/mocks/helpers"
import { delay, notFound, store } from "./mockStore"

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

export async function listPosts(filters: PostFilters = {}): Promise<Post[]> {
  await delay()
  const q = filters.search?.trim().toLowerCase()
  return store.posts
    .filter((p) => (filters.status && filters.status !== "all" ? p.status === filters.status : true))
    .filter((p) => (filters.platform && filters.platform !== "all" ? p.variants.some((v) => v.platform === filters.platform) : true))
    .filter((p) => (q ? `${p.title ?? ""} ${p.body}`.toLowerCase().includes(q) : true))
    .sort((a, b) => {
      const da = a.scheduledAt ?? a.updatedAt
      const db = b.scheduledAt ?? b.updatedAt
      return new Date(db).getTime() - new Date(da).getTime()
    })
    .map((p) => structuredClone(p))
}

export async function getPost(id: string): Promise<Post> {
  await delay(200)
  const post = store.posts.find((p) => p.id === id)
  return post ? structuredClone(post) : notFound("Publication")
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
    errorMessage: null,
  }))
}

type SaveMode = "draft" | "schedule" | "publish"

const MODE_STATUS: Record<SaveMode, { post: PostStatus; variant: VariantStatus }> = {
  draft: { post: "draft", variant: "draft" },
  schedule: { post: "scheduled", variant: "scheduled" },
  publish: { post: "processing", variant: "publishing" },
}

export async function savePost(input: PostDraftInput, mode: SaveMode, id?: string): Promise<Post> {
  await delay(500)
  const now = new Date().toISOString()
  const status = MODE_STATUS[mode]
  const existing = id ? store.posts.find((p) => p.id === id) : undefined
  const postId = existing?.id ?? uid("post")
  const post: Post = {
    id: postId,
    title: input.title,
    body: input.body,
    status: status.post,
    scheduledAt: mode === "publish" ? now : input.scheduledAt,
    publishedAt: null,
    timezone: input.timezone,
    source: existing?.source ?? "manual",
    mediaIds: input.mediaIds,
    variants: buildVariants(postId, { ...input, scheduledAt: mode === "publish" ? now : input.scheduledAt }, status.variant),
    metrics: null,
    createdAt: existing?.createdAt ?? now,
    updatedAt: now,
  }
  if (existing) Object.assign(existing, post)
  else store.posts.unshift(post)

  if (mode === "publish") simulatePublication(postId)
  return structuredClone(post)
}

/**
 * Simule le traitement asynchrone du worker « publishing » : chaque variante passe à
 * « published », sauf les comptes en erreur (expiré, permission manquante) qui échouent.
 */
function simulatePublication(postId: string) {
  setTimeout(() => {
    const post = store.posts.find((p) => p.id === postId)
    if (!post) return
    const now = new Date().toISOString()
    for (const v of post.variants) {
      const account = store.socialAccounts.find((a) => a.id === v.socialAccountId)
      if (account && account.status !== "connected") {
        v.status = "failed"
        v.errorMessage = `${account.statusMessage ?? "Le compte n'est pas connecté."} Reconnectez-le puis réessayez.`
      } else {
        v.status = "published"
        v.publishedAt = now
        v.externalUrl = "#"
      }
    }
    post.status = aggregateStatus(post.variants, post.status)
    post.publishedAt = post.variants.some((v) => v.status === "published") ? now : null
  }, 2500)
}

export async function deletePost(id: string): Promise<void> {
  await delay(300)
  const index = store.posts.findIndex((p) => p.id === id)
  if (index === -1) notFound("Publication")
  store.posts.splice(index, 1)
}

export async function duplicatePost(id: string): Promise<Post> {
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
      errorMessage: null,
    })),
  }
  store.posts.unshift(copy)
  return structuredClone(copy)
}

export async function cancelSchedule(id: string): Promise<Post> {
  await delay(300)
  const post = store.posts.find((p) => p.id === id) ?? notFound("Publication")
  for (const v of post.variants) if (v.status === "scheduled" || v.status === "queued") v.status = "draft"
  post.status = "draft"
  post.scheduledAt = null
  post.updatedAt = new Date().toISOString()
  return structuredClone(post)
}

export async function reschedule(id: string, scheduledAt: string): Promise<Post> {
  await delay(300)
  const post = store.posts.find((p) => p.id === id) ?? notFound("Publication")
  post.scheduledAt = scheduledAt
  post.status = "scheduled"
  for (const v of post.variants) {
    if (v.status !== "published") {
      v.status = "scheduled"
      v.scheduledAt = scheduledAt
      v.errorMessage = null
    }
  }
  post.updatedAt = new Date().toISOString()
  return structuredClone(post)
}

export async function retryFailed(id: string): Promise<Post> {
  await delay(300)
  const post = store.posts.find((p) => p.id === id) ?? notFound("Publication")
  for (const v of post.variants) {
    if (v.status === "failed") {
      v.status = "publishing"
      v.errorMessage = null
    }
  }
  post.status = "processing"
  simulatePublication(post.id)
  return structuredClone(post)
}
