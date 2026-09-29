import { Check } from "lucide-react"
import { Link } from "react-router"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { PLATFORM_ORDER, PLATFORMS } from "@/lib/platforms"
import { cn } from "@/lib/utils"
import type { PlatformKey, SocialAccount } from "@/types"

/** Sélection des réseaux (maquette : rangée d'icônes « Sélectionner les réseaux »). */
export function NetworkPicker({
  selected,
  accounts,
  onToggle,
}: {
  selected: PlatformKey[]
  accounts: SocialAccount[]
  onToggle: (p: PlatformKey) => void
}) {
  return (
    <div>
      <ul className="flex flex-wrap gap-2.5" aria-label="Réseaux sociaux">
        {PLATFORM_ORDER.map((p) => {
          const account = accounts.find((a) => a.platform === p)
          const cfg = PLATFORMS[p]
          const unavailable = cfg.availability !== "available" || !account || account.status === "disconnected" || account.status === "coming_soon"
          const warning = account && (account.status === "expired" || account.status === "permission_required" || account.status === "error")
          const isSelected = selected.includes(p)
          const reason = cfg.availability === "coming_soon" ? "Bientôt disponible" : unavailable ? "Non connecté" : warning ? "À reconnecter" : isSelected ? "Sélectionné" : "Cliquer pour sélectionner"
          return (
            <li key={p}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    disabled={unavailable}
                    aria-pressed={isSelected}
                    aria-label={`${cfg.name} — ${reason}`}
                    onClick={() => onToggle(p)}
                    className={cn(
                      "relative inline-flex rounded-full p-0.5 transition-all outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                      isSelected ? "ring-2 ring-brand-500 ring-offset-2" : "hover:scale-105",
                      unavailable && "cursor-not-allowed opacity-35 grayscale",
                    )}
                  >
                    <PlatformIcon platform={p} size="md" />
                    {isSelected && (
                      <span className="absolute -right-1 -bottom-1 inline-flex size-4 items-center justify-center rounded-full bg-brand-500 text-white ring-2 ring-card" aria-hidden="true">
                        <Check className="size-2.5" strokeWidth={3} />
                      </span>
                    )}
                    {warning && !isSelected && (
                      <span className="absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-warning-500 ring-2 ring-card" aria-hidden="true" />
                    )}
                  </button>
                </TooltipTrigger>
                <TooltipContent>
                  {cfg.name} · {reason}
                </TooltipContent>
              </Tooltip>
            </li>
          )
        })}
      </ul>
      <p className="mt-3 text-sm text-ink-muted">
        {selected.length === 0 ? "Sélectionner les réseaux" : `${selected.length} réseau${selected.length > 1 ? "x" : ""} sélectionné${selected.length > 1 ? "s" : ""}`}
        {" · "}
        <Link to="/social-accounts" className="font-medium text-brand-600 hover:underline">
          Gérer mes réseaux
        </Link>
      </p>
    </div>
  )
}
