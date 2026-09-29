import { useEffect } from "react"

/** Titre d'onglet par page (accessibilité : annonce du changement de page). */
export function useDocumentTitle(title: string) {
  useEffect(() => {
    document.title = title ? `${title} · SoxialConnect` : "SoxialConnect"
  }, [title])
}
