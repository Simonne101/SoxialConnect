import type { LiveAnalyticsPoint, LiveMetricKey, LiveSyncState, PlatformKey } from "@/types"

/**
 * Logique d'affichage des statistiques réelles (P4 — ADR-068), sans dépendance : état de chaque réseau à
 * partir de l'état renvoyé par le serveur (jamais du nom du réseau ni de la seule connexion OAuth), dernier
 * relevé par compte et par publication, libellés. Aucune valeur inventée : null → « Non disponible ».
 */

export const NOT_AVAILABLE = "Non disponible"

export type LivePeriod = "7d" | "30d" | "90d"

/** Période des collectes (dates des relevés), précisée par le libellé accessible du sélecteur. */
export const LIVE_PERIOD_LABEL: Record<LivePeriod, string> = {
  "7d": "7 derniers jours",
  "30d": "30 derniers jours",
  "90d": "90 derniers jours",
}

const PERIOD_DAYS: Record<LivePeriod, number> = { "7d": 7, "30d": 30, "90d": 90 }

/** Bornes envoyées à GET /analytics (dates UTC AAAA-MM-JJ, bornes incluses). */
export function periodRange(period: LivePeriod, today: Date = new Date()): { from: string; to: string } {
  const to = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate()))
  const from = new Date(to.getTime() - (PERIOD_DAYS[period] - 1) * 86_400_000)
  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }
}

export const METRIC_LABELS: Record<LiveMetricKey, string> = {
  views: "Vues",
  likes: "J'aime",
  comments: "Commentaires",
  impressions: "Impressions",
  reach: "Portée",
  shares: "Partages",
  saves: "Enregistrements",
  clicks: "Clics",
  engagements: "Engagements",
  followers: "Abonnés",
}

/** Ordre d'affichage : YouTube (vues, J'aime, commentaires) d'abord. */
export const METRIC_ORDER: LiveMetricKey[] = ["views", "likes", "comments", "impressions", "reach", "shares", "saves", "clicks", "engagements", "followers"]

const numberFormat = new Intl.NumberFormat("fr-FR")

/** Valeur affichée : null reste « Non disponible », 0 reste 0. */
export function formatMetric(value: number | null | undefined): string {
  return value === null || value === undefined ? NOT_AVAILABLE : numberFormat.format(value)
}

/** Métriques à afficher en colonnes : celles qu'au moins un point fournit (les autres n'existent pas pour ces réseaux). */
export function metricColumns(points: LiveAnalyticsPoint[], exclude: LiveMetricKey[] = []): LiveMetricKey[] {
  return METRIC_ORDER.filter((key) => !exclude.includes(key) && points.some((p) => p.metrics[key] !== null))
}

/**
 * Dernier relevé par compte (points de compte) ou par publication : le plus récent de la période, de
 * préférence cumulé (« lifetime »). Aucune agrégation entre relevés.
 */
export function latestPoints(points: LiveAnalyticsPoint[], scope: "account" | "post"): LiveAnalyticsPoint[] {
  const latest = new Map<string, LiveAnalyticsPoint>()
  for (const point of points) {
    const key = scope === "post" ? point.postVariantId : point.postVariantId === null ? point.socialAccountId : null
    if (key === null) continue
    const current = latest.get(key)
    const rank = (p: LiveAnalyticsPoint) => `${p.date}|${p.granularity === "lifetime" ? 1 : 0}`
    if (!current || rank(point) > rank(current)) latest.set(key, point)
  }
  return [...latest.values()]
}

export type NetworkStatusKind = "available" | "pending" | "permission" | "reconnect" | "error" | "unavailable"

export interface NetworkStatus {
  kind: NetworkStatusKind
  label: string
  /** Explication affichée : message du serveur quand il existe, sinon texte générique. */
  description: string
}

const RECONNECT_CODES = new Set([
  "ACCOUNT_NOT_CONNECTED", "ACCOUNT_DISCONNECTED", "TOKEN_INVALID", "TOKEN_REVOKED", "TOKEN_EXPIRED", "TOKEN_MISSING",
  "REFRESH_TOKEN_MISSING", "CHANNEL_MISMATCH", "ACCOUNT_MISMATCH",
])

const PERMISSION_CODES = new Set(["PERMISSION_REQUIRED", "PERMISSION_DENIED"])

/** Réseaux sans collecte (NO_ADAPTER) : raison connue, sinon générique. */
const NO_ADAPTER_REASON: Partial<Record<PlatformKey, string>> = {
  x: "Collecte désactivée : chaque lecture de l'API X est payante. Elle sera activée après validation des crédits du compte développeur X.",
  telegram: "L'API des bots Telegram ne fournit pas de statistiques sur les messages publiés.",
}

/**
 * État affiché pour un compte, déduit UNIQUEMENT de l'état de collecte renvoyé par le serveur.
 * hasData : des points existent pour ce compte sur la période affichée.
 */
export function networkStatus(sync: LiveSyncState, hasData: boolean): NetworkStatus {
  const code = sync.lastErrorCode ?? ""
  const message = sync.lastErrorMessage ?? null

  if (sync.status === "succeeded") {
    return {
      kind: "available",
      label: "Données disponibles",
      description: hasData ? "Statistiques réelles synchronisées." : "Synchronisé, mais aucun relevé sur la période choisie.",
    }
  }
  if (RECONNECT_CODES.has(code)) {
    return { kind: "reconnect", label: "Reconnexion requise", description: message ?? "Le compte n'est plus autorisé : reconnectez-le pour reprendre la collecte." }
  }
  if (PERMISSION_CODES.has(code)) {
    return { kind: "permission", label: "Autorisation requise", description: message ?? "Le réseau refuse la collecte faute d'autorisation." }
  }
  if (sync.status === "failed") {
    const kept = sync.lastSuccessAt ? " Les dernières valeurs collectées restent affichées." : ""
    return { kind: "error", label: "Erreur de synchronisation", description: `${message ?? "La collecte a échoué."}${kept}` }
  }
  if (sync.status === "unsupported") {
    if (code === "DEMO_ACCOUNT") return { kind: "unavailable", label: "Compte de démonstration", description: "Aucune statistique réelle pour un compte de démonstration." }
    const reason = code === "NO_ADAPTER" && sync.platform ? NO_ADAPTER_REASON[sync.platform] : undefined
    return { kind: "unavailable", label: sync.platform === "x" && code === "NO_ADAPTER" ? "Collecte désactivée" : "Collecte non disponible", description: reason ?? message ?? "La collecte n'est pas disponible pour ce réseau." }
  }
  // pending, running : rien n'a encore été enregistré.
  return {
    kind: "pending",
    label: sync.status === "running" ? "Collecte en cours" : "Collecte en attente",
    description: "Aucune donnée n'a encore été enregistrée pour ce compte.",
  }
}
