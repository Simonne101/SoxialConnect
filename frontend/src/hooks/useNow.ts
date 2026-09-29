import { useEffect, useState } from "react"

/**
 * Horloge figée au montage puis rafraîchie périodiquement : évite d'appeler Date.now()
 * pendant le rendu (rendu pur) tout en gardant les indications « à venir / passé » à jour.
 */
export function useNow(refreshMs = 60_000): number {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), refreshMs)
    return () => clearInterval(t)
  }, [refreshMs])
  return now
}
