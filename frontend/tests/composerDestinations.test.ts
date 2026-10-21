// Composer multi-comptes : sélection de plusieurs comptes d'un même réseau, une destination (variante
// serveur) par compte, validation de chaque destination, relecture d'une publication existante.
// Exécution : npm test (node --test, TypeScript exécuté nativement, sans dépendance).
import assert from "node:assert/strict"
import { describe, it } from "node:test"
import {
  accountChoices,
  defaultVariant,
  divergentPlatforms,
  EMPTY_VALUES,
  fromPost,
  selectedAccounts,
  toDraftInput,
  validateComposer,
  type ComposerValues,
} from "../src/components/content/composer/model.ts"
import type { MediaAsset, Post, PostVariant, SocialAccount } from "../src/types/index.ts"

function account(id: string, platform: SocialAccount["platform"], status: SocialAccount["status"] = "connected"): SocialAccount {
  return { id, platform, status, accountType: "channel", displayName: `Compte ${id}`, username: null, entities: [], permissions: [], followers: null,
    connectedAt: null, lastSyncedAt: null, statusMessage: status === "connected" ? null : "Autorisation expirée.", isDemo: false } as unknown as SocialAccount
}

const YT = ["yt1", "yt2", "yt3", "yt4", "yt5"].map((id) => account(id, "youtube"))
const LI = [account("liA", "linkedin"), account("liB", "linkedin")]
const ACCOUNTS = [...YT, ...LI, account("yt-old", "youtube", "expired"), account("other-x", "x")]
const VIDEO = { id: "m1", type: "video", mimeType: "video/mp4", sizeBytes: 1000, usable: true, isGif: false } as unknown as MediaAsset

function values(patch: Partial<ComposerValues>): ComposerValues {
  return { ...EMPTY_VALUES, title: "", body: "Ma vidéo", mediaIds: ["m1"], ...patch }
}

const youtube = (accountIds: string[]) => ({ ...defaultVariant("youtube"), accountIds, options: { ...defaultVariant("youtube").options, title: "Titre" } })

describe("sélection des comptes", () => {
  it("cinq comptes YouTube cochés → cinq destinations distinctes, mêmes options", () => {
    const input = toDraftInput(values({ platforms: ["youtube"], variants: { youtube: youtube(YT.map((a) => a.id)) } }), ACCOUNTS, null)
    assert.deepEqual(input.targets.map((t) => t.socialAccountId), ["yt1", "yt2", "yt3", "yt4", "yt5"])
    assert.ok(input.targets.every((t) => t.platform === "youtube" && t.options.youtubeVisibility === "public" && t.format === "video"))
  })

  it("un seul compte, plusieurs comptes, plusieurs réseaux", () => {
    const one = toDraftInput(values({ platforms: ["youtube"], variants: { youtube: youtube(["yt3"]) } }), ACCOUNTS, null)
    assert.deepEqual(one.targets.map((t) => t.socialAccountId), ["yt3"])
    const mixed = toDraftInput(values({ mediaIds: [], platforms: ["youtube", "linkedin"], variants: { youtube: youtube(["yt1", "yt2"]), linkedin: { ...defaultVariant("linkedin"), accountIds: ["liA", "liB"] } } }), ACCOUNTS, null)
    assert.deepEqual(mixed.targets.map((t) => `${t.platform}:${t.socialAccountId}`), ["youtube:yt1", "youtube:yt2", "linkedin:liA", "linkedin:liB"])
  })

  it("un identifiant inconnu ou d'un autre réseau n'est jamais envoyé ni remplacé par un autre compte", () => {
    const { found, missing } = selectedAccounts(ACCOUNTS, "youtube", ["yt1", "other-x", "supprime", "yt1"])
    assert.deepEqual(found.map((a) => a.id), ["yt1"])
    assert.deepEqual(missing, ["other-x", "supprime"])
    const input = toDraftInput(values({ platforms: ["youtube"], variants: { youtube: youtube(["supprime"]) } }), ACCOUNTS, null)
    assert.deepEqual(input.targets, [])
  })

  it("comptes proposés : connectés, plus ceux déjà choisis qui ne le sont plus", () => {
    assert.deepEqual(accountChoices(ACCOUNTS, "youtube", []).map((a) => a.id), ["yt1", "yt2", "yt3", "yt4", "yt5"])
    assert.deepEqual(accountChoices(ACCOUNTS, "youtube", ["yt-old"]).map((a) => a.id), ["yt-old", "yt1", "yt2", "yt3", "yt4", "yt5"])
  })
})

describe("validation de chaque destination", () => {
  const media = { m1: VIDEO }

  it("aucun compte coché : erreur explicite", () => {
    const issues = validateComposer(values({ platforms: ["youtube"], variants: { youtube: youtube([]) } }), ACCOUNTS, media, "publish")
    assert.ok(issues.some((i) => i.level === "error" && /cochez au moins un compte/.test(i.message)))
  })

  it("un compte expiré parmi les destinations est nommé, jamais ignoré en silence", () => {
    const issues = validateComposer(values({ platforms: ["youtube"], variants: { youtube: youtube(["yt1", "yt-old"]) } }), ACCOUNTS, media, "publish")
    const errors = issues.filter((i) => i.level === "error")
    assert.equal(errors.length, 1)
    assert.match(errors[0].message, /Compte yt-old n'est pas prêt/)
  })

  it("cinq comptes connectés valides : aucune erreur", () => {
    const issues = validateComposer(values({ platforms: ["youtube"], variants: { youtube: youtube(YT.map((a) => a.id)) } }), ACCOUNTS, media, "publish")
    assert.deepEqual(issues.filter((i) => i.level === "error"), [])
  })

  it("contenu incompatible signalé par réseau (vidéo vers LinkedIn), pas ignoré", () => {
    const issues = validateComposer(values({ platforms: ["youtube", "linkedin"], variants: { youtube: youtube(["yt1"]), linkedin: { ...defaultVariant("linkedin"), accountIds: ["liA", "liB"] } } }), ACCOUNTS, media, "publish")
    assert.ok(issues.some((i) => i.platform === "linkedin" && i.level === "error" && /images et vidéos/.test(i.message)))
    assert.ok(!issues.some((i) => i.platform === "youtube" && i.level === "error"))
  })
})

describe("relecture d'une publication existante", () => {
  const variant = (id: string, accountId: string, body: string | null = null): PostVariant =>
    ({ id, postId: "p1", platform: "youtube", socialAccountId: accountId, format: "video", body, options: { title: "T" }, status: "draft" }) as unknown as PostVariant
  const post = (variants: PostVariant[]) => ({ id: "p1", title: null, body: "Texte", mediaIds: [], variants }) as unknown as Post

  it("plusieurs comptes d'un même réseau sont tous conservés (aucune destination perdue à l'enregistrement)", () => {
    const loaded = fromPost(post([variant("v1", "yt1"), variant("v2", "yt2"), variant("v3", "yt3")]))
    assert.deepEqual(loaded.variants.youtube?.accountIds, ["yt1", "yt2", "yt3"])
    assert.deepEqual(toDraftInput(loaded, ACCOUNTS, null).targets.map((t) => t.socialAccountId), ["yt1", "yt2", "yt3"])
    assert.deepEqual(divergentPlatforms(post([variant("v1", "yt1"), variant("v2", "yt2")])), [])
  })

  it("des contenus différents pour un même réseau sont signalés", () => {
    assert.deepEqual(divergentPlatforms(post([variant("v1", "yt1", "A"), variant("v2", "yt2", "B")])), ["youtube"])
  })
})
