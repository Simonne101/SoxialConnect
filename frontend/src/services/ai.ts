// Service AI — futur : /api/v1/ai/generations (AIServiceInterface côté Laravel, fournisseur interchangeable).
// Ici : réponses simulées, aucune donnée n'est envoyée à un fournisseur IA.
import type { AIConversation, AIMessage, AIPromptInput } from "@/types"
import { mockAIResponse } from "@/mocks/ai"
import { uid } from "@/mocks/helpers"
import { delay, notFound, store } from "./mockStore"

export async function listConversations(): Promise<AIConversation[]> {
  await delay(200)
  return structuredClone(store.conversations).sort((a, b) => new Date(b.updatedAt).getTime() - new Date(a.updatedAt).getTime())
}

export async function sendPrompt(input: AIPromptInput): Promise<AIConversation> {
  const now = new Date().toISOString()
  let conv = input.conversationId ? store.conversations.find((c) => c.id === input.conversationId) : undefined
  if (input.conversationId && !conv) notFound("Conversation")
  if (!conv) {
    conv = {
      id: uid("conv"),
      title: input.prompt.slice(0, 40) || "Nouvelle conversation",
      task: input.task,
      platform: input.platform,
      tone: input.tone,
      messages: [],
      updatedAt: now,
    }
    store.conversations.unshift(conv)
  }
  const userMessage: AIMessage = { id: uid("msg"), role: "user", content: input.prompt, task: input.task, createdAt: now }
  conv.messages.push(userMessage)
  await delay(1100)
  const response = mockAIResponse(input)
  conv.messages.push({
    id: uid("msg"),
    role: "assistant",
    content: response.content,
    items: response.items,
    task: input.task,
    createdAt: new Date().toISOString(),
  })
  conv.task = input.task
  conv.tone = input.tone
  conv.platform = input.platform
  conv.updatedAt = new Date().toISOString()
  return structuredClone(conv)
}

export async function deleteConversation(id: string): Promise<void> {
  await delay(150)
  store.conversations = store.conversations.filter((c) => c.id !== id)
}
