import heroImage from "@/assets/images/hero-640.webp"
import type { MediaAsset, MediaFolder } from "@/types"
import { minutesAgo } from "./helpers"
import { POST_IMAGES } from "./postImages"

export const mockFolders: MediaFolder[] = [
  { id: "f-autumn", name: "Campagne automne", parentId: null, count: 0 },
  { id: "f-products", name: "Produits", parentId: null, count: 0 },
  { id: "f-youtube", name: "Vidéos YouTube", parentId: null, count: 0 },
  { id: "f-brand", name: "Identité de marque", parentId: null, count: 0 },
]

type Def = [id: string, name: string, type: MediaAsset["type"], thumb: number, folder: string | null, tags: string[], extra?: Partial<MediaAsset>]

const DEFS: Def[] = [
  ["m-01", "route-foret-automne.jpg", "image", 1, "f-autumn", ["automne", "nature"]],
  ["m-02", "sentier-montagne.jpg", "image", 2, "f-autumn", ["nature"]],
  ["m-03", "falaises-coucher-soleil.jpg", "image", 3, null, ["inspiration"]],
  ["m-04", "reel-astuces.mp4", "video", 4, null, ["reels", "astuces"], { durationMs: 38_000, width: 1080, height: 1920, mimeType: "video/mp4" }],
  ["m-05", "lac-matin.jpg", "image", 5, "f-autumn", ["nature", "automne"]],
  ["m-06", "checklist-rentree.pdf", "document", 11, "f-brand", ["guide"], { mimeType: "application/pdf", width: null, height: null }],
  ["m-07", "atelier-equipe.jpg", "image", 6, "f-products", ["atelier"]],
  ["m-08", "portrait-studio.jpg", "image", 8, null, ["portrait"]],
  ["m-09", "fleurs-sechees.gif", "image", 9, "f-products", ["produits", "déco"], { mimeType: "image/gif", isGif: true }],
  ["m-10", "moodboard-automne.jpg", "image", 7, "f-autumn", ["automne", "moodboard"]],
  ["m-11", "ville-reflets.jpg", "image", 12, null, ["urbain"]],
  ["m-12", "jingle-intro.mp3", "audio", 10, "f-brand", ["son", "intro"], { mimeType: "audio/mpeg", durationMs: 12_000, width: null, height: null }],
  ["m-13", "coulisses-atelier.mp4", "video", 6, "f-products", ["coulisses"], { durationMs: 52_000, width: 1080, height: 1920, mimeType: "video/mp4" }],
  ["m-14", "tutoriel-planification.mp4", "video", 5, "f-youtube", ["tutoriel"], { durationMs: 642_000, width: 1920, height: 1080, mimeType: "video/mp4" }],
  ["m-15", "interview-strategie.mp4", "video", 12, "f-youtube", ["interview"], { durationMs: 2_532_000, width: 1920, height: 1080, mimeType: "video/mp4" }],
  ["m-16", "coucher-soleil-plage.jpg", "image", 3, null, ["été"]],
  ["m-17", "logo-soxialconnect.png", "image", 11, "f-brand", ["logo"], { mimeType: "image/png" }],
]

export const mockMedia: MediaAsset[] = [
  {
    id: "m-hero",
    type: "image",
    status: "ready",
    name: "visuel-hero.png",
    mimeType: "image/png",
    sizeBytes: 1_701_643,
    width: 1520,
    height: 1035,
    durationMs: null,
    folderId: "f-brand",
    tags: ["site", "marque"],
    preview: { kind: "image", src: heroImage },
    isGif: false,
    createdAt: minutesAgo(60 * 24 * 2),
  },
  // Photos des publications « paysage / productivité / café / voyage » (voir postImages.ts).
  ...Object.values(POST_IMAGES).map((img, i): MediaAsset => ({
    id: img.mediaId,
    type: "image",
    status: "ready",
    name: img.fileName,
    mimeType: "image/webp",
    sizeBytes: img.sizeBytes,
    width: img.width,
    height: img.height,
    durationMs: null,
    folderId: null,
    tags: img.tags,
    preview: { kind: "image", src: img.src },
    isGif: false,
    createdAt: minutesAgo(60 * (26 + i * 5)),
  })),
  ...DEFS.map(([id, name, type, thumb, folderId, tags, extra], i): MediaAsset => ({
    id,
    type,
    status: "ready",
    name,
    mimeType: type === "image" ? "image/jpeg" : type === "video" ? "video/mp4" : "application/octet-stream",
    sizeBytes: type === "video" ? 18_000_000 + i * 7_300_000 : type === "audio" ? 480_000 : 850_000 + i * 120_000,
    width: 1080,
    height: 1350,
    durationMs: null,
    folderId,
    tags,
    preview: { kind: "gradient", className: `thumb-${thumb}` },
    isGif: false,
    createdAt: minutesAgo(60 * (i * 9 + 3)),
    ...extra,
  })),
]
