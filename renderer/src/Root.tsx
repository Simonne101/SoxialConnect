import { Composition } from "remotion"
import { CleanShort } from "./templates/CleanShort"
import { DEFAULT_PROPS, type ClipCompositionProps } from "./types"

/**
 * Compositions du Clipper (ADR-051). « YouTubeClip » reçoit le RenderPlan du backend ; ses dimensions,
 * sa cadence et sa durée viennent du plan (calculateMetadata), jamais de valeurs codées ici.
 * Nouveaux modèles : un composant dans templates/ + son nom dans le champ « template » du plan.
 */
export function Root() {
  return (
    <Composition
      id="YouTubeClip"
      component={CleanShort}
      defaultProps={DEFAULT_PROPS}
      width={DEFAULT_PROPS.width}
      height={DEFAULT_PROPS.height}
      fps={DEFAULT_PROPS.fps}
      durationInFrames={DEFAULT_PROPS.duration_in_frames}
      calculateMetadata={({ props }: { props: ClipCompositionProps }) => ({
        width: props.width,
        height: props.height,
        fps: props.fps,
        durationInFrames: Math.max(1, Math.round(props.duration_in_frames)),
      })}
    />
  )
}
