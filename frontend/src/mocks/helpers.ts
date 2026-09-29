// Utilitaires de génération des données fictives. Les dates sont relatives au jour courant
// afin que le calendrier et le tableau de bord restent toujours pertinents.

/** Date ISO à J+offset, à l'heure indiquée (heure locale). */
export function at(dayOffset: number, hour: number, minute = 0): string {
  const d = new Date()
  d.setDate(d.getDate() + dayOffset)
  d.setHours(hour, minute, 0, 0)
  return d.toISOString()
}

/** Date ISO il y a N minutes. */
export function minutesAgo(minutes: number): string {
  return new Date(Date.now() - minutes * 60_000).toISOString()
}

/** Générateur pseudo-aléatoire déterministe (mulberry32) : mêmes données à chaque rechargement. */
export function seeded(seed: number) {
  let a = seed >>> 0
  return () => {
    a |= 0
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

let counter = 0
/** Identifiant local unique (le backend fournira des UUID v7). */
export function uid(prefix: string): string {
  counter += 1
  const rand = typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID().slice(0, 8)
    : Math.random().toString(36).slice(2, 10)
  return `${prefix}-${rand}${counter}`
}
