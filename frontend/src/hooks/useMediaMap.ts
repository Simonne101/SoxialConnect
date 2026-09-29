import { useMemo } from "react"
import type { MediaAsset } from "@/types"
import { useMedia } from "./queries/media"

/** Index des médias par identifiant (vignettes des publications). */
export function useMediaMap(): Record<string, MediaAsset> {
  const { data } = useMedia()
  return useMemo(() => Object.fromEntries((data ?? []).map((m) => [m.id, m])), [data])
}
