// Fuseaux horaires. Les dates sont saisies dans le fuseau du navigateur et envoyées en UTC (ISO) ;
// le fuseau IANA accompagne chaque programmation (docs/decisions.md ADR-020).

export const TIMEZONES = [
  "Europe/Paris",
  "Europe/Brussels",
  "Europe/Zurich",
  "America/Montreal",
  "Africa/Abidjan",
  "Africa/Dakar",
  "Indian/Reunion",
] as const

/** Fuseau IANA réel du navigateur (ex. « Europe/Paris »). */
export function browserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC"
  } catch {
    return "UTC"
  }
}
