import { zodResolver } from "@hookform/resolvers/zod"
import { AlertTriangle, BadgeCheck, KeyRound, LoaderCircle, LogOut, Monitor, ShieldCheck, Smartphone } from "lucide-react"
import { useEffect, useState, type ReactNode } from "react"
import { useForm } from "react-hook-form"
import { Link } from "react-router"
import { toast } from "sonner"
import { z } from "zod"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { ConfirmDialog } from "@/components/shared/ConfirmDialog"
import { Field, PasswordInput } from "@/components/shared/FormField"
import { ListSkeleton } from "@/components/shared/States"
import { StatusBadge } from "@/components/shared/StatusBadge"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import {
  useChangePassword,
  useMe,
  useNotificationPreferences,
  useRevokeSession,
  useSecurityEvents,
  useSessions,
  useUpdateMe,
  useUpdateNotificationPreferences,
} from "@/hooks/queries/misc"
import { useSocialAccounts } from "@/hooks/queries/social"
import { formatDate, formatRelative, initials } from "@/lib/format"
import { PLATFORMS } from "@/lib/platforms"
import { ACCOUNT_STATUS } from "@/lib/status"
import { TIMEZONES } from "@/lib/time"
import type { NotificationPreferences } from "@/types"

export function Panel({ title, description, children, footer }: { title: string; description?: string; children: ReactNode; footer?: ReactNode }) {
  return (
    <section className="surface-card" aria-label={title}>
      <div className="border-b border-border p-5">
        <h2 className="text-base font-semibold">{title}</h2>
        {description && <p className="mt-1 text-sm text-ink-muted">{description}</p>}
      </div>
      <div className="p-5">{children}</div>
      {footer && <div className="flex justify-end gap-2 border-t border-border px-5 py-4">{footer}</div>}
    </section>
  )
}

// --- Profil -------------------------------------------------------------------------------
const profileSchema = z.object({
  name: z.string().trim().min(2, "Indiquez votre nom.").max(120),
  role: z.string().trim().max(60),
  bio: z.string().max(280, "280 caractères maximum."),
})

export function ProfilePanel() {
  const { data: me } = useMe()
  const update = useUpdateMe()
  const form = useForm<z.infer<typeof profileSchema>>({ resolver: zodResolver(profileSchema), defaultValues: { name: "", role: "", bio: "" } })
  const { register, handleSubmit, reset, formState } = form
  useEffect(() => {
    if (me) reset({ name: me.name, role: me.role, bio: me.bio })
  }, [me, reset])

  return (
    <form onSubmit={handleSubmit((v) => update.mutate(v, { onSuccess: () => { toast.success("Profil enregistré."); reset(v) } }))} noValidate>
      <Panel
        title="Profil"
        description="Ces informations sont visibles uniquement par vous."
        footer={
          <Button type="submit" disabled={!formState.isDirty || update.isPending}>
            {update.isPending && <LoaderCircle className="animate-spin" />} Enregistrer
          </Button>
        }
      >
        <div className="flex items-center gap-4">
          <Avatar className="size-16">
            <AvatarFallback className="bg-brand-gradient text-lg font-semibold text-white">{initials(me?.name ?? "")}</AvatarFallback>
          </Avatar>
          <div>
            <Button type="button" variant="outline" size="sm" disabled>Changer la photo</Button>
            <p className="mt-1 text-xs text-ink-muted">JPG ou PNG, 2 Mo maximum (bientôt disponible).</p>
          </div>
        </div>
        <div className="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
          <Field label="Nom complet" error={formState.errors.name?.message}>
            {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} {...register("name")} />}
          </Field>
          <Field label="Activité" hint="Ex. : Créatrice de contenu, Photographe…">
            {({ id, describedBy }) => <Input id={id} aria-describedby={describedBy} {...register("role")} />}
          </Field>
          <Field label="Présentation" error={formState.errors.bio?.message} className="sm:col-span-2">
            {({ id, describedBy, invalid }) => <Textarea id={id} rows={3} aria-describedby={describedBy} aria-invalid={invalid} {...register("bio")} />}
          </Field>
        </div>
      </Panel>
    </form>
  )
}

// --- Compte -------------------------------------------------------------------------------
export function AccountPanel() {
  const { data: me } = useMe()
  const [confirm, setConfirm] = useState(false)
  return (
    <div className="space-y-6">
      <Panel title="Compte" description="Votre adresse e-mail sert à vous connecter et à recevoir les alertes importantes.">
        <div className="space-y-1.5">
          <Label htmlFor="account-email" className="text-[13px] font-semibold">Adresse e-mail</Label>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <Input id="account-email" value={me?.email ?? ""} readOnly className="bg-muted/50 sm:max-w-sm" />
            {me?.emailVerified && (
              <Badge variant="success"><BadgeCheck /> Vérifiée</Badge>
            )}
          </div>
          <p className="text-xs text-ink-muted">Pour changer d'adresse, un lien de confirmation sera envoyé à la nouvelle adresse (disponible avec le backend).</p>
        </div>
      </Panel>
      <Panel title="Zone sensible">
        <div className="flex flex-col gap-4 rounded-xl border border-danger-100 bg-danger-50/50 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="flex items-center gap-2 text-sm font-semibold text-danger-700"><AlertTriangle className="size-4" aria-hidden="true" /> Supprimer mon compte</p>
            <p className="mt-1 text-sm text-ink-soft">Vos réseaux seront déconnectés et vos données supprimées définitivement après 30 jours.</p>
          </div>
          <Button variant="destructive" onClick={() => setConfirm(true)}>Supprimer le compte</Button>
        </div>
      </Panel>
      <ConfirmDialog
        open={confirm}
        onOpenChange={setConfirm}
        title="Supprimer définitivement votre compte ?"
        description="Cette action révoque l'accès à tous vos réseaux et planifie la suppression de vos données. Démonstration : aucune donnée n'est supprimée."
        confirmLabel="Oui, supprimer"
        onConfirm={() => toast.info("Démonstration : la suppression sera disponible avec le backend.")}
      />
    </div>
  )
}

// --- Sécurité -----------------------------------------------------------------------------
const passwordSchema = z
  .object({
    current: z.string().min(1, "Saisissez votre mot de passe actuel."),
    next: z.string().min(12, "Au moins 12 caractères."),
    confirm: z.string(),
  })
  .refine((v) => v.next === v.confirm, { path: ["confirm"], message: "Les mots de passe ne correspondent pas." })
  .refine((v) => v.next !== v.current, { path: ["next"], message: "Choisissez un mot de passe différent de l'actuel." })

export function SecurityPanel() {
  const change = useChangePassword()
  const sessions = useSessions()
  const revoke = useRevokeSession()
  const events = useSecurityEvents()
  const form = useForm<z.infer<typeof passwordSchema>>({ resolver: zodResolver(passwordSchema), defaultValues: { current: "", next: "", confirm: "" } })
  const { register, handleSubmit, reset, formState } = form

  return (
    <div className="space-y-6">
      <form onSubmit={handleSubmit(() => change.mutate(undefined, { onSuccess: () => { toast.success("Mot de passe modifié. Vos autres sessions ont été déconnectées."); reset() } }))} noValidate>
        <Panel
          title="Mot de passe"
          description="Utilisez une phrase longue et unique, que vous n'utilisez sur aucun autre site."
          footer={<Button type="submit" disabled={change.isPending}>{change.isPending && <LoaderCircle className="animate-spin" />} Modifier le mot de passe</Button>}
        >
          <div className="grid gap-5 sm:max-w-md">
            <Field label="Mot de passe actuel" error={formState.errors.current?.message}>
              {({ id, describedBy, invalid }) => <PasswordInput id={id} autoComplete="current-password" aria-describedby={describedBy} aria-invalid={invalid} {...register("current")} />}
            </Field>
            <Field label="Nouveau mot de passe" error={formState.errors.next?.message} hint="Au moins 12 caractères.">
              {({ id, describedBy, invalid }) => <PasswordInput id={id} autoComplete="new-password" aria-describedby={describedBy} aria-invalid={invalid} {...register("next")} />}
            </Field>
            <Field label="Confirmer le nouveau mot de passe" error={formState.errors.confirm?.message}>
              {({ id, describedBy, invalid }) => <PasswordInput id={id} autoComplete="new-password" aria-describedby={describedBy} aria-invalid={invalid} {...register("confirm")} />}
            </Field>
          </div>
        </Panel>
      </form>

      <Panel title="Double authentification (2FA)" description="Ajoutez un code temporaire à votre mot de passe lors de la connexion.">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <p className="flex items-center gap-3 text-sm text-ink-soft">
            <span className="inline-flex size-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600" aria-hidden="true"><KeyRound className="size-5" /></span>
            Application d'authentification (Google Authenticator, Authy…)
          </p>
          <Button variant="outline" disabled>Bientôt disponible</Button>
        </div>
      </Panel>

      <Panel title="Sessions actives" description="Les appareils actuellement connectés à votre compte.">
        {sessions.isLoading ? (
          <ListSkeleton rows={3} />
        ) : (
          <ul className="divide-y divide-border">
            {sessions.data?.map((s) => (
              <li key={s.id} className="flex flex-wrap items-center gap-3 py-3">
                <span className="inline-flex size-10 items-center justify-center rounded-xl bg-muted text-ink-soft" aria-hidden="true">
                  {s.device.includes("iPhone") ? <Smartphone className="size-5" /> : <Monitor className="size-5" />}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-ink">
                    {s.device} {s.current && <Badge variant="success" className="ml-1">Cet appareil</Badge>}
                  </p>
                  <p className="text-xs text-ink-muted">{s.location} · {s.ipMasked} · {s.current ? "Active maintenant" : `Active ${formatRelative(s.lastActiveAt)}`}</p>
                </div>
                {!s.current && (
                  <Button variant="outline" size="sm" disabled={revoke.isPending} onClick={() => revoke.mutate(s.id, { onSuccess: () => toast.success("Session déconnectée.") })}>
                    <LogOut /> Déconnecter
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <Panel title="Journal de sécurité" description="Les derniers événements importants de votre compte.">
        {events.isLoading ? (
          <ListSkeleton rows={4} />
        ) : (
          <ol className="space-y-3">
            {events.data?.map((e) => (
              <li key={e.id} className="flex items-start gap-3 text-sm">
                <ShieldCheck className="mt-0.5 size-4 shrink-0 text-success-500" aria-hidden="true" />
                <div className="flex-1">
                  <p className="font-medium text-ink">{e.label}</p>
                  <p className="text-xs text-ink-muted">{formatDate(e.createdAt, "d MMM yyyy 'à' HH:mm")} · {e.ipMasked}</p>
                </div>
              </li>
            ))}
          </ol>
        )}
      </Panel>
    </div>
  )
}

// --- Notifications ------------------------------------------------------------------------
const PREFS: { key: keyof NotificationPreferences; label: string; description: string }[] = [
  { key: "publishFailureEmail", label: "Échec de publication", description: "Un e-mail si une publication n'a pas pu partir." },
  { key: "accountAlertsEmail", label: "Compte à reconnecter", description: "Un e-mail quand un réseau expire ou perd une permission." },
  { key: "publishSuccessEmail", label: "Publication réussie", description: "Un e-mail à chaque publication en ligne." },
  { key: "videoReadyEmail", label: "Vidéo prête", description: "Un e-mail quand vos clips sont prêts." },
  { key: "weeklyReportEmail", label: "Bilan hebdomadaire", description: "Un résumé de vos statistiques chaque lundi." },
  { key: "inAppAll", label: "Notifications dans l'application", description: "Afficher toutes les notifications dans SocialPulse." },
]

export function NotificationsPanel() {
  const { data } = useNotificationPreferences()
  const update = useUpdateNotificationPreferences()
  return (
    <Panel title="Notifications" description="Choisissez ce qui mérite votre attention.">
      <ul className="divide-y divide-border">
        {PREFS.map((p) => (
          <li key={p.key} className="flex items-center justify-between gap-4 py-3.5">
            <div>
              <Label htmlFor={`pref-${p.key}`} className="text-sm font-semibold text-ink">{p.label}</Label>
              <p className="text-xs text-ink-muted">{p.description}</p>
            </div>
            <Switch
              id={`pref-${p.key}`}
              checked={Boolean(data?.[p.key])}
              disabled={!data}
              onCheckedChange={(c) => update.mutate({ [p.key]: c } as Partial<NotificationPreferences>, { onSuccess: () => toast.success("Préférence enregistrée.") })}
            />
          </li>
        ))}
      </ul>
    </Panel>
  )
}

// --- Comptes connectés --------------------------------------------------------------------
export function ConnectedAccountsPanel() {
  const { data, isLoading } = useSocialAccounts()
  return (
    <Panel title="Comptes connectés" description="Résumé de vos réseaux. Gérez les connexions et permissions depuis la page Réseaux sociaux." footer={<Button asChild><Link to="/social-accounts">Gérer mes réseaux</Link></Button>}>
      {isLoading ? (
        <ListSkeleton rows={5} />
      ) : (
        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          {data?.map((a) => (
            <li key={a.id} className="flex items-center gap-3 rounded-xl border border-border p-3">
              <PlatformIcon platform={a.platform} size="md" />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-ink">{PLATFORMS[a.platform].name}</p>
                <p className="truncate text-xs text-ink-muted">{a.username ?? a.displayName ?? "—"}</p>
              </div>
              <StatusBadge meta={ACCOUNT_STATUS[a.status]} />
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}

// --- Préférences --------------------------------------------------------------------------
export function PreferencesPanel() {
  const { data: me } = useMe()
  const update = useUpdateMe()
  const [weekStart, setWeekStart] = useState("monday")
  return (
    <Panel title="Préférences" description="Adaptez SocialPulse à votre façon de travailler.">
      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="pref-tz" className="text-[13px] font-semibold">Fuseau horaire</Label>
          <Select value={me?.timezone ?? "Europe/Paris"} onValueChange={(v) => update.mutate({ timezone: v }, { onSuccess: () => toast.success("Fuseau horaire enregistré.") })}>
            <SelectTrigger id="pref-tz" className="w-full"><SelectValue /></SelectTrigger>
            <SelectContent>
              {TIMEZONES.map((tz) => (
                <SelectItem key={tz} value={tz}>{tz}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-ink-muted">Utilisé pour programmer vos publications.</p>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="pref-lang" className="text-[13px] font-semibold">Langue</Label>
          <Select value={me?.locale ?? "fr"} onValueChange={() => toast.info("L'anglais arrive bientôt.")}>
            <SelectTrigger id="pref-lang" className="w-full"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="fr">Français</SelectItem>
              <SelectItem value="en" disabled>English (bientôt)</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="pref-week" className="text-[13px] font-semibold">Premier jour de la semaine</Label>
          <Select value={weekStart} onValueChange={setWeekStart}>
            <SelectTrigger id="pref-week" className="w-full"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="monday">Lundi</SelectItem>
              <SelectItem value="sunday">Dimanche</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>
    </Panel>
  )
}
