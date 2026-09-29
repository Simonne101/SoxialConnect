// Types du domaine AI — alignés sur docs/database.md (ai_generations).
import type { PlatformKey } from "./social"

export type AITask =
  | "caption"
  | "post"
  | "rephrase"
  | "hashtags"
  | "ideas"
  | "script"
  | "tone"

export type AITone = "friendly" | "professional" | "inspiring" | "fun" | "educational"

export interface AIMessage {
  id: string
  role: "user" | "assistant"
  content: string
  task: AITask | null
  createdAt: string
  /** Propositions structurées (hashtags, idées…) affichées sous forme de liste. */
  items?: string[]
}

export interface AIConversation {
  id: string
  title: string
  task: AITask
  platform: PlatformKey | null
  tone: AITone
  messages: AIMessage[]
  updatedAt: string
}

export interface AIPromptInput {
  conversationId: string | null
  prompt: string
  task: AITask
  tone: AITone
  platform: PlatformKey | null
}
