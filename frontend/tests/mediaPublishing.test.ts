// Publication depuis la médiathèque : sélection publiable et état transmis au Composer.
// Exécution : npm test (node --test, TypeScript exécuté nativement, sans dépendance).
import assert from "node:assert/strict"
import { describe, it } from "node:test"
import { composerStateFor, MAX_MEDIA_PER_POST, publishableSelection } from "../src/lib/mediaPublishing.ts"
import type { MediaAsset } from "../src/types/index.ts"

function media(id: string, overrides: Partial<MediaAsset> = {}): MediaAsset {
  return {
    id, type: "video", status: "ready", scanStatus: "clean", usable: true, name: `${id}.mp4`, mimeType: "video/mp4", extension: "mp4",
    sizeBytes: 2048, width: 1080, height: 1920, durationMs: 15000, folderId: null, tags: [], preview: { kind: "placeholder" },
    url: null, downloadUrl: null, failureReason: null, isGif: false, createdAt: "2026-10-14T00:00:00Z", ...overrides,
  }
}

describe("publishableSelection", () => {
  it("une vidéo prête sélectionnée est publiable, avec son identifiant de médiathèque", () => {
    assert.deepEqual(publishableSelection(["v1"], [media("v1"), media("v2")]), { ok: true, mediaIds: ["v1"] })
  })

  it("conserve l'ordre de sélection pour plusieurs médias (vidéo et image)", () => {
    const items = [media("v1"), media("i1", { type: "image", mimeType: "image/png" })]
    assert.deepEqual(publishableSelection(["i1", "v1"], items), { ok: true, mediaIds: ["i1", "v1"] })
  })

  it("refuse une sélection vide", () => {
    assert.equal(publishableSelection([], [media("v1")]).ok, false)
  })

  it("refuse un média pas encore prêt ou en quarantaine", () => {
    const result = publishableSelection(["v1"], [media("v1", { usable: false, status: "processing" })])
    assert.equal(result.ok, false)
    assert.match((result as { reason: string }).reason, /pas encore prêt/)
  })

  it("refuse un document ou un audio", () => {
    assert.equal(publishableSelection(["d1"], [media("d1", { type: "document" })]).ok, false)
    assert.equal(publishableSelection(["a1"], [media("a1", { type: "audio" })]).ok, false)
  })

  it("refuse un média qui n'est plus dans la liste affichée (supprimé, autre page)", () => {
    assert.equal(publishableSelection(["absent"], [media("v1")]).ok, false)
  })

  it("respecte la limite du serveur (20 médias par publication)", () => {
    const items = Array.from({ length: MAX_MEDIA_PER_POST + 1 }, (_, i) => media(`m${i}`))
    assert.equal(publishableSelection(items.map((m) => m.id), items).ok, false)
    assert.equal(publishableSelection(items.slice(0, MAX_MEDIA_PER_POST).map((m) => m.id), items).ok, true)
  })
})

describe("composerStateFor", () => {
  it("ouvre le Composer avec exactement les médias choisis, comme depuis le Clipper", () => {
    const ids = ["v1"]
    const state = composerStateFor(ids)
    assert.deepEqual(state, { mediaIds: ["v1"] })
    ids.push("autre")
    assert.deepEqual(state.mediaIds, ["v1"], "copie : une sélection modifiée ensuite ne change pas l'état envoyé")
  })
})
