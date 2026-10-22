// Fuseaux horaires. Les dates sont saisies dans le fuseau du navigateur et envoyées en UTC (ISO) ;
// le fuseau IANA accompagne chaque programmation (docs/decisions.md ADR-020).

export interface TimezoneOption {
  /** Identifiant IANA enregistré dans le profil (validé par l'API). */
  value: string
  /** Pays (et ville quand le pays a plusieurs fuseaux), en français. */
  country: string
}

/** Les 54 pays d'Afrique, chacun avec le fuseau de sa capitale (la RD Congo en a deux). */
export const AFRICA_TIMEZONES: readonly TimezoneOption[] = [
  { country: "Afrique du Sud", value: "Africa/Johannesburg" },
  { country: "Algérie", value: "Africa/Algiers" },
  { country: "Angola", value: "Africa/Luanda" },
  { country: "Bénin", value: "Africa/Porto-Novo" },
  { country: "Botswana", value: "Africa/Gaborone" },
  { country: "Burkina Faso", value: "Africa/Ouagadougou" },
  { country: "Burundi", value: "Africa/Bujumbura" },
  { country: "Cameroun", value: "Africa/Douala" },
  { country: "Cap-Vert", value: "Atlantic/Cape_Verde" },
  { country: "Centrafrique", value: "Africa/Bangui" },
  { country: "Comores", value: "Indian/Comoro" },
  { country: "Congo", value: "Africa/Brazzaville" },
  { country: "Côte d'Ivoire", value: "Africa/Abidjan" },
  { country: "Djibouti", value: "Africa/Djibouti" },
  { country: "Égypte", value: "Africa/Cairo" },
  { country: "Érythrée", value: "Africa/Asmara" },
  { country: "Eswatini", value: "Africa/Mbabane" },
  { country: "Éthiopie", value: "Africa/Addis_Ababa" },
  { country: "Gabon", value: "Africa/Libreville" },
  { country: "Gambie", value: "Africa/Banjul" },
  { country: "Ghana", value: "Africa/Accra" },
  { country: "Guinée", value: "Africa/Conakry" },
  { country: "Guinée équatoriale", value: "Africa/Malabo" },
  { country: "Guinée-Bissau", value: "Africa/Bissau" },
  { country: "Kenya", value: "Africa/Nairobi" },
  { country: "Lesotho", value: "Africa/Maseru" },
  { country: "Liberia", value: "Africa/Monrovia" },
  { country: "Libye", value: "Africa/Tripoli" },
  { country: "Madagascar", value: "Indian/Antananarivo" },
  { country: "Malawi", value: "Africa/Blantyre" },
  { country: "Mali", value: "Africa/Bamako" },
  { country: "Maroc", value: "Africa/Casablanca" },
  { country: "Maurice", value: "Indian/Mauritius" },
  { country: "Mauritanie", value: "Africa/Nouakchott" },
  { country: "Mozambique", value: "Africa/Maputo" },
  { country: "Namibie", value: "Africa/Windhoek" },
  { country: "Niger", value: "Africa/Niamey" },
  { country: "Nigeria", value: "Africa/Lagos" },
  { country: "Ouganda", value: "Africa/Kampala" },
  { country: "RD Congo (Kinshasa)", value: "Africa/Kinshasa" },
  { country: "RD Congo (Lubumbashi)", value: "Africa/Lubumbashi" },
  { country: "Rwanda", value: "Africa/Kigali" },
  { country: "Sao Tomé-et-Principe", value: "Africa/Sao_Tome" },
  { country: "Sénégal", value: "Africa/Dakar" },
  { country: "Seychelles", value: "Indian/Mahe" },
  { country: "Sierra Leone", value: "Africa/Freetown" },
  { country: "Somalie", value: "Africa/Mogadishu" },
  { country: "Soudan", value: "Africa/Khartoum" },
  { country: "Soudan du Sud", value: "Africa/Juba" },
  { country: "Tanzanie", value: "Africa/Dar_es_Salaam" },
  { country: "Tchad", value: "Africa/Ndjamena" },
  { country: "Togo", value: "Africa/Lome" },
  { country: "Tunisie", value: "Africa/Tunis" },
  { country: "Zambie", value: "Africa/Lusaka" },
  { country: "Zimbabwe", value: "Africa/Harare" },
]

/** Autres fuseaux proposés depuis le début (valeurs déjà présentes dans les profils existants). */
export const OTHER_TIMEZONES: readonly TimezoneOption[] = [
  { country: "Belgique", value: "Europe/Brussels" },
  { country: "Canada (Montréal)", value: "America/Montreal" },
  { country: "France", value: "Europe/Paris" },
  { country: "La Réunion", value: "Indian/Reunion" },
  { country: "Suisse", value: "Europe/Zurich" },
]

export const TIMEZONE_GROUPS = [
  { label: "Afrique", options: AFRICA_TIMEZONES },
  { label: "Europe et Amérique", options: OTHER_TIMEZONES },
] as const

export const TIMEZONES: readonly string[] = TIMEZONE_GROUPS.flatMap((g) => g.options.map((o) => o.value))

export const DEFAULT_TIMEZONE = "Europe/Paris"

/** Décalage horaire actuel du fuseau, par exemple « UTC+1 » (tient compte de l'heure d'été). */
export function utcOffsetLabel(timezone: string, at: Date = new Date()): string {
  try {
    const part = new Intl.DateTimeFormat("en-US", { timeZone: timezone, timeZoneName: "shortOffset" })
      .formatToParts(at)
      .find((p) => p.type === "timeZoneName")?.value
    return part ? part.replace(/^GMT/, "UTC").replace(/^UTC$/, "UTC+0") : timezone
  } catch {
    return timezone
  }
}

/** Fuseau IANA réel du navigateur (ex. « Europe/Paris »). */
export function browserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC"
  } catch {
    return "UTC"
  }
}

/** Fuseau proposé par défaut : celui du navigateur s'il figure dans la liste, sinon la France. */
export function suggestedTimezone(): string {
  const tz = browserTimezone()
  return TIMEZONES.includes(tz) ? tz : DEFAULT_TIMEZONE
}
