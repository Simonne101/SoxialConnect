import { Bot, Check, Copy, History, MessageSquarePlus, RotateCw, SendHorizontal, Sparkles, SquarePen, Trash2 } from "lucide-react"
import { useEffect, useRef, useState, type KeyboardEvent } from "react"
import { useNavigate } from "react-router"
import { toast } from "sonner"
import { PlatformIcon } from "@/components/brand/PlatformIcon"
import { PageHeader } from "@/components/shared/PageHeader"
import { DemoNotice, ListSkeleton } from "@/components/shared/States"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Sheet, SheetContent, SheetDescription, SheetTitle } from "@/components/ui/sheet"
import { Textarea } from "@/components/ui/textarea"
import { useConversations, useDeleteConversation, useSendPrompt } from "@/hooks/queries/ai"
import { AI_TASKS, AI_TONES } from "@/mocks/ai"
import { formatRelative } from "@/lib/format"
import { PLATFORM_ORDER, PLATFORMS } from "@/lib/platforms"
import { cn } from "@/lib/utils"
import type { AIConversation, AIMessage, AITask, AITone, PlatformKey } from "@/types"

function HistoryList({ conversations, activeId, onSelect, onNew, onDelete }: { conversations: AIConversation[]; activeId: string | null; onSelect: (id: string) => void; onNew: () => void; onDelete: (id: string) => void }) {
  return (
    <div className="flex h-full flex-col">
      <Button onClick={onNew} className="w-full">
        <MessageSquarePlus /> Nouvelle conversation
      </Button>
      <h2 className="mt-5 mb-2 px-1 text-xs font-semibold tracking-wide text-ink-muted uppercase">Historique</h2>
      {conversations.length === 0 ? (
        <p className="px-1 text-sm text-ink-muted">Vos conversations apparaîtront ici.</p>
      ) : (
        <ul className="space-y-1 overflow-y-auto">
          {conversations.map((c) => (
            <li key={c.id} className="group flex items-center gap-1">
              <button type="button" onClick={() => onSelect(c.id)} aria-current={activeId === c.id ? "true" : undefined} className={cn("min-w-0 flex-1 rounded-lg px-3 py-2 text-left", activeId === c.id ? "bg-brand-50" : "hover:bg-muted")}>
                <span className={cn("block truncate text-sm font-medium", activeId === c.id ? "text-brand-700" : "text-ink")}>{c.title}</span>
                <span className="block text-xs text-ink-muted">{AI_TASKS.find((t) => t.task === c.task)?.label ?? "Conversation"} · {formatRelative(c.updatedAt)}</span>
              </button>
              <Button variant="ghost" size="icon-xs" className="opacity-0 group-hover:opacity-100 focus:opacity-100" onClick={() => onDelete(c.id)} aria-label={`Supprimer « ${c.title} »`}>
                <Trash2 />
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function MessageBubble({ message, onUse, onRegenerate }: { message: AIMessage; onUse: (text: string) => void; onRegenerate?: () => void }) {
  const [copied, setCopied] = useState(false)
  const isUser = message.role === "user"
  const fullText = message.items?.length ? `${message.content}\n${message.items.join(message.task === "hashtags" ? " " : "\n")}` : message.content
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(message.task === "hashtags" && message.items ? message.items.join(" ") : fullText)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch {
      toast.error("Copie impossible dans ce navigateur.")
    }
  }
  return (
    <li className={cn("flex gap-3", isUser && "flex-row-reverse")}>
      <Avatar className="size-8 shrink-0">
        <AvatarFallback className={cn("text-xs font-semibold", isUser ? "bg-brand-gradient text-white" : "bg-brand-50 text-brand-600")}>
          {isUser ? "MD" : <Bot className="size-4" aria-hidden="true" />}
        </AvatarFallback>
      </Avatar>
      <div className={cn("max-w-[85%] space-y-2", isUser && "items-end text-right")}>
        <div className={cn("rounded-2xl px-4 py-3 text-sm leading-relaxed whitespace-pre-line", isUser ? "rounded-tr-md bg-brand-500 text-left text-white" : "rounded-tl-md border border-border bg-card text-ink-soft shadow-card")}>
          <span className="sr-only">{isUser ? "Vous : " : "Assistant : "}</span>
          {message.content}
          {message.items && message.task === "hashtags" && (
            <span className="mt-3 flex flex-wrap gap-1.5">
              {message.items.map((h) => (
                <span key={h} className="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700">{h}</span>
              ))}
            </span>
          )}
          {message.items && message.task !== "hashtags" && (
            <ol className="mt-3 space-y-2">
              {message.items.map((item, i) => (
                <li key={i} className="flex items-start gap-2 rounded-lg bg-page px-3 py-2">
                  <span className="text-xs font-bold text-brand-600">{i + 1}.</span>
                  <span className="flex-1">{item}</span>
                  <button type="button" onClick={() => onUse(item)} className="shrink-0 text-xs font-semibold text-brand-600 hover:underline">Utiliser</button>
                </li>
              ))}
            </ol>
          )}
        </div>
        {!isUser && (
          <div className="flex flex-wrap gap-1">
            <Button variant="ghost" size="xs" onClick={copy}>{copied ? <Check /> : <Copy />} {copied ? "Copié" : "Copier"}</Button>
            <Button variant="ghost" size="xs" onClick={() => onUse(message.task === "hashtags" && message.items ? message.items.join(" ") : message.content)}>
              <SquarePen /> Utiliser dans une publication
            </Button>
            {onRegenerate && <Button variant="ghost" size="xs" onClick={onRegenerate}><RotateCw /> Régénérer</Button>}
          </div>
        )}
      </div>
    </li>
  )
}

export default function AIAssistantPage() {
  const navigate = useNavigate()
  const { data: conversations = [], isLoading } = useConversations()
  const send = useSendPrompt()
  const del = useDeleteConversation()
  const [activeId, setActiveId] = useState<string | null>(null)
  const [task, setTask] = useState<AITask>("caption")
  const [tone, setTone] = useState<AITone>("friendly")
  const [platform, setPlatform] = useState<PlatformKey | "none">("instagram")
  const [prompt, setPrompt] = useState("")
  const [pendingPrompt, setPendingPrompt] = useState<string | null>(null)
  const [historyOpen, setHistoryOpen] = useState(false)
  const endRef = useRef<HTMLDivElement>(null)

  const active = conversations.find((c) => c.id === activeId) ?? null
  const messages = active?.messages ?? []

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth", block: "end" })
  }, [messages.length, pendingPrompt])

  const submit = (text = prompt, t = task) => {
    const value = text.trim()
    if (!value || send.isPending) return
    setPendingPrompt(value)
    setPrompt("")
    send.mutate(
      { conversationId: activeId, prompt: value, task: t, tone, platform: platform === "none" ? null : platform },
      {
        onSuccess: (conv) => setActiveId(conv.id),
        onError: () => toast.error("L'assistant n'a pas pu répondre. Réessayez."),
        onSettled: () => setPendingPrompt(null),
      },
    )
  }

  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault()
      submit()
    }
  }

  const openInComposer = (text: string) => navigate("/content/create", { state: { body: text } })
  const lastUser = [...messages].reverse().find((m) => m.role === "user")
  const history = (
    <HistoryList
      conversations={conversations}
      activeId={activeId}
      onSelect={(id) => { setActiveId(id); setHistoryOpen(false) }}
      onNew={() => { setActiveId(null); setHistoryOpen(false) }}
      onDelete={(id) => del.mutate(id, { onSuccess: () => { if (id === activeId) setActiveId(null); toast.success("Conversation supprimée.") } })}
    />
  )

  return (
    <>
      <PageHeader
        title="Assistant IA"
        description="Trouvez des idées, rédigez vos légendes et adaptez vos textes en quelques secondes."
        actions={
          <Button variant="outline" className="lg:hidden" onClick={() => setHistoryOpen(true)}>
            <History /> Historique
          </Button>
        }
      />
      <DemoNotice className="mb-6">Réponses simulées : aucun texte n'est envoyé à un fournisseur d'IA pendant cette phase.</DemoNotice>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
        <aside className="surface-card hidden p-4 lg:block" aria-label="Historique des conversations">
          {isLoading ? <ListSkeleton rows={3} /> : history}
        </aside>

        <section className="surface-card flex min-h-[620px] flex-col" aria-label="Conversation">
          {/* Réglages */}
          <div className="flex flex-col gap-3 border-b border-border p-4">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Que voulez-vous faire ?">
              {AI_TASKS.map((t) => (
                <button key={t.task} type="button" aria-pressed={task === t.task} onClick={() => setTask(t.task)} className={cn("rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors", task === t.task ? "border-brand-500 bg-brand-500 text-white" : "border-border text-ink-soft hover:border-brand-200 hover:bg-brand-50")}>
                  {t.label}
                </button>
              ))}
            </div>
            <div className="flex flex-wrap gap-3">
              <div className="flex items-center gap-2">
                <Label htmlFor="ai-tone" className="text-xs text-ink-muted">Ton</Label>
                <Select value={tone} onValueChange={(v) => setTone(v as AITone)}>
                  <SelectTrigger id="ai-tone" size="sm" className="w-40"><SelectValue /></SelectTrigger>
                  <SelectContent>{AI_TONES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}</SelectContent>
                </Select>
              </div>
              <div className="flex items-center gap-2">
                <Label htmlFor="ai-platform" className="text-xs text-ink-muted">Pour</Label>
                <Select value={platform} onValueChange={(v) => setPlatform(v as PlatformKey | "none")}>
                  <SelectTrigger id="ai-platform" size="sm" className="w-44"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Tous les réseaux</SelectItem>
                    {PLATFORM_ORDER.map((p) => (
                      <SelectItem key={p} value={p}><PlatformIcon platform={p} size="xs" /> {PLATFORMS[p].name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>

          {/* Messages */}
          <div className="flex-1 overflow-y-auto p-4 sm:p-6" aria-live="polite">
            {messages.length === 0 && !pendingPrompt ? (
              <div className="mx-auto flex max-w-lg flex-col items-center py-8 text-center">
                <span className="inline-flex size-14 items-center justify-center rounded-2xl bg-brand-gradient text-white shadow-brand" aria-hidden="true">
                  <Sparkles className="size-7" />
                </span>
                <h2 className="mt-4 text-lg font-bold">Comment puis-je vous aider ?</h2>
                <p className="mt-1 text-sm text-ink-muted">Choisissez une action ci-dessus, puis décrivez votre besoin. Exemples :</p>
                <ul className="mt-5 grid w-full gap-2 sm:grid-cols-2">
                  {AI_TASKS.slice(0, 4).map((t) => (
                    <li key={t.task}>
                      <button type="button" onClick={() => { setTask(t.task); submit(t.example, t.task) }} className="h-full w-full rounded-xl border border-border p-3 text-left text-sm hover:border-brand-200 hover:bg-brand-50/50">
                        <span className="block text-xs font-semibold text-brand-600">{t.label}</span>
                        <span className="text-ink-soft">« {t.example} »</span>
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            ) : (
              <ol className="space-y-5">
                {messages.map((m) => (
                  <MessageBubble
                    key={m.id}
                    message={m}
                    onUse={openInComposer}
                    onRegenerate={m.role === "assistant" && lastUser ? () => submit(lastUser.content, lastUser.task ?? task) : undefined}
                  />
                ))}
                {pendingPrompt && (
                  <>
                    <MessageBubble message={{ id: "pending", role: "user", content: pendingPrompt, task, createdAt: "" }} onUse={openInComposer} />
                    <li className="flex items-center gap-3 text-sm text-ink-muted" role="status">
                      <span className="inline-flex size-8 items-center justify-center rounded-full bg-brand-50 text-brand-600"><Bot className="size-4" aria-hidden="true" /></span>
                      <span className="flex gap-1" aria-hidden="true">
                        <span className="size-2 animate-bounce rounded-full bg-brand-300" />
                        <span className="size-2 animate-bounce rounded-full bg-brand-400 [animation-delay:120ms]" />
                        <span className="size-2 animate-bounce rounded-full bg-brand-500 [animation-delay:240ms]" />
                      </span>
                      L'assistant rédige…
                    </li>
                  </>
                )}
              </ol>
            )}
            <div ref={endRef} />
          </div>

          {/* Prompt */}
          <form className="border-t border-border p-4" onSubmit={(e) => { e.preventDefault(); submit() }}>
            <Label htmlFor="ai-prompt" className="sr-only">Votre demande</Label>
            <div className="flex items-end gap-2 rounded-2xl border border-input bg-card p-2 focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/20">
              <Textarea
                id="ai-prompt"
                value={prompt}
                onChange={(e) => setPrompt(e.target.value)}
                onKeyDown={onKeyDown}
                rows={2}
                placeholder={AI_TASKS.find((t) => t.task === task)?.example ?? "Décrivez ce que vous voulez…"}
                className="max-h-40 min-h-12 flex-1 resize-none border-none bg-transparent shadow-none focus-visible:ring-0"
              />
              <Button type="submit" size="icon" disabled={!prompt.trim() || send.isPending} aria-label="Envoyer">
                <SendHorizontal />
              </Button>
            </div>
            <p className="mt-2 text-xs text-ink-muted">Entrée pour envoyer · Maj + Entrée pour aller à la ligne. Relisez toujours les propositions avant de publier.</p>
          </form>
        </section>
      </div>

      <Sheet open={historyOpen} onOpenChange={setHistoryOpen}>
        <SheetContent side="left" className="w-[300px] p-5">
          <SheetTitle>Historique</SheetTitle>
          <SheetDescription className="sr-only">Vos conversations avec l'assistant</SheetDescription>
          {history}
        </SheetContent>
      </Sheet>
    </>
  )
}
