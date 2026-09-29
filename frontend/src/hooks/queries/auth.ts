import { useMutation, useQueryClient } from "@tanstack/react-query"
import * as auth from "@/services/auth"
import { qk } from "./keys"

/** Connexion : l'utilisateur renvoyé devient l'utilisateur courant (cache « me »). */
export function useLogin() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: auth.login,
    onSuccess: (user) => {
      qc.clear()
      qc.setQueryData(qk.me, user)
    },
  })
}

export function useRegister() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: auth.register,
    onSuccess: (user) => {
      qc.clear()
      qc.setQueryData(qk.me, user)
    },
  })
}

/** Déconnexion : toutes les données en cache sont effacées (aucune fuite vers la session suivante). */
export function useLogout() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: auth.logout,
    onSettled: () => qc.clear(),
  })
}

export function useForgotPassword() {
  return useMutation({ mutationFn: auth.forgotPassword })
}
