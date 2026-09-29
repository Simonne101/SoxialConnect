// Graphiques Recharts stylés avec les tokens du design system (var(--chart-*)).
import { format } from "date-fns"
import { fr } from "date-fns/locale"
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts"
import { formatCompact } from "@/lib/format"

const AXIS = { fontSize: 12, fill: "var(--color-ink-muted)" }
const GRID = "var(--color-border)"

interface TooltipEntry {
  name?: string
  value?: number | string
  color?: string
}

function ChartTooltip({ active, payload, label, labelFormatter }: { active?: boolean; payload?: TooltipEntry[]; label?: string | number; labelFormatter?: (l: string) => string }) {
  if (!active || !payload?.length) return null
  return (
    <div className="rounded-xl border border-border bg-card px-3 py-2 text-xs shadow-float">
      <p className="mb-1 font-semibold text-ink">{labelFormatter ? labelFormatter(String(label)) : label}</p>
      {payload.map((p) => (
        <p key={p.name} className="flex items-center gap-2 text-ink-soft">
          <span className="size-2 rounded-full" style={{ background: p.color }} aria-hidden="true" />
          {p.name} : <span className="font-semibold text-ink">{typeof p.value === "number" ? formatCompact(p.value) : p.value}</span>
        </p>
      ))}
    </div>
  )
}

const dayLabel = (iso: string, long = false) => format(new Date(iso), long ? "EEEE d MMM" : "EEE", { locale: fr })
const shortDate = (iso: string) => format(new Date(iso), "d MMM", { locale: fr })

/** Dashboard — « Performance globale » : impressions + engagement (maquette). */
export function PerformanceChart({ data }: { data: { date: string; impressions: number; engagements: number }[] }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <AreaChart data={data} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
        <defs>
          <linearGradient id="pc-imp" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0" stopColor="var(--chart-1)" stopOpacity={0.22} />
            <stop offset="1" stopColor="var(--chart-1)" stopOpacity={0} />
          </linearGradient>
          <linearGradient id="pc-eng" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0" stopColor="var(--chart-2)" stopOpacity={0.16} />
            <stop offset="1" stopColor="var(--chart-2)" stopOpacity={0} />
          </linearGradient>
        </defs>
        <CartesianGrid stroke={GRID} strokeDasharray="4 4" vertical={false} />
        <XAxis dataKey="date" tickFormatter={(d: string) => dayLabel(d)} tick={AXIS} axisLine={false} tickLine={false} />
        <YAxis yAxisId="imp" tickFormatter={(v: number) => formatCompact(v)} tick={AXIS} axisLine={false} tickLine={false} width={48} />
        <YAxis yAxisId="eng" orientation="right" hide />
        <Tooltip content={<ChartTooltip labelFormatter={(l) => dayLabel(l, true)} />} />
        <Area yAxisId="imp" type="monotone" dataKey="impressions" name="Impressions" stroke="var(--chart-1)" strokeWidth={2.5} fill="url(#pc-imp)" dot={{ r: 3, fill: "var(--chart-1)" }} activeDot={{ r: 5 }} />
        <Area yAxisId="eng" type="monotone" dataKey="engagements" name="Engagement" stroke="var(--chart-2)" strokeWidth={2.5} fill="url(#pc-eng)" dot={{ r: 3, fill: "var(--chart-2)" }} activeDot={{ r: 5 }} />
      </AreaChart>
    </ResponsiveContainer>
  )
}

/** Analytics — cette période vs période précédente (maquette « Analyses »). */
export function ComparisonChart({ data, dataKey = "impressions", previousKey = "previousImpressions", label = "Impressions" }: { data: object[]; dataKey?: string; previousKey?: string; label?: string }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <AreaChart data={data} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
        <defs>
          <linearGradient id="cc-cur" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0" stopColor="var(--chart-1)" stopOpacity={0.2} />
            <stop offset="1" stopColor="var(--chart-1)" stopOpacity={0} />
          </linearGradient>
        </defs>
        <CartesianGrid stroke={GRID} strokeDasharray="4 4" vertical={false} />
        <XAxis dataKey="date" tickFormatter={shortDate} tick={AXIS} axisLine={false} tickLine={false} minTickGap={24} />
        <YAxis tickFormatter={(v: number) => formatCompact(v)} tick={AXIS} axisLine={false} tickLine={false} width={48} />
        <Tooltip content={<ChartTooltip labelFormatter={(l) => format(new Date(l), "d MMMM yyyy", { locale: fr })} />} />
        <Legend verticalAlign="top" align="right" height={32} iconType="plainline" wrapperStyle={{ fontSize: 12 }} />
        <Area type="monotone" dataKey={dataKey} name={`${label} — cette période`} stroke="var(--chart-1)" strokeWidth={2.5} fill="url(#cc-cur)" dot={false} activeDot={{ r: 5 }} />
        <Area type="monotone" dataKey={previousKey} name="Période précédente" stroke="var(--color-brand-300)" strokeWidth={2} strokeDasharray="5 5" fill="none" dot={false} />
      </AreaChart>
    </ResponsiveContainer>
  )
}

export function EngagementBarChart({ data }: { data: object[] }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={data} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
        <CartesianGrid stroke={GRID} strokeDasharray="4 4" vertical={false} />
        <XAxis dataKey="date" tickFormatter={shortDate} tick={AXIS} axisLine={false} tickLine={false} minTickGap={24} />
        <YAxis tickFormatter={(v: number) => formatCompact(v)} tick={AXIS} axisLine={false} tickLine={false} width={48} />
        <Tooltip cursor={{ fill: "var(--color-brand-50)" }} content={<ChartTooltip labelFormatter={(l) => format(new Date(l), "d MMMM", { locale: fr })} />} />
        <Bar dataKey="engagements" name="Engagement" fill="var(--chart-2)" radius={[6, 6, 0, 0]} maxBarSize={28} />
      </BarChart>
    </ResponsiveContainer>
  )
}

export function PlatformBarChart({ data }: { data: { name: string; value: number | null }[] }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={data} layout="vertical" margin={{ top: 0, right: 16, left: 8, bottom: 0 }}>
        <CartesianGrid stroke={GRID} strokeDasharray="4 4" horizontal={false} />
        <XAxis type="number" tickFormatter={(v: number) => formatCompact(v)} tick={AXIS} axisLine={false} tickLine={false} />
        <YAxis type="category" dataKey="name" tick={AXIS} axisLine={false} tickLine={false} width={96} />
        <Tooltip cursor={{ fill: "var(--color-brand-50)" }} content={<ChartTooltip />} />
        <Bar dataKey="value" name="Impressions" fill="var(--chart-1)" radius={[0, 6, 6, 0]} maxBarSize={22} />
      </BarChart>
    </ResponsiveContainer>
  )
}

export function FollowersLineChart({ data }: { data: object[] }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <LineChart data={data} margin={{ top: 8, right: 8, left: -4, bottom: 0 }}>
        <CartesianGrid stroke={GRID} strokeDasharray="4 4" vertical={false} />
        <XAxis dataKey="date" tickFormatter={shortDate} tick={AXIS} axisLine={false} tickLine={false} minTickGap={24} />
        <YAxis tickFormatter={(v: number) => formatCompact(v)} tick={AXIS} axisLine={false} tickLine={false} width={52} domain={["dataMin - 500", "dataMax + 200"]} />
        <Tooltip content={<ChartTooltip labelFormatter={(l) => format(new Date(l), "d MMMM", { locale: fr })} />} />
        <Line type="monotone" dataKey="followers" name="Abonnés" stroke="var(--chart-3)" strokeWidth={2.5} dot={false} activeDot={{ r: 5 }} />
      </LineChart>
    </ResponsiveContainer>
  )
}
