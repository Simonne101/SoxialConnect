import { useEffect, useState } from "react"
import { useLocation, useNavigate } from "react-router"
import type { Post } from "@/types"

/**
 * Publication à ouvrir à l'arrivée sur la page (state.openPost, envoyé par le Composer après
 * confirmation) : son détail montre l'état de chaque réseau. L'état de navigation est ensuite effacé
 * pour qu'un rechargement ou un retour arrière ne rouvre pas le détail.
 */
export function useOpenedPost(): Post | null {
  const location = useLocation()
  const navigate = useNavigate()
  const [opened] = useState<Post | null>(() => (location.state as { openPost?: Post } | null)?.openPost ?? null)
  const hasState = Boolean((location.state as { openPost?: Post } | null)?.openPost)

  useEffect(() => {
    if (hasState) navigate(`${location.pathname}${location.search}`, { replace: true, state: null })
  }, [hasState, navigate, location.pathname, location.search])

  return opened
}
