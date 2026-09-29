import { zodResolver } from "@hookform/resolvers/zod"
import { LoaderCircle, MailCheck } from "lucide-react"
import { useState } from "react"
import { useForm } from "react-hook-form"
import { Link } from "react-router"
import { z } from "zod"
import { AuthCard } from "@/components/shared/AuthCard"
import { Field } from "@/components/shared/FormField"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"

const schema = z.object({ email: z.email("Saisissez une adresse e-mail valide.") })
type Values = z.infer<typeof schema>

export default function ForgotPasswordPage() {
  const [sent, setSent] = useState(false)
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: "" } })

  const onSubmit = async () => {
    await new Promise((r) => setTimeout(r, 700))
    setSent(true)
  }

  return (
    <AuthCard
      title="Mot de passe oublié"
      subtitle="Recevez un lien pour choisir un nouveau mot de passe."
      footer={
        <Link to="/login" className="font-semibold text-brand-600 hover:underline">
          Retour à la connexion
        </Link>
      }
    >
      {sent ? (
        // Message identique que le compte existe ou non (pas d'énumération d'e-mails).
        <div role="status" className="flex flex-col items-center gap-3 rounded-2xl bg-success-50 px-5 py-6 text-center">
          <MailCheck className="size-8 text-success-700" aria-hidden="true" />
          <p className="text-sm text-ink-soft">
            Si un compte correspond à cette adresse, vous allez recevoir un e-mail avec un lien de réinitialisation.
          </p>
        </div>
      ) : (
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4 short:space-y-3">
          <Field label="Adresse e-mail" error={errors.email?.message}>
            {({ id, describedBy, invalid }) => (
              <Input id={id} type="email" autoComplete="email" placeholder="votre@email.com" aria-describedby={describedBy} aria-invalid={invalid} {...register("email")} />
            )}
          </Field>
          <Button type="submit" size="lg" className="w-full" disabled={isSubmitting}>
            {isSubmitting && <LoaderCircle className="animate-spin" />}
            Envoyer le lien
          </Button>
        </form>
      )}
    </AuthCard>
  )
}
