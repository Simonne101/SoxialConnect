import { type ReactNode, useState } from "react"
import { Navigate } from "react-router"
import { PageLoader } from "@/components/shared/PageLoader"
import { useMe } from "@/hooks/queries/misc"
import { isApiEnabled } from "@/lib/http"

/**
 * Pages réservées aux visiteurs non connectés (connexion, inscription, mot de passe oublié) :
 * une session déjà ouverte renvoie vers le tableau de bord, comme le fait l'API (409 « déjà
 * connecté »). La décision est prise une seule fois, au chargement : elle ne perturbe pas la
 * navigation faite par les formulaires après une connexion ou une inscription réussie.
 * Pendant de RequireAuth ; en mode démonstration (sans backend), accès libre.
 */
export function GuestOnly({ children }: { children: ReactNode }) {
  const { data, isLoading } = useMe()
  // Résultat du premier chargement, conservé ensuite (mise à jour d'état pendant le rendu,
  // schéma recommandé par React pour mémoriser une valeur issue d'un rendu précédent).
  const [decision, setDecision] = useState<"guest" | "user" | null>(null)
  if (decision === null && !isLoading) setDecision(data ? "user" : "guest")

  if (!isApiEnabled) return children
  if (decision === null) return <PageLoader label="Vérification de votre session…" />
  if (decision === "user") return <Navigate to="/dashboard" replace />
  return children
}
