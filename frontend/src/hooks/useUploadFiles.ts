import { toast } from "sonner"
import { useUploadQueue } from "./useUploadQueue"

/** Import de fichiers avec retours utilisateur (toasts) ; le détail s'affiche dans la file d'import. */
export function useUploadFiles() {
  const { enqueue } = useUploadQueue()
  return async (files: File[], folderId: string | null) => {
    const { uploaded, rejected } = await enqueue(files, folderId)
    if (uploaded.length) toast.success(uploaded.length === 1 ? "1 fichier importé." : `${uploaded.length} fichiers importés.`)
    if (rejected.length) toast.error(rejected.length === 1 ? `${rejected[0].name} : ${rejected[0].error}` : `${rejected.length} fichiers refusés : voir le détail des imports.`)
    return uploaded
  }
}
