import { zodResolver } from "@hookform/resolvers/zod"
import { LoaderCircle } from "lucide-react"
import { useForm } from "react-hook-form"
import { Link, useLocation, useNavigate } from "react-router"
import { toast } from "sonner"
import { z } from "zod"
import { BrandGlyph } from "@/components/brand/PlatformIcon"
import { AuthCard } from "@/components/shared/AuthCard"
import { Field, PasswordInput } from "@/components/shared/FormField"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { useLogin } from "@/hooks/queries/auth"
import { ApiError } from "@/lib/http"

const schema = z.object({
  email: z.email("Saisissez une adresse e-mail valide."),
  password: z.string().min(1, "Saisissez votre mot de passe."),
  remember: z.boolean(),
})
type Values = z.infer<typeof schema>

export default function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const login = useLogin()
  const {
    register,
    handleSubmit,
    setValue,
    setError,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: "", password: "", remember: true } })

  // Connexion réelle (Laravel Fortify + session Sanctum) si le backend est branché, sinon démonstration.
  const onSubmit = async (values: Values) => {
    try {
      await login.mutateAsync(values)
      toast.success("Bon retour parmi nous !")
      const from = (location.state as { from?: string } | null)?.from
      navigate(from && from.startsWith("/") ? from : "/dashboard", { replace: true })
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        setError("email", { message: e.field("email") ?? e.message })
        if (e.field("password")) setError("password", { message: e.field("password") })
      } else {
        setError("root", { message: e instanceof ApiError ? e.message : "La connexion a échoué. Réessayez." })
      }
    }
  }

  return (
    <AuthCard
      title="Connexion"
      subtitle="Bon retour ! Connectez-vous à votre compte."
      footer={
        <>
          Vous n'avez pas de compte ?{" "}
          <Link to="/register" className="font-semibold text-brand-600 hover:underline">
            S'inscrire
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4 short:space-y-3">
        <Field label="Adresse e-mail" error={errors.email?.message}>
          {({ id, describedBy, invalid }) => (
            <Input id={id} type="email" autoComplete="email" placeholder="votre@email.com" aria-describedby={describedBy} aria-invalid={invalid} {...register("email")} />
          )}
        </Field>
        <Field label="Mot de passe" error={errors.password?.message}>
          {({ id, describedBy, invalid }) => (
            <PasswordInput id={id} autoComplete="current-password" placeholder="Votre mot de passe" aria-describedby={describedBy} aria-invalid={invalid} {...register("password")} />
          )}
        </Field>
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <Checkbox id="remember" checked={watch("remember")} onCheckedChange={(v) => setValue("remember", v === true)} />
            <Label htmlFor="remember" className="text-sm font-normal text-ink-soft">
              Se souvenir de moi
            </Label>
          </div>
          <Link to="/forgot-password" className="text-sm font-semibold text-brand-600 hover:underline">
            Mot de passe oublié ?
          </Link>
        </div>
        {errors.root && (
          <p role="alert" className="rounded-lg bg-danger-50 px-3 py-2 text-sm font-medium text-danger-700">
            {errors.root.message}
          </p>
        )}
        <Button type="submit" size="lg" className="w-full" disabled={isSubmitting}>
          {isSubmitting && <LoaderCircle className="animate-spin" />}
          {isSubmitting ? "Connexion…" : "Se connecter"}
        </Button>
      </form>

      {/* Maquette : connexion via Google / Facebook — hors V1 (ADR-016), affichée désactivée. */}
      <div className="mt-5 flex items-center gap-3 text-xs text-ink-muted short:mt-3">
        <span className="h-px flex-1 bg-border" /> Ou continuer avec <span className="h-px flex-1 bg-border" />
      </div>
      <div className="mt-4 grid grid-cols-2 gap-3 short:mt-3">
        {(
          [
            ["google", "Google", "text-google"],
            ["facebook", "Facebook", "text-facebook"],
          ] as const
        ).map(([key, name, color]) => (
          <Tooltip key={key}>
            <TooltipTrigger asChild>
              <span tabIndex={0} className="rounded-lg">
                <Button variant="outline" className="pointer-events-none w-full" disabled aria-label={`Continuer avec ${name} (bientôt disponible)`}>
                  <BrandGlyph name={key} className={`size-[18px] ${color}`} /> {name}
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>Bientôt disponible</TooltipContent>
          </Tooltip>
        ))}
      </div>
    </AuthCard>
  )
}
