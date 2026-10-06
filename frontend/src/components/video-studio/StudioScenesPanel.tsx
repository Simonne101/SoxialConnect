import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-react"
import { useState } from "react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useStudioAction } from "@/hooks/queries/videoStudio"
import { apiErrorMessage } from "@/lib/http"
import * as studio from "@/services/videoStudio"
import type { StudioProject, StudioScene, StudioSceneRole } from "@/types"
import { formatSeconds, msToSeconds, ROLE_LABELS, secondsToMs } from "./labels"

/** Scènes : segments narratifs ordonnés du projet (début = somme des durées précédentes). */
export function StudioScenesPanel({ project }: { project: StudioProject }) {
  const add = useStudioAction(project.id, () => studio.createScene(project.id, { duration_ms: 5000, name: `Scène ${project.scenes.length + 1}` }, project.lockVersion))
  // Début de chaque scène = somme des durées des scènes précédentes.
  const starts = project.scenes.map((_, i) => project.scenes.slice(0, i).reduce((sum, s) => sum + s.durationMs, 0))

  return (
    <div className="space-y-2">
      <ol className="space-y-2">
        {project.scenes.map((scene, index) => (
          <SceneRow key={`${scene.id}-${project.lockVersion}`} project={project} scene={scene} startMs={starts[index]} index={index} />
        ))}
      </ol>
      <Button variant="outline" size="sm" className="w-full" disabled={add.isPending} onClick={() => add.mutate(undefined, { onError: (e) => toast.error(apiErrorMessage(e)) })}>
        <Plus /> Ajouter une scène
      </Button>
    </div>
  )
}

function SceneRow({ project, scene, startMs, index }: { project: StudioProject; scene: StudioScene; startMs: number; index: number }) {
  const [name, setName] = useState(scene.name ?? "")
  const [seconds, setSeconds] = useState(String(msToSeconds(scene.durationMs)))
  const update = useStudioAction(project.id, (input: studio.SceneInput) => studio.updateScene(scene.id, input, project.lockVersion))
  const remove = useStudioAction(project.id, () => studio.deleteScene(scene.id, project.lockVersion))
  const onError = (e: unknown) => toast.error(apiErrorMessage(e))
  const durationMs = secondsToMs(Number(seconds.replace(",", ".")))
  const dirty = name !== (scene.name ?? "") || durationMs !== scene.durationMs
  const busy = update.isPending || remove.isPending

  return (
    <li className="rounded-xl border border-border bg-card p-3">
      <div className="mb-2 flex items-center justify-between gap-2 text-xs text-ink-muted">
        <span>Scène {index + 1} · début {formatSeconds(startMs)}</span>
        <span className="flex gap-1">
          <Button variant="ghost" size="icon" className="size-7" aria-label="Monter la scène" disabled={busy || index === 0} onClick={() => update.mutate({ position: index - 1 }, { onError })}><ArrowUp /></Button>
          <Button variant="ghost" size="icon" className="size-7" aria-label="Descendre la scène" disabled={busy || index === project.scenes.length - 1} onClick={() => update.mutate({ position: index + 1 }, { onError })}><ArrowDown /></Button>
          <Button variant="ghost" size="icon" className="size-7" aria-label="Supprimer la scène" disabled={busy} onClick={() => remove.mutate(undefined, { onError })}><Trash2 /></Button>
        </span>
      </div>
      <div className="grid grid-cols-[minmax(0,1fr)_5.5rem] gap-2">
        <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={100} placeholder="Nom de la scène" aria-label={`Nom de la scène ${index + 1}`} />
        <Input value={seconds} onChange={(e) => setSeconds(e.target.value)} inputMode="decimal" aria-label={`Durée de la scène ${index + 1} en secondes`} />
      </div>
      <div className="mt-2 flex items-center gap-2">
        <Select value={scene.role} onValueChange={(v) => update.mutate({ role: v as StudioSceneRole }, { onError })}>
          <SelectTrigger className="h-8 flex-1 text-xs" aria-label={`Rôle de la scène ${index + 1}`}><SelectValue /></SelectTrigger>
          <SelectContent>
            {(Object.keys(ROLE_LABELS) as StudioSceneRole[]).map((r) => <SelectItem key={r} value={r}>{ROLE_LABELS[r]}</SelectItem>)}
          </SelectContent>
        </Select>
        <Button size="sm" disabled={!dirty || busy} onClick={() => update.mutate({ name: name.trim() || null, duration_ms: durationMs }, { onError })}>Enregistrer</Button>
      </div>
    </li>
  )
}
