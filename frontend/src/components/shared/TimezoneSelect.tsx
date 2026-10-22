import { useMemo } from "react"
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from "@/components/ui/select"
import { TIMEZONE_GROUPS, TIMEZONES, utcOffsetLabel } from "@/lib/time"

/** Choix du pays de l'utilisateur, enregistré comme fuseau horaire IANA (Afrique d'abord, puis les autres). */
export function TimezoneSelect({ id, value, onChange }: { id: string; value: string; onChange: (timezone: string) => void }) {
  // Calculés une fois par affichage : les décalages changent seulement avec l'heure d'été.
  const offsets = useMemo(() => Object.fromEntries([...TIMEZONES, value].map((tz) => [tz, utcOffsetLabel(tz)])), [value])
  // Un profil peut contenir un fuseau hors de la liste (ancien choix, fuseau du navigateur) : il reste affiché.
  const unlisted = !TIMEZONES.includes(value)

  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger id={id} className="w-full">
        <SelectValue />
      </SelectTrigger>
      <SelectContent className="max-h-80">
        {unlisted && (
          <SelectItem value={value}>
            {value} · {offsets[value]}
          </SelectItem>
        )}
        {TIMEZONE_GROUPS.map((group) => (
          <SelectGroup key={group.label}>
            <SelectLabel>{group.label}</SelectLabel>
            {group.options.map((o) => (
              <SelectItem key={o.value} value={o.value}>
                {o.country} · {offsets[o.value]}
              </SelectItem>
            ))}
          </SelectGroup>
        ))}
      </SelectContent>
    </Select>
  )
}
