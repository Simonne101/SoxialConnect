import { Eye, EyeOff } from "lucide-react"
import { forwardRef, useId, useState, type ComponentProps, type ReactNode } from "react"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { cn } from "@/lib/utils"

interface FieldProps {
  label: string
  error?: string
  hint?: ReactNode
  children: (ids: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode
  className?: string
  labelAction?: ReactNode
}

/** Champ accessible : label relié, message d'aide et d'erreur annoncés (aria-describedby). */
export function Field({ label, error, hint, children, className, labelAction }: FieldProps) {
  const id = useId()
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  const describedBy = [hintId, errorId].filter(Boolean).join(" ") || undefined
  return (
    <div className={cn("space-y-1.5", className)}>
      <div className="flex items-center justify-between gap-2">
        <Label htmlFor={id} className="text-[13px] font-semibold text-ink">
          {label}
        </Label>
        {labelAction}
      </div>
      {children({ id, describedBy, invalid: Boolean(error) })}
      {hint && !error && (
        <p id={hintId} className="text-xs text-ink-muted">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} role="alert" className="text-xs font-medium text-danger-700">
          {error}
        </p>
      )}
    </div>
  )
}

/** Mot de passe avec bouton « œil » (maquette). */
export const PasswordInput = forwardRef<HTMLInputElement, ComponentProps<"input">>(function PasswordInput(
  { className, ...props },
  ref,
) {
  const [visible, setVisible] = useState(false)
  return (
    <div className="relative">
      <Input ref={ref} type={visible ? "text" : "password"} className={cn("pr-11", className)} {...props} />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-ink-muted hover:text-ink"
        aria-label={visible ? "Masquer le mot de passe" : "Afficher le mot de passe"}
        aria-pressed={visible}
      >
        {visible ? <EyeOff className="size-[18px]" /> : <Eye className="size-[18px]" />}
      </button>
    </div>
  )
})
