import { useQueryClient } from "@tanstack/react-query"
import { KeyRound, LoaderCircle, ShieldCheck } from "lucide-react"
import { useState, type FormEvent } from "react"
import { toast } from "sonner"
import { ConfirmDialog } from "@/components/shared/ConfirmDialog"
import { PasswordInput } from "@/components/shared/FormField"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useMe } from "@/hooks/queries/misc"
import { qk } from "@/hooks/queries/keys"
import { ApiError, apiErrorMessage, isApiEnabled } from "@/lib/http"
import * as auth from "@/services/auth"
import { Panel } from "./SettingsPanels"

type Step = { kind: "idle" } | { kind: "setup"; svg: string; secretKey: string } | { kind: "codes"; codes: string[] }

/**
 * Double authentification TOTP (étape 8, lot 5). Chaque action passe par une confirmation récente du mot de passe :
 * le serveur répond 423 tant qu'elle manque, la fenêtre de saisie s'ouvre alors puis l'action est rejouée.
 */
export function TwoFactorPanel() {
  const { data: me } = useMe()
  const qc = useQueryClient()
  const [step, setStep] = useState<Step>({ kind: "idle" })
  const [busy, setBusy] = useState(false)
  const [pending, setPending] = useState<(() => Promise<void>) | null>(null)
  const [disableOpen, setDisableOpen] = useState(false)
  const [code, setCode] = useState("")
  const [codeError, setCodeError] = useState<string | null>(null)

  const refreshMe = () => qc.invalidateQueries({ queryKey: qk.me })

  // Exécute l'action ; si le mot de passe doit être confirmé (423), la met en attente derrière la fenêtre de confirmation.
  const guarded = async (action: () => Promise<void>) => {
    setBusy(true)
    try {
      await action()
    } catch (e) {
      if (e instanceof ApiError && e.status === auth.PASSWORD_CONFIRMATION_REQUIRED) setPending(() => action)
      else toast.error(apiErrorMessage(e, "L'opération a échoué. Réessayez."))
    } finally {
      setBusy(false)
    }
  }

  const start = () =>
    guarded(async () => {
      await auth.enableTwoFactor()
      setStep({ kind: "setup", ...(await auth.twoFactorSetup()) })
    })

  const confirm = async (e: FormEvent) => {
    e.preventDefault()
    setCodeError(null)
    await guarded(async () => {
      try {
        await auth.confirmTwoFactor(code.replace(/\s/g, ""))
      } catch (err) {
        if (err instanceof ApiError && err.status === 422) {
          setCodeError("Code invalide. Vérifiez l'heure de votre téléphone et réessayez.")
          return
        }
        throw err
      }
      setCode("")
      setStep({ kind: "codes", codes: await auth.recoveryCodes() })
      toast.success("Double authentification activée.")
      await refreshMe()
    })
  }

  const showCodes = () => guarded(async () => setStep({ kind: "codes", codes: await auth.recoveryCodes() }))
  const regenerate = () => guarded(async () => setStep({ kind: "codes", codes: await auth.regenerateRecoveryCodes() }))
  const disable = () =>
    guarded(async () => {
      await auth.disableTwoFactor()
      setStep({ kind: "idle" })
      toast.success("Double authentification désactivée.")
      await refreshMe()
    })

  const enabled = me?.twoFactorEnabled === true

  return (
    <>
      <Panel title="Double authentification (2FA)" description="Ajoutez un code temporaire à votre mot de passe lors de la connexion.">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <p className="flex items-center gap-3 text-sm text-ink-soft">
            <span className="inline-flex size-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600" aria-hidden="true"><KeyRound className="size-5" /></span>
            Application d'authentification (Google Authenticator, Authy…)
            {enabled && <Badge variant="success"><ShieldCheck /> Activée</Badge>}
          </p>
          {!isApiEnabled ? (
            <Button variant="outline" disabled>Disponible avec le backend</Button>
          ) : enabled ? (
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" disabled={busy} onClick={showCodes}>Codes de secours</Button>
              <Button variant="outline" disabled={busy} onClick={() => setDisableOpen(true)}>Désactiver</Button>
            </div>
          ) : step.kind === "idle" ? (
            <Button disabled={busy} onClick={start}>{busy && <LoaderCircle className="animate-spin" />} Activer</Button>
          ) : null}
        </div>

        {step.kind === "setup" && (
          <form onSubmit={confirm} className="mt-6 grid gap-5 sm:grid-cols-[auto_1fr]" noValidate>
            {/* Le SVG est affiché comme une image (jamais injecté dans la page). */}
            <img src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(step.svg)}`} alt="QR code à scanner avec votre application" className="size-44 rounded-xl border border-border bg-white p-2" />
            <div className="space-y-3">
              <p className="text-sm text-ink-soft">1. Scannez ce QR code avec votre application, ou saisissez la clé :</p>
              <code className="block break-all rounded-lg bg-muted px-3 py-2 text-sm">{step.secretKey}</code>
              <Label htmlFor="twofa-code" className="text-[13px] font-semibold">2. Code affiché par l'application</Label>
              <Input id="twofa-code" value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" autoComplete="one-time-code" aria-invalid={codeError !== null} className="sm:max-w-40" />
              {codeError && <p role="alert" className="text-sm text-danger-700">{codeError}</p>}
              <div className="flex gap-2">
                <Button type="submit" disabled={busy || code.trim() === ""}>{busy && <LoaderCircle className="animate-spin" />} Confirmer</Button>
                <Button type="button" variant="ghost" onClick={() => guarded(async () => { await auth.disableTwoFactor(); setStep({ kind: "idle" }) })}>Annuler</Button>
              </div>
            </div>
          </form>
        )}

        {step.kind === "codes" && (
          <div className="mt-6 space-y-3">
            <p className="text-sm text-ink-soft">Codes de secours : chacun ne sert qu'une fois si vous perdez votre téléphone. Conservez-les en lieu sûr.</p>
            <ul className="grid grid-cols-1 gap-2 font-mono text-sm sm:grid-cols-2">
              {step.codes.map((c) => <li key={c} className="rounded-lg bg-muted px-3 py-2">{c}</li>)}
            </ul>
            <div className="flex gap-2">
              <Button variant="outline" disabled={busy} onClick={regenerate}>Générer de nouveaux codes</Button>
              <Button variant="ghost" onClick={() => setStep({ kind: "idle" })}>Masquer</Button>
            </div>
          </div>
        )}
      </Panel>

      <PasswordConfirmDialog
        open={pending !== null}
        onCancel={() => setPending(null)}
        onConfirmed={() => {
          const action = pending
          setPending(null)
          if (action) void guarded(action)
        }}
      />
      <ConfirmDialog
        open={disableOpen}
        onOpenChange={setDisableOpen}
        title="Désactiver la double authentification ?"
        description="Votre compte ne sera plus protégé que par votre mot de passe."
        confirmLabel="Désactiver"
        onConfirm={disable}
      />
    </>
  )
}

function PasswordConfirmDialog({ open, onCancel, onConfirmed }: { open: boolean; onCancel: () => void; onConfirmed: () => void }) {
  const [password, setPassword] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await auth.confirmPassword(password)
      setPassword("")
      onConfirmed()
    } catch (err) {
      setError(err instanceof ApiError && err.status === 422 ? "Mot de passe incorrect." : apiErrorMessage(err, "La confirmation a échoué."))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) { setPassword(""); setError(null); onCancel() } }}>
      <DialogContent>
        <form onSubmit={submit} noValidate>
          <DialogHeader>
            <DialogTitle>Confirmez votre mot de passe</DialogTitle>
            <DialogDescription>Cette action touche à la sécurité de votre compte.</DialogDescription>
          </DialogHeader>
          <div className="my-4 space-y-1.5">
            <Label htmlFor="confirm-password" className="text-[13px] font-semibold">Mot de passe</Label>
            <PasswordInput id="confirm-password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} aria-invalid={error !== null} />
            {error && <p role="alert" className="text-sm text-danger-700">{error}</p>}
          </div>
          <DialogFooter>
            <Button type="submit" disabled={busy || password === ""}>{busy && <LoaderCircle className="animate-spin" />} Confirmer</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
