// File d'import partagée (médiathèque, boîte d'import, sélecteur du Composer).
// Chaque fichier passe par : pending → uploading (progression) → processing | ready | quarantined | failed.
import { useQueryClient } from "@tanstack/react-query"
import { useSyncExternalStore } from "react"
import { apiErrorMessage } from "@/lib/http"
import { uid } from "@/mocks/helpers"
import * as media from "@/services/media"
import type { MediaAsset, UploadItem, UploadState } from "@/types"
import { qk } from "./queries/keys"

let items: UploadItem[] = []
const listeners = new Set<() => void>()
const emit = () => listeners.forEach((l) => l())
const patch = (id: string, change: Partial<UploadItem>) => {
  items = items.map((i) => (i.id === id ? { ...i, ...change } : i))
  emit()
}

/** Deux envois en parallèle au plus : suffisant sans saturer la connexion ni le serveur. */
const CONCURRENCY = 2

function stateOf(asset: MediaAsset): UploadState {
  if (asset.status === "processing" || asset.status === "uploading") return "processing"
  if (asset.status === "failed") return "failed"
  // Prêt mais non utilisable : en attente d'analyse antivirus (quarantaine).
  return asset.usable ? "ready" : "quarantined"
}

async function runAll(queue: { item: UploadItem; file: File }[], folderId: string | null, onDone: () => void): Promise<MediaAsset[]> {
  const uploaded: MediaAsset[] = []
  let cursor = 0
  const worker = async () => {
    while (cursor < queue.length) {
      const { item, file } = queue[cursor++]
      patch(item.id, { state: "uploading" })
      try {
        const { media: asset, duplicates } = await media.uploadMedia(file, folderId, (progress) => patch(item.id, { progress }))
        uploaded.push(asset)
        patch(item.id, { state: stateOf(asset), progress: 100, mediaId: asset.id, duplicate: duplicates > 0, error: asset.failureReason })
      } catch (error) {
        patch(item.id, { state: "failed", error: apiErrorMessage(error, "L'import a échoué. Réessayez.") })
      }
      onDone()
    }
  }
  await Promise.all(Array.from({ length: Math.min(CONCURRENCY, queue.length) }, worker))
  return uploaded
}

export function useUploadQueue() {
  const qc = useQueryClient()
  const snapshot = useSyncExternalStore(
    (listener) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    () => items,
  )

  /** Ajoute des fichiers ; les fichiers refusés côté client sont signalés immédiatement. */
  const enqueue = async (files: File[], folderId: string | null) => {
    const queue: { item: UploadItem; file: File }[] = []
    const added: UploadItem[] = files.map((file) => {
      const check = media.validateFile(file)
      const item: UploadItem = {
        id: uid("up"),
        name: file.name,
        type: check.ok ? check.type : null,
        sizeBytes: file.size,
        progress: 0,
        state: check.ok ? "pending" : "failed",
        error: check.ok ? null : check.reason,
        mediaId: null,
        duplicate: false,
      }
      if (check.ok) queue.push({ item, file })
      return item
    })
    items = [...added, ...items]
    emit()
    const uploaded = await runAll(queue, folderId, () => void qc.invalidateQueries({ queryKey: qk.media.all }))
    return { uploaded, rejected: added.filter((i) => i.state === "failed") }
  }

  const dismiss = (id: string) => {
    items = items.filter((i) => i.id !== id)
    emit()
  }

  /** Retire les imports terminés (réussis ou refusés). */
  const clearFinished = () => {
    items = items.filter((i) => i.state === "pending" || i.state === "uploading")
    emit()
  }

  return { items: snapshot, enqueue, dismiss, clearFinished }
}
