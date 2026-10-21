import { addDays, format, nextSaturday, setHours, setMinutes, startOfDay } from "date-fns"
import { fr } from "date-fns/locale"
import { CalendarClock, Globe } from "lucide-react"
import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useNow } from "@/hooks/useNow"
import { browserTimezone } from "@/lib/time"

function at(date: Date, time: string) {
  const [h, m] = time.split(":").map(Number)
  return setMinutes(setHours(startOfDay(date), h ?? 9), m ?? 0)
}

/** Choix de la date et de l'heure de publication (fuseau de l'utilisateur, stocké en UTC). */
export function ScheduleDialog({
  open,
  onOpenChange,
  onConfirm,
  initial,
  confirmLabel = "Programmer",
  pending = false,
  timezone = browserTimezone(),
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onConfirm: (iso: string) => void
  initial?: string | null
  confirmLabel?: string
  pending?: boolean
  timezone?: string
}) {
  const now = useNow(30_000)
  const today = new Date(now)
  const [date, setDate] = useState<Date>(() => (initial ? new Date(initial) : addDays(new Date(now), 1)))
  const [time, setTime] = useState(() => (initial ? format(new Date(initial), "HH:mm") : "09:00"))
  const target = at(date, time)
  const inPast = target.getTime() < now + 60_000

  const quick = [
    { label: "Ce soir 18:00", d: today, t: "18:00" },
    { label: "Demain 09:00", d: addDays(today, 1), t: "09:00" },
    { label: "Samedi 10:00", d: nextSaturday(today), t: "10:00" },
  ].filter((q) => at(q.d, q.t).getTime() > now)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[560px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <CalendarClock className="size-5 text-brand-500" aria-hidden="true" /> Programmer la publication
          </DialogTitle>
          <DialogDescription>Choisissez quand votre publication doit partir. Vous pourrez la modifier jusqu'à l'heure prévue.</DialogDescription>
        </DialogHeader>

        <div className="flex flex-wrap gap-2">
          {quick.map((q) => (
            <Button key={q.label} type="button" size="xs" variant="secondary" onClick={() => { setDate(q.d); setTime(q.t) }}>
              {q.label}
            </Button>
          ))}
        </div>

        <div className="grid grid-cols-1 gap-5 sm:grid-cols-[auto_minmax(0,1fr)]">
          <Calendar
            mode="single"
            selected={date}
            onSelect={(d) => d && setDate(d)}
            locale={fr}
            weekStartsOn={1}
            disabled={{ before: startOfDay(today) }}
            className="rounded-xl border border-border"
          />
          <div className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="schedule-time" className="text-[13px] font-semibold">Heure</Label>
              <Input id="schedule-time" type="time" value={time} step={300} onChange={(e) => setTime(e.target.value)} />
            </div>
            <p className="flex items-center gap-1.5 text-xs text-ink-muted">
              <Globe className="size-3.5" aria-hidden="true" /> Fuseau : {timezone}
            </p>
            <div className="rounded-xl bg-brand-50 p-3 text-sm text-brand-700" aria-live="polite">
              Publication prévue le <strong>{format(target, "EEEE d MMMM 'à' HH:mm", { locale: fr })}</strong>
            </div>
            {inPast && <p role="alert" className="text-xs font-medium text-danger-700">Choisissez une date et une heure à venir.</p>}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Annuler</Button>
          <Button disabled={inPast || pending} onClick={() => onConfirm(target.toISOString())}>
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
