import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
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
  })
}

export const useConnectAccount = () => useSocialMutation(social.connectAccount)
export const useDisconnectAccount = () => useSocialMutation(social.disconnectAccount)
export const useSyncAccount = () => useSocialMutation(social.syncAccount)
