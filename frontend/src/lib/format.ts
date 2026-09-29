import { format, formatDistanceToNowStrict, isToday, isTomorrow, isYesterday } from "date-fns"
import { fr } from "date-fns/locale"

const compactFmt = new Intl.NumberFormat("fr-FR", { notation: "compact", maximumFractionDigits: 1 })
const intFmt = new Intl.NumberFormat("fr-FR")

/** 48200 → « 48,2 k ». null → « n/d » (métrique non fournie, jamais 0). */
export function formatCompact(value: number | null | undefined): string {
  if (value === null || value === undefined) return "n/d"
  return compactFmt.format(value).replace(/ /g, " ")
}

export function formatInt(value: number | null | undefined): string {
  if (value === null || value === undefined) return "n/d"
  return intFmt.format(value)
}

export function formatPercent(value: number | null | undefined, digits = 1): string {
  if (value === null || value === undefined) return "n/d"
  return `${value.toLocaleString("fr-FR", { maximumFractionDigits: digits, minimumFractionDigits: digits })} %`
}

export function formatChange(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—"
  const sign = value > 0 ? "+" : ""
  return `${sign}${value.toLocaleString("fr-FR", { maximumFractionDigits: 1 })} %`
}

export function formatBytes(bytes: number | null | undefined): string {
  if (!bytes && bytes !== 0) return "—"
  const units = ["o", "Ko", "Mo", "Go"]
  let v = bytes
  let i = 0
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return `${v.toLocaleString("fr-FR", { maximumFractionDigits: i === 0 ? 0 : 1 })} ${units[i]}`
}

export function formatDuration(ms: number | null | undefined): string {
  if (ms === null || ms === undefined) return "—"
  const total = Math.round(ms / 1000)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n: number) => n.toString().padStart(2, "0")
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`
}

export function formatDate(iso: string, pattern = "d MMM yyyy"): string {
  return format(new Date(iso), pattern, { locale: fr })
}

/** « Lun 22 sept. · 10:00 » comme dans la maquette. */
export function formatSlot(iso: string): string {
  const d = new Date(iso)
  const day = isToday(d)
    ? "Aujourd'hui"
    : isTomorrow(d)
      ? "Demain"
      : isYesterday(d)
        ? "Hier"
        : capitalize(format(d, "EEE d MMM", { locale: fr }))
  return `${day} · ${format(d, "HH:mm")}`
}

export function formatTime(iso: string): string {
  return format(new Date(iso), "HH:mm")
}

/** « il y a 5 min » */
export function formatRelative(iso: string): string {
  return `il y a ${formatDistanceToNowStrict(new Date(iso), { locale: fr })}`
}

export function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1)
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? "")
    .join("")
}
