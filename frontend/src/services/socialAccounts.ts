// Service Social Accounts.
// - Backend branché : lecture seule de /api/v1/social/accounts (comptes réels ou de démonstration).
//   Connexion / déconnexion / synchronisation : phase Social Connectors (OAuth officiel) — refusées
//   explicitement ici, sans aucune simulation.
// - Sinon : démonstration en mémoire (connexion simulée). Aucun token n'existe côté frontend.
import type { PlatformKey, SocialAccount, SocialAccountStatus, SocialAccountType } from "@/types"
import { FeatureUnavailableError, SOCIAL_ACTION_UNAVAILABLE } from "@/lib/features"
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
  assertDemoMode("disconnect")
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
  assertDemoMode("sync")
  await delay(1200)
  const account = find(id)
  account.lastSyncedAt = new Date().toISOString()
  return structuredClone(account)
}
