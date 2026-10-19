import { CheckCircle2, Download, Film, KeyRound, LoaderCircle, RefreshCw, TriangleAlert } from "lucide-react"
import { useState } from "react"
import { toast } from "sonner"
import { MediaPickerDialog } from "@/components/media/MediaPickerDialog"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import { useAcquireClipperSource, useAttachClipperSource, useStartPortabilityConsent } from "@/hooks/queries/creative"
import { apiErrorMessage } from "@/lib/http"
import type { ClipperProject } from "@/types"

const METHOD_LABEL = {
  import: "fichier de votre médiathèque",
  data_portability: "export officiel Google de votre chaîne",
  external: "fournisseur autorisé",
  library: "fichier de cette vidéo déjà présent dans votre médiathèque",
  yt_dlp: "vidéo publique récupérée automatiquement (droits attestés)",
} as const

/**
 * Source vidéo du projet (ADR-049, ADR-053, ADR-054). Le lien suffit à identifier, analyser et
 * prévisualiser ; les MP4 exigent le fichier de la vidéo, récupéré automatiquement quand une voie
 * autorisée existe : fichier déjà fourni pour cette vidéo, ou export officiel Google de la chaîne
 * du propriétaire (consentement), ou vidéo publique récupérée par yt-dlp après attestation de droits
 * (ADR-059). L'import d'un fichier n'est proposé qu'en solution de remplacement, avec la raison précise
 * pour laquelle la récupération automatique n'a pas abouti.
 */
export function SourceCard({ project, disabled }: { project: ClipperProject; disabled: boolean }) {
  const [picking, setPicking] = useState(false)
  const [attesting, setAttesting] = useState(false)
  const attach = useAttachClipperSource(project.id)
  const acquire = useAcquireClipperSource(project.id)
  const consent = useStartPortabilityConsent(project.id)
  const { status, method, errorCode, errorMessage, rightsAttested, downloadAvailable } = project.sourceStatus

  const choose = (ids: string[]) => {
    if (!ids[0]) return
    attach.mutate(ids[0], {
      onSuccess: () => toast.success("Source vidéo ajoutée : préparation des clips en cours."),
      onError: (e) => toast.error(apiErrorMessage(e, "La vidéo source n'a pas pu être ajoutée.")),
    })
  }
  const retry = (rightsConfirmed = false) =>
    acquire.mutate(rightsConfirmed, {
      onSuccess: () => toast.success(rightsConfirmed ? "Attestation enregistrée : récupération de la vidéo lancée." : "Récupération de la vidéo relancée."),
      onError: (e) => toast.error(apiErrorMessage(e, "La récupération n'a pas pu être relancée.")),
    })
  const authorize = () => consent.mutate(undefined, { onError: (e) => toast.error(apiErrorMessage(e, "L'autorisation Google n'a pas pu être ouverte.")) })
  const picker = <MediaPickerDialog key={String(picking)} open={picking} onOpenChange={setPicking} initial={[]} onConfirm={choose} videoOnly />

  if (project.renderable) {
    return (
      <div className="flex flex-col gap-2 rounded-xl border border-success-100 bg-success-50/60 p-3 text-sm sm:flex-row sm:items-center sm:justify-between">
        <p className="flex min-w-0 items-start gap-2 text-ink-soft">
          <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success-700" aria-hidden="true" />
          <span className="min-w-0 wrap-anywhere">
            <span className="font-semibold text-ink">Source prête</span>
            {" — "}
            {method ? METHOD_LABEL[method] : "fichier de votre médiathèque"}
            {project.source?.name ? ` (${project.source.name})` : ""}
          </span>
        </p>
        <Button variant="ghost" size="sm" className="self-start sm:self-auto" disabled={disabled || attach.isPending} onClick={() => setPicking(true)}>Changer de fichier</Button>
        {picker}
      </div>
    )
  }

  if (status === "pending" || status === "resolving" || status === "waiting_for_provider") {
    const google = method === "data_portability"
    const download = method === "yt_dlp"
    return (
      <div className="flex items-start gap-2 rounded-xl border border-border p-3 text-sm text-ink-soft" role="status">
        <LoaderCircle className="mt-0.5 size-4 shrink-0 animate-spin text-brand-600" aria-hidden="true" />
        <p className="min-w-0">
          <span className="font-semibold text-ink">{google ? "Récupération de la vidéo via Google" : download ? "Téléchargement de la vidéo" : "Récupération de la vidéo"}</span>
          {" — "}
          {status === "waiting_for_provider" && google
            ? "Google prépare l'export de votre chaîne (de quelques minutes à quelques heures), puis SoxialConnect en extrait cette vidéo. Les clips pourront être générés dès qu'elle sera récupérée."
            : status === "waiting_for_provider" && download
              ? (errorMessage ?? "Le téléchargement n'a pas abouti : nouvel essai automatique.")
              : download
                ? "récupération du fichier, puis vérification et import dans votre médiathèque (quelques minutes selon la durée)."
                : status === "resolving"
                  ? "vidéo obtenue, vérification et préparation en cours."
                  : "recherche d'une voie autorisée pour obtenir le fichier de cette vidéo."}
        </p>
      </div>
    )
  }

  // Fichier non accessible automatiquement : erreur d'ACQUISITION précise, puis import d'un fichier autorisé.
  // Le clipping ne dépend d'aucun compte social (ADR-058) : jamais de demande de connecter la chaîne qui a
  // publié la vidéo. Seule exception, une vidéo de la PROPRE chaîne connectée de l'utilisateur : l'export
  // officiel Google (CONSENT_REQUIRED) est alors proposé, en plus de l'import.
  const needsConsent = errorCode === "CONSENT_REQUIRED"
  const canRetry = status === "failed" || status === "expired" || status === "unavailable"
  // Récupération yt-dlp possible mais pas encore attestée : l'utilisateur confirme ses droits (ADR-059).
  const canAttest = downloadAvailable && !rightsAttested && canRetry
  // Voie de récupération désactivée ou mal installée sur le serveur : diagnostic distinct d'un échec réel.
  const downloadOff = errorCode === "VIDEO_DOWNLOAD_DISABLED" || errorCode === "YTDLP_UNAVAILABLE"
  const title = needsConsent
    ? "Vidéo de votre chaîne : autorisez l'export Google ou importez le fichier"
    : errorCode === "BLOCKED_BY_YOUTUBE"
      ? "YouTube a refusé la récupération depuis ce serveur"
      : downloadOff
        ? "Récupération automatique indisponible sur ce serveur"
        : "Fichier vidéo non accessible automatiquement"

  return (
    <div className="rounded-xl border border-warning-100 bg-warning-50 p-4 text-sm text-warning-700">
      <p className="flex items-start gap-2 font-semibold">
        <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <span>{title}</span>
      </p>
      <p className="mt-1">
        {project.clips.some((c) => c.source !== "manual") ? "Les passages ont été analysés. " : ""}
        {errorMessage ?? "Le fichier de la vidéo est nécessaire pour générer les MP4."}
      </p>
      {canAttest && (
        <div className="mt-3 rounded-lg border border-warning-100 bg-card p-3 text-ink-soft">
          <div className="flex items-start gap-2">
            <Checkbox id={`rights-${project.id}`} checked={attesting} onCheckedChange={(v) => setAttesting(v === true)} className="mt-0.5" />
            <Label htmlFor={`rights-${project.id}`} className="block text-sm font-normal leading-snug">
              <span className="font-medium text-ink">J'atteste détenir les droits ou l'autorisation d'utiliser cette vidéo.</span>{" "}
              Seules les vidéos publiques ou non répertoriées peuvent être récupérées (jamais une vidéo privée, réservée aux membres ou soumise à une limite d'âge).
            </Label>
          </div>
          <Button size="sm" className="mt-2" disabled={disabled || !attesting || acquire.isPending} onClick={() => retry(true)}>
            {acquire.isPending ? <LoaderCircle className="animate-spin" /> : <Download />} Récupérer la vidéo
          </Button>
        </div>
      )}
      <div className="mt-3 flex flex-wrap gap-2">
        <Button size="sm" disabled={disabled || attach.isPending} onClick={() => setPicking(true)}>
          {attach.isPending ? <LoaderCircle className="animate-spin" /> : <Film />} Importer le fichier
        </Button>
        {needsConsent && (
          <Button size="sm" variant="outline" disabled={disabled || consent.isPending} onClick={authorize}>
            {consent.isPending ? <LoaderCircle className="animate-spin" /> : <KeyRound />} Autoriser l'export Google de ma chaîne
          </Button>
        )}
        {canRetry && !canAttest && !downloadOff && (
          <Button size="sm" variant="outline" disabled={disabled || acquire.isPending} onClick={() => retry()}>
            {acquire.isPending ? <LoaderCircle className="animate-spin" /> : <RefreshCw />} Relancer la récupération
          </Button>
        )}
      </div>
      <p className="mt-3 text-warning-700/90">Importez un fichier dont vous détenez les droits : les passages seront recalés sur la vidéo réelle, puis les clips générés.</p>
      {picker}
    </div>
  )
}
