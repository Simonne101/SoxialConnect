import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"
import { FeatureUnavailableError } from "@/lib/features"
import * as social from "@/services/socialAccounts"
import { qk } from "./keys"

export function useSocialAccounts() {
  return useQuery({ queryKey: qk.socialAccounts, queryFn: social.listSocialAccounts })
}

function useSocialMutation<TArgs>(fn: (args: TArgs) => Promise<unknown>) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.socialAccounts })
      void qc.invalidateQueries({ queryKey: qk.dashboard })
    },
    // Action pas encore disponible (OAuth) : message explicite, jamais d'échec silencieux.
    onError: (error) => {
      if (error instanceof FeatureUnavailableError) toast.info(error.message, { id: "social-unavailable" })
    },
  })
}

export const useConnectAccount = () => useSocialMutation(social.connectAccount)
export const useDisconnectAccount = () => useSocialMutation(social.disconnectAccount)
export const useSyncAccount = () => useSocialMutation(social.syncAccount)
