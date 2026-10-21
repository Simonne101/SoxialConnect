// Page Analytics en mode API (P4) : états par réseau issus du serveur, valeurs null, dernier relevé, période,
// et absence de jeu simulé dans le chemin API. Exécution : npm test (node --test, sans dépendance).
import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { describe, it } from "node:test"
import { formatMetric, latestPoints, metricColumns, networkStatus, NOT_AVAILABLE, periodRange } from "../src/lib/analyticsView.ts"
import type { LiveAnalyticsPoint, LiveMetricKey, LiveSyncState } from "../src/types/index.ts"

const EMPTY: Record<LiveMetricKey, number | null> = { impressions: null, reach: null, views: null, engagements: null, clicks: null, likes: null, comments: null, shares: null, saves: null, followers: null }

function point(overrides: Partial<LiveAnalyticsPoint> & { metrics?: Partial<Record<LiveMetricKey, number | null>> }): LiveAnalyticsPoint {
  return { socialAccountId: "acc-yt", platform: "youtube", postVariantId: null, date: "2026-10-14", granularity: "lifetime", followersDelta: null, engagementRate: null, extra: null,
    ...overrides, metrics: { ...EMPTY, ...overrides.metrics } }
}

function sync(overrides: Partial<LiveSyncState>): LiveSyncState {
  return { socialAccountId: "acc", platform: "youtube", status: "pending", lastAttemptAt: null, lastSuccessAt: null, lastErrorCode: null, lastErrorMessage: null, nextAttemptAt: null, ...overrides }
}

describe("formatMetric", () => {
  it("null reste « Non disponible », 0 reste 0", () => {
    assert.equal(formatMetric(null), NOT_AVAILABLE)
    assert.equal(formatMetric(undefined), NOT_AVAILABLE)
    assert.equal(formatMetric(0), "0")
    assert.equal(formatMetric(1520).replace(/\s/g, " "), "1 520")
  })
})

describe("periodRange", () => {
  it("bornes UTC incluses envoyées à l'API (30 jours = J-29 → J)", () => {
    assert.deepEqual(periodRange("30d", new Date("2026-10-14T23:30:00Z")), { from: "2026-09-15", to: "2026-10-14" })
    assert.deepEqual(periodRange("7d", new Date("2026-10-14T00:10:00Z")), { from: "2026-10-08", to: "2026-10-14" })
  })
})

describe("metricColumns", () => {
  it("YouTube : vues, J'aime et commentaires ; jamais de colonne pour une métrique qu'aucun relevé ne fournit", () => {
    const points = [
      point({ postVariantId: "v1", metrics: { views: 7, likes: 1, comments: 1 } }),
      point({ postVariantId: "v2", metrics: { views: 0, likes: null, comments: null } }),
    ]
    assert.deepEqual(metricColumns(points), ["views", "likes", "comments"])
  })
})

describe("latestPoints", () => {
  it("garde le relevé le plus récent par publication et par compte, sans additionner", () => {
    const points = [
      point({ postVariantId: "v1", date: "2026-10-13", metrics: { views: 3 } }),
      point({ postVariantId: "v1", date: "2026-10-14", metrics: { views: 7 } }),
      point({ date: "2026-10-12", metrics: { followers: 0 } }),
      point({ date: "2026-10-14", metrics: { followers: 1 } }),
    ]
    assert.deepEqual(latestPoints(points, "post").map((p) => p.metrics.views), [7])
    assert.deepEqual(latestPoints(points, "account").map((p) => p.metrics.followers), [1])
  })
})

describe("networkStatus — état issu du serveur, jamais du nom du réseau", () => {
  it("données disponibles après une collecte réussie", () => {
    assert.equal(networkStatus(sync({ status: "succeeded", lastSuccessAt: "2026-10-14T11:00:00Z" }), true).kind, "available")
    assert.match(networkStatus(sync({ status: "succeeded" }), false).description, /aucun relevé sur la période/)
  })

  it("collecte en attente quand rien n'est enregistré", () => {
    assert.equal(networkStatus(sync({ status: "pending" }), false).label, "Collecte en attente")
    assert.equal(networkStatus(sync({ status: "running" }), false).label, "Collecte en cours")
  })

  it("LinkedIn sans r_member_postAnalytics : autorisation requise, message du serveur affiché", () => {
    const status = networkStatus(sync({ platform: "linkedin", status: "unsupported", lastErrorCode: "PERMISSION_REQUIRED", lastErrorMessage: "Accordez r_member_postAnalytics." }), false)
    assert.deepEqual([status.kind, status.label, status.description], ["permission", "Autorisation requise", "Accordez r_member_postAnalytics."])
  })

  it("jeton invalide ou compte déconnecté : reconnexion requise", () => {
    for (const code of ["TOKEN_INVALID", "TOKEN_REVOKED", "ACCOUNT_NOT_CONNECTED", "REFRESH_TOKEN_MISSING"]) {
      assert.equal(networkStatus(sync({ status: "failed", lastErrorCode: code }), false).kind, "reconnect", code)
    }
  })

  it("autre échec : erreur de synchronisation, en précisant que les dernières valeurs restent affichées", () => {
    const status = networkStatus(sync({ status: "failed", lastErrorCode: "QUOTA_EXCEEDED", lastErrorMessage: "Quota quotidien atteint.", lastSuccessAt: "2026-10-13T10:00:00Z" }), true)
    assert.equal(status.kind, "error")
    assert.match(status.description, /^Quota quotidien atteint\. Les dernières valeurs/)
  })

  it("X sans adaptateur : collecte désactivée (payante) ; Telegram : non disponible ; démonstration", () => {
    assert.deepEqual([networkStatus(sync({ platform: "x", status: "unsupported", lastErrorCode: "NO_ADAPTER" }), false).label], ["Collecte désactivée"])
    assert.match(networkStatus(sync({ platform: "telegram", status: "unsupported", lastErrorCode: "NO_ADAPTER" }), false).description, /bots Telegram/)
    assert.equal(networkStatus(sync({ status: "unsupported", lastErrorCode: "DEMO_ACCOUNT" }), false).label, "Compte de démonstration")
  })

  it("un réseau qui collecte d'habitude (YouTube) sans adaptateur côté serveur n'est jamais « disponible »", () => {
    assert.equal(networkStatus(sync({ platform: "youtube", status: "unsupported", lastErrorCode: "NO_ADAPTER" }), true).kind, "unavailable")
  })
})

describe("mode API : aucun jeu simulé", () => {
  const read = (path: string) => readFileSync(new URL(path, import.meta.url), "utf8")

  it("le service ne charge le module simulé que dynamiquement, en mode démonstration", () => {
    const source = read("../src/services/misc.ts")
    assert.doesNotMatch(source, /^import[^\n]*mocks\/analytics/m, "aucun import statique du jeu simulé")
    const live = source.slice(source.indexOf("export async function getLiveAnalytics"))
    assert.doesNotMatch(live.slice(0, live.indexOf("\n}\n")), /mocks|buildAnalytics/, "getLiveAnalytics n'utilise aucune donnée simulée")
  })

  it("la page en mode API ne dépend d'aucun mock", () => {
    assert.doesNotMatch(read("../src/components/analytics/LiveAnalytics.tsx"), /mocks|useAnalytics\(/)
    assert.doesNotMatch(read("../src/lib/analyticsView.ts"), /mocks/)
  })
})
