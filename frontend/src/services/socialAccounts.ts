// Service Social Accounts — futur : /api/v1/social-accounts + flux OAuth serveur.
// Aucun OAuth réel ici : la connexion est simulée. Aucun token n'existe côté frontend.
import type { SocialAccount } from "@/types"
import { PLATFORMS } from "@/lib/platforms"
import { delay, notFound, store } from "./mockStore"

export async function listSocialAccounts(): Promise<SocialAccount[]> {
  await delay()
  return structuredClone(store.socialAccounts)
}

function find(id: string): SocialAccount {
  return store.socialAccounts.find((a) => a.id === id) ?? notFound("Compte")
}

/** Simule : redirection OAuth → consentement → callback → comptes récupérés. */
export async function connectAccount(id: string): Promise<SocialAccount> {
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
  await delay(1200)
  const account = find(id)
  account.lastSyncedAt = new Date().toISOString()
  return structuredClone(account)
}
