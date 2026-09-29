import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import * as ai from "@/services/ai"
import { qk } from "./keys"

export const useConversations = () => useQuery({ queryKey: qk.conversations, queryFn: ai.listConversations })

export function useSendPrompt() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ai.sendPrompt,
    onSuccess: () => void qc.invalidateQueries({ queryKey: qk.conversations }),
  })
}

export function useDeleteConversation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ai.deleteConversation,
    onSuccess: () => void qc.invalidateQueries({ queryKey: qk.conversations }),
  })
}
