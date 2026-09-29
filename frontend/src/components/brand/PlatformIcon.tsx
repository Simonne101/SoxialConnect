import { memo } from "react"
import { cn } from "@/lib/utils"
import type { PlatformKey } from "@/types"
import { PLATFORMS } from "@/lib/platforms"
import { BRAND_PATHS, type BrandIconKey } from "./brand-paths"

// Classes de fond par plateforme (tokens --color-* du design system).
const BADGE_BG: Record<PlatformKey, string> = {
  facebook: "bg-facebook",
  instagram: "bg-instagram-gradient",
  tiktok: "bg-tiktok",
  youtube: "bg-youtube",
  linkedin: "bg-linkedin",
  x: "bg-x",
  pinterest: "bg-pinterest",
  threads: "bg-threads",
}

const GLYPH_COLOR: Record<PlatformKey, string> = {
  facebook: "text-facebook",
  instagram: "text-instagram",
  tiktok: "text-tiktok",
  youtube: "text-youtube",
  linkedin: "text-linkedin",
  x: "text-x",
  pinterest: "text-pinterest",
  threads: "text-threads",
}

export function BrandGlyph({ name, className }: { name: BrandIconKey; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" className={className}>
      <path d={BRAND_PATHS[name]} />
    </svg>
  )
}

interface PlatformIconProps {
  platform: PlatformKey
  /** badge = pastille colorée ronde (maquette) ; glyph = logo seul coloré. */
  variant?: "badge" | "glyph"
  size?: "xs" | "sm" | "md" | "lg" | "xl"
  className?: string
  /** Libellé accessible ; par défaut décoratif (le nom est affiché à côté). */
  title?: string
}

const SIZES = {
  xs: { box: "size-5", glyph: "size-2.5" },
  sm: { box: "size-7", glyph: "size-3.5" },
  md: { box: "size-9", glyph: "size-[18px]" },
  lg: { box: "size-12", glyph: "size-6" },
  xl: { box: "size-14", glyph: "size-7" },
} as const

function PlatformIconBase({ platform, variant = "badge", size = "md", className, title }: PlatformIconProps) {
  const s = SIZES[size]
  const a11y = title ? { role: "img", "aria-label": title } : { "aria-hidden": true }
  if (variant === "glyph") {
    return (
      <span {...a11y} className={cn("inline-flex shrink-0", GLYPH_COLOR[platform], className)}>
        <BrandGlyph name={platform} className={s.box} />
      </span>
    )
  }
  return (
    <span
      {...a11y}
      title={title ?? PLATFORMS[platform].name}
      className={cn(
        "inline-flex shrink-0 items-center justify-center text-white",
        platform === "instagram" || platform === "youtube" ? "rounded-[30%]" : "rounded-full",
        BADGE_BG[platform],
        s.box,
        className,
      )}
    >
      <BrandGlyph name={platform} className={s.glyph} />
    </span>
  )
}

export const PlatformIcon = memo(PlatformIconBase)
