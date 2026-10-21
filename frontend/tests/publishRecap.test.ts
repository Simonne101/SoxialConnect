// Récapitulatif avant publication : réseaux, comptes, texte et visibilité réellement transmise.
// Exécution : npm test (node --test, TypeScript exécuté nativement, sans dépendance).
import assert from "node:assert/strict"
import { describe, it } from "node:test"
import { publicationRecap, recapMedia, visibilityLabel } from "../src/components/content/composer/recap.ts"
import type { MediaAsset, PlatformKey, SocialAccount, VariantOptions } from "../src/types/index.ts"

function account(platform: PlatformKey, displayName: string): SocialAccount {
  return { id: `${platform}-1`, platform, status: "connected", accountType: null, displayName, username: null, entities: [], permissions: [],
    followers: null, connectedAt: null, lastSyncedAt: null, statusMessage: null } as unknown as SocialAccount
}

describe("visibilityLabel", () => {
  it("YouTube : la visibilité choisie est affichée telle quelle", () => {
    assert.equal(visibilityLabel("youtube", { youtubeVisibility: "public" }), "Publique")
    assert.equal(visibilityLabel("youtube", { youtubeVisibility: "unlisted" }), "Non répertoriée")
    assert.equal(visibilityLabel("youtube", { youtubeVisibility: "private" }), "Privée")
  })

  it("YouTube : sans choix, le récapitulatif annonce « Privée », comme le serveur (jamais « Publique » implicite)", () => {
    assert.equal(visibilityLabel("youtube", {}), "Privée (par défaut)")
    assert.equal(visibilityLabel("youtube", { youtubeVisibility: "PUBLIC" } as unknown as VariantOptions), "Privée (par défaut)")
  })

  it("TikTok : confidentialité choisie ; réseaux sans visibilité : null", () => {
    assert.equal(visibilityLabel("tiktok", { privacy: "followers" }), "Mes abonnés")
    assert.equal(visibilityLabel("tiktok", {}), null)
    assert.equal(visibilityLabel("telegram", { youtubeVisibility: "public" }), null)
  })
})

describe("publicationRecap", () => {
  it("une ligne par destination (compte), avec compte, texte envoyé et visibilité", () => {
    const options: Partial<Record<PlatformKey, VariantOptions>> = { youtube: { youtubeVisibility: "public" }, telegram: {} }
    const lines = publicationRecap({
      destinations: [
        { platform: "youtube", account: account("youtube", "Chaîne 1") },
        { platform: "youtube", account: { ...account("youtube", "Chaîne 2"), id: "youtube-2" } },
        { platform: "telegram", account: account("telegram", "Mon canal") },
      ],
      options: (p) => options[p] ?? {},
      text: (p) => `Texte ${p}`,
    })

    assert.deepEqual(lines.map((l) => [l.key, l.network, l.account, l.text, l.visibility]), [
      ["youtube:youtube-1", "YouTube", "Chaîne 1", "Texte youtube", "Publique"],
      ["youtube:youtube-2", "YouTube", "Chaîne 2", "Texte youtube", "Publique"],
      ["telegram:telegram-1", "Telegram", "Mon canal", "Texte telegram", null],
    ])
  })

  it("signale un compte introuvable plutôt que d'en afficher un autre", () => {
    const [line] = publicationRecap({ destinations: [{ platform: "youtube", account: undefined }], options: () => ({}), text: () => "" })
    assert.equal(line.account, "Compte non trouvé")
  })
})

describe("recapMedia", () => {
  it("restitue les médias dans l'ordre de la publication et ignore un identifiant inconnu", () => {
    const v1 = { id: "v1", type: "video" } as MediaAsset
    const i1 = { id: "i1", type: "image" } as MediaAsset
    assert.deepEqual(recapMedia(["i1", "inconnu", "v1"], { v1, i1 }).map((m) => m.id), ["i1", "v1"])
  })
})
