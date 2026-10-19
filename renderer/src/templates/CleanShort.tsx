import { Video } from "@remotion/media"
import { AbsoluteFill, interpolate, useCurrentFrame, useVideoConfig } from "remotion"
import type { ClipCompositionProps } from "../types"

/**
 * Modèle V1 « clean-short » (ADR-051) : vidéo du passage plein cadre (déjà recadrée par FFmpeg),
 * décodée dans le navigateur par <Video> de @remotion/media (ADR-057) : <OffthreadVideo> passait par le cache
 * du compositeur, que Remotion réduit sous la taille d'une image 1080×1920 quand la mémoire libre est faible
 * (« No frame found at position »). Aucun repli silencieux vers OffthreadVideo : un échec reste explicite.
 * accroche en haut pendant les premières secondes, légende active en bas. Sobre et lisible :
 * apparition courte, texte blanc sur fond semi-opaque, jamais par-dessus le centre de l'image.
 */
export function CleanShort({ video_src, captions, subtitles, hook, hook_duration_ms, branding }: ClipCompositionProps) {
  const frame = useCurrentFrame()
  const { fps, width, height } = useVideoConfig()
  const ms = (frame / fps) * 1000
  const accent = branding?.accent ?? "#7c5cff"
  const unit = Math.min(width, height) / 1080

  const active = subtitles ? captions.find((c) => ms >= c.start_ms && ms < c.end_ms) : undefined
  const captionIn = active ? interpolate(ms - active.start_ms, [0, 120], [0, 1], { extrapolateRight: "clamp" }) : 0

  const showHook = hook !== null && hook !== "" && ms < hook_duration_ms
  const hookOpacity = showHook ? interpolate(ms, [0, 200, hook_duration_ms - 250, hook_duration_ms], [0, 1, 1, 0], { extrapolateLeft: "clamp", extrapolateRight: "clamp" }) : 0

  return (
    <AbsoluteFill style={{ backgroundColor: "#0b0b14", fontFamily: "Inter, 'Segoe UI', Arial, sans-serif" }}>
      {video_src ? <Video src={video_src} objectFit="cover" disallowFallbackToOffthreadVideo style={{ width: "100%", height: "100%" }} /> : null}

      {showHook && (
        <AbsoluteFill style={{ justifyContent: "flex-start", alignItems: "center", paddingTop: height * 0.08 }}>
          <div
            style={{
              opacity: hookOpacity,
              maxWidth: width * 0.86,
              padding: `${24 * unit}px ${36 * unit}px`,
              borderRadius: 28 * unit,
              background: "rgba(11, 11, 20, 0.78)",
              borderLeft: `${10 * unit}px solid ${accent}`,
              color: "#ffffff",
              fontSize: 58 * unit,
              fontWeight: 800,
              lineHeight: 1.15,
              textAlign: "center",
            }}
          >
            {hook}
          </div>
        </AbsoluteFill>
      )}

      {active && (
        <AbsoluteFill style={{ justifyContent: "flex-end", alignItems: "center", paddingBottom: height * 0.14 }}>
          <div
            style={{
              opacity: captionIn,
              transform: `translateY(${(1 - captionIn) * 12 * unit}px)`,
              maxWidth: width * 0.88,
              padding: `${14 * unit}px ${28 * unit}px`,
              borderRadius: 18 * unit,
              background: "rgba(0, 0, 0, 0.62)",
              color: "#ffffff",
              fontSize: 54 * unit,
              fontWeight: 700,
              lineHeight: 1.2,
              textAlign: "center",
            }}
          >
            {active.text}
          </div>
        </AbsoluteFill>
      )}
    </AbsoluteFill>
  )
}
