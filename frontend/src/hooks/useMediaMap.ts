import { useMemo } from "react"
import type { MediaAsset } from "@/types"
import { useMediaByIds } from "./queries/media"

/** Index des médias demandés par identifiant (vignettes des publications). */
export function useMediaMap(ids: string[]): Record<string, MediaAsset> {
  const { data } = useMediaByIds(ids)
  return useMemo(() => Object.fromEntries((data ?? []).map((m) => [m.id, m])), [data])
}
