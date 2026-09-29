import { zodResolver } from "@hookform/resolvers/zod"
import { LoaderCircle } from "lucide-react"
import { useForm } from "react-hook-form"
import { Link, useNavigate } from "react-router"
import { toast } from "sonner"
import { z } from "zod"
import { AuthCard } from "@/components/shared/AuthCard"
import { Field, PasswordInput } from "@/components/shared/FormField"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"

// Politique de mot de passe : ≥ 12 caractères (docs/security.md §3). Vérification HIBP côté serveur.
const schema = z
  .object({
    name: z.string().trim().min(2, "Indiquez votre nom complet.").max(120),
    email: z.email("Saisissez une adresse e-mail valide."),
    password: z.string().min(12, "Au moins 12 caractères."),
    confirm: z.string(),
  })
  .refine((v) => v.password === v.confirm, { path: ["confirm"], message: "Les mots de passe ne correspondent pas." })
type Values = z.infer<typeof schema>

export default function RegisterPage() {
  const navigate = useNavigate()
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { name: "", email: "", password: "", confirm: "" } })

  const onSubmit = async () => {
    await new Promise((r) => setTimeout(r, 800))
    toast.success("Compte créé ! Un e-mail de vérification vous a été envoyé.")
    navigate("/onboarding")
  }

  return (
    <AuthCard
      wide
      title="Créer un compte"
      subtitle="Rejoignez la communauté SocialPulse."
      footer={
        <>
          Déjà un compte ?{" "}
          <Link to="/login" className="font-semibold text-brand-600 hover:underline">
            Se connecter
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4 short:space-y-3 tiny:space-y-2.5">
        {/* Deux colonnes dès que la largeur le permet : l'inscription tient dans la hauteur de l'écran. */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 short:gap-3 tiny:gap-2.5">
          <Field label="Nom complet" error={errors.name?.message}>
            {({ id, describedBy, invalid }) => (
              <Input id={id} autoComplete="name" placeholder="Votre nom complet" aria-describedby={describedBy} aria-invalid={invalid} {...register("name")} />
            )}
          </Field>
          <Field label="Email" error={errors.email?.message}>
            {({ id, describedBy, invalid }) => (
              <Input id={id} type="email" autoComplete="email" placeholder="votre@email.com" aria-describedby={describedBy} aria-invalid={invalid} {...register("email")} />
            )}
          </Field>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:items-start short:gap-3 tiny:gap-2.5">
          <Field label="Mot de passe" error={errors.password?.message} hint="Au moins 12 caractères.">
            {({ id, describedBy, invalid }) => (
              <PasswordInput id={id} autoComplete="new-password" placeholder="Votre mot de passe" aria-describedby={describedBy} aria-invalid={invalid} {...register("password")} />
            )}
          </Field>
          <Field label="Confirmer le mot de passe" error={errors.confirm?.message}>
            {({ id, describedBy, invalid }) => (
              <PasswordInput id={id} autoComplete="new-password" placeholder="Confirmez-le" aria-describedby={describedBy} aria-invalid={invalid} {...register("confirm")} />
            )}
          </Field>
        </div>
        <Button type="submit" size="lg" className="w-full" disabled={isSubmitting}>
          {isSubmitting && <LoaderCircle className="animate-spin" />}
          {isSubmitting ? "Création du compte…" : "Créer mon compte"}
        </Button>
        <p className="text-center text-xs text-ink-muted">
          En créant un compte, vous acceptez les{" "}
          <Link to="/legal/terms" className="underline hover:text-ink">conditions d'utilisation</Link> et la{" "}
          <Link to="/legal/privacy" className="underline hover:text-ink">politique de confidentialité</Link>.
        </p>
      </form>
    </AuthCard>
  )
}
