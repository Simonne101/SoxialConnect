// Service Social Accounts.
// - Backend branché : /api/v1/social/accounts (comptes réels ou de démonstration).
//   Connexion OAuth officielle : YouTube (redirection vers Google, callback côté Laravel).
//   Les autres réseaux restent refusés explicitement, sans aucune simulation.
//   Déconnexion et synchronisation : côté serveur (la synchronisation n'existe que pour YouTube).
// - Sinon : démonstration en mémoire (connexion simulée). Aucun token n'existe côté frontend.
import type { PlatformKey, SocialAccount, SocialAccountStatus, SocialAccountType } from "@/types"
import { FeatureUnavailableError, OAUTH_PLATFORMS, SOCIAL_ACTION_UNAVAILABLE } from "@/lib/features"
import { http, isApiEnabled } from "@/lib/http"
import { PLATFORMS } from "@/lib/platforms"
import { delay, notFound, store } from "./mockStore"

/** Format de backend/app/Http/Resources/SocialAccountResource.php. */
interface ApiSocialAccount {
  id: string
  platform: PlatformKey
  status: "connected" | "expired" | "revoked" | "error"
  account_type: SocialAccountType | null
  display_name: string | null
  username: string | null
  status_reason: string | null
  is_demo?: boolean
  connected_at: string | null
  last_synced_at: string | null
}

const STATUS: Record<ApiSocialAccount["status"], SocialAccountStatus> = {
  connected: "connected",
  expired: "expired",
  revoked: "disconnected",
  error: "error",
}

export function toSocialAccount(a: ApiSocialAccount): SocialAccount {
  return {
    id: a.id,
    platform: a.platform,
    status: STATUS[a.status] ?? "error",
    accountType: a.account_type,
    displayName: a.display_name,
    username: a.username,
    entities: [],
    permissions: [],
    followers: null,
    connectedAt: a.connected_at,
    lastSyncedAt: a.last_synced_at,
    statusMessage: a.status_reason,
    isDemo: a.is_demo ?? false,
  }
}

export async function listSocialAccounts(): Promise<SocialAccount[]> {
  if (isApiEnabled) {
    const res = await http.get<ApiSocialAccount[]>("/social/accounts")
    return res!.data.map(toSocialAccount)
  }
  await delay()
  return structuredClone(store.socialAccounts)
}

function assertDemoMode(action: keyof typeof SOCIAL_ACTION_UNAVAILABLE) {
  if (isApiEnabled) throw new FeatureUnavailableError(SOCIAL_ACTION_UNAVAILABLE[action])
}

function find(id: string): SocialAccount {
  return store.socialAccounts.find((a) => a.id === id) ?? notFound("Compte")
}

/** Réseaux connectables via OAuth officiel (backend branché). */
export const supportsOAuth = (platform: PlatformKey) => isApiEnabled && OAUTH_PLATFORMS.includes(platform)

/**
 * Démarre la connexion OAuth officielle : le serveur crée le state (usage unique) et renvoie
 * l'URL de consentement de la plateforme ; le navigateur y est redirigé. Aucun token ne
 * transite par le frontend : le retour arrive sur le callback Laravel, puis /social-accounts.
 */
export async function startOAuth(platform: PlatformKey): Promise<void> {
  if (!supportsOAuth(platform)) throw new FeatureUnavailableError(SOCIAL_ACTION_UNAVAILABLE.connect)
  const res = await http.post<{ authorization_url: string }>(`/social/${platform}/connect`)
  const url = res!.data.authorization_url
  // Garde-fou : uniquement la page de consentement officielle attendue.
  if (!url.startsWith("https://accounts.google.com/")) throw new FeatureUnavailableError("Adresse de connexion inattendue.")
  window.location.assign(url)
  // La page quitte l'application : l'indicateur « Redirection… » reste affiché jusque-là.
  return new Promise<void>(() => {})
}

/** Emplacement « à connecter » pour un réseau sans compte (backend branché). */
export function placeholderAccount(platform: PlatformKey): SocialAccount {
  return {
    id: `new:${platform}`,
    platform,
    status: PLATFORMS[platform].availability === "coming_soon" ? "coming_soon" : "disconnected",
    accountType: null,
    displayName: null,
    username: null,
    entities: [],
    permissions: [],
    followers: null,
    connectedAt: null,
    lastSyncedAt: null,
    statusMessage: null,
  }
}

/** Simule : redirection OAuth → consentement → callback → comptes récupérés. */
export async function connectAccount(id: string): Promise<SocialAccount> {
  assertDemoMode("connect")
  await delay(1600)
  const account = find(id)
  const platform = PLATFORMS[account.platform]
  const now = new Date().toISOString()
  account.status = "connected"
  account.statusMessage = null
  account.connectedAt = account.connectedAt ?? now
  account.lastSyncedAt = now
  account.displayName = account.displayName ?? "Marie Dubois"
  account.accountType = account.accountType ?? "profile"
  if (account.entities.length === 0) account.entities = [{ id: `${id}-1`, name: "Marie Dubois", kind: "Compte" }]
  account.permissions = platform.permissions.map((p) => ({ scope: p.scope, label: p.label, status: "granted", required: true }))
  account.followers = account.followers ?? 1240
  return structuredClone(account)
}

export async function disconnectAccount(id: string): Promise<SocialAccount> {
  if (isApiEnabled) {
    const res = await http.delete<ApiSocialAccount>(`/social/accounts/${encodeURIComponent(id)}`)
    return toSocialAccount(res!.data)
  }
  await delay(700)
  const account = find(id)
  account.status = "disconnected"
  account.entities = []
  account.permissions = account.permissions.map((p) => ({ ...p, status: "missing" }))
  account.statusMessage = null
  account.lastSyncedAt = null
  return structuredClone(account)
}

export async function syncAccount(id: string): Promise<SocialAccount> {
  if (isApiEnabled) {
    const res = await http.post<ApiSocialAccount>(`/social/accounts/${encodeURIComponent(id)}/sync`)
    return toSocialAccount(res!.data)
  }
  await delay(1200)
  const account = find(id)
  account.lastSyncedAt = new Date().toISOString()
  return structuredClone(account)
}
