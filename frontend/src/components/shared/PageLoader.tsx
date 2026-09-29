import { LoaderCircle } from "lucide-react"

export function PageLoader({ label = "Chargement de la page…" }: { label?: string }) {
  return (
    <div className="flex min-h-[40vh] items-center justify-center" role="status" aria-live="polite">
      <LoaderCircle className="size-7 animate-spin text-brand-500" aria-hidden="true" />
      <span className="sr-only">{label}</span>
    </div>
  )
}
