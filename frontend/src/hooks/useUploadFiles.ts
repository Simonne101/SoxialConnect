import { toast } from "sonner"
import { useUploadMedia } from "./queries/media"

/** Import de fichiers avec retours utilisateur (toasts). */
export function useUploadFiles() {
  const upload = useUploadMedia()
  return (files: File[], folderId: string | null) =>
    upload.mutate(
      { files, folderId },
      {
        onSuccess: ({ accepted, rejected }) => {
          if (accepted.length) toast.success(`${accepted.length} fichier(s) en cours d'import. Ils seront prêts dans quelques secondes.`)
          rejected.forEach((r) => toast.error(`${r.name} : ${r.reason}`))
        },
      },
    )
}
