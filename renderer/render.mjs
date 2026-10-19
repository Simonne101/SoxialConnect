// Rendu Remotion d'un clip (ADR-051/052), appelé par le backend (RemotionRenderer, Symfony Process,
// arguments en tableau : aucun shell). Entrée : un fichier JSON (RenderPlan) et, facultativement, la
// vidéo du passage déjà découpée et recadrée par FFmpeg ; sortie : un MP4 H.264/AAC. Une ligne JSON
// FINALE est écrite sur la sortie standard ({"ok":true,…,"timings":{…}} ou {"ok":false,…}) ; pendant le
// rendu, des lignes {"progress":0.42,…} (au plus une par point de pourcentage) permettent au backend
// d'afficher l'avancement réel.
//
//   node render.mjs --props <plan.json> --out <clip.mp4> [--video <passage.mp4> --root <dossier de travail>]
//
// La vidéo n'est JAMAIS désignée par le plan : elle est servie à Chrome par un mini-serveur local
// (127.0.0.1, port aléatoire, jeton aléatoire) qui ne sert QUE ce fichier, et seulement s'il se trouve
// dans le dossier de travail du rendu (--root). Aucun autre chemin n'est lisible par la composition.
//
// Variables : REMOTION_BROWSER_EXECUTABLE (Chrome local, évite tout téléchargement),
// REMOTION_CONCURRENCY (onglets de rendu parallèles), TEMP/TMP (fichiers temporaires).
import { randomBytes } from "node:crypto"
import { createReadStream, existsSync, mkdirSync, readFileSync, readdirSync, realpathSync, statSync } from "node:fs"
import { createServer } from "node:http"
import path from "node:path"
import { fileURLToPath } from "node:url"
import { bundle } from "@remotion/bundler"
import { renderMedia, selectComposition } from "@remotion/renderer"

const ROOT = path.dirname(fileURLToPath(import.meta.url))
const GL = ["angle", "swangle", "egl", "swiftshader", "vulkan", "angle-egl"].includes(process.env.REMOTION_GL ?? "") ? process.env.REMOTION_GL : null
const BUNDLE_DIR = path.join(ROOT, ".bundle")

function fail(code, message) {
  process.stdout.write(JSON.stringify({ ok: false, code, message }) + "\n")
  process.exit(1)
}

function arg(name) {
  const i = process.argv.indexOf(`--${name}`)
  return i > 0 ? process.argv[i + 1] : undefined
}

/** Validation stricte du plan : rien d'arbitraire n'atteint la composition ni le moteur de rendu. */
function validate(props) {
  const int = (v, min, max) => Number.isInteger(v) && v >= min && v <= max
  if (props?.schema !== "soxial.clip_render_plan.v1") fail("INVALID_PLAN", "Schéma de plan inconnu.")
  if (!int(props.width, 16, 3840) || !int(props.height, 16, 3840)) fail("INVALID_PLAN", "Dimensions invalides.")
  if (![24, 25, 30].includes(props.fps)) fail("INVALID_PLAN", "Cadence invalide.")
  // Règle métier : jamais plus de 60 secondes (+1 image de tolérance d'arrondi).
  if (!int(props.duration_in_frames, 1, props.fps * 60 + 1)) fail("INVALID_PLAN", "Durée invalide (60 s maximum).")
  if (props.template !== "clean-short") fail("INVALID_PLAN", "Modèle inconnu.")
  if (!Array.isArray(props.captions) || props.captions.some((c) => typeof c?.text !== "string" || !Number.isFinite(c.start_ms) || !Number.isFinite(c.end_ms))) {
    fail("INVALID_PLAN", "Légendes invalides.")
  }
  if (props.hook !== null && props.hook !== undefined && typeof props.hook !== "string") fail("INVALID_PLAN", "Accroche invalide.")
  // La source vidéo n'est jamais lue depuis le plan (elle est fournie par --video, voir plus bas).
  if (props.video_src !== null && props.video_src !== undefined) fail("INVALID_PLAN", "Source vidéo refusée dans le plan.")
}

/** Délai d'une étape de rendu (ms), fourni par Laravel (video.remotion.step_timeout) et borné à 10 s – 10 min. */
function stepTimeout() {
  const ms = Number(process.env.REMOTION_STEP_TIMEOUT_MS)
  return Number.isInteger(ms) && ms >= 10_000 && ms <= 600_000 ? ms : 120_000
}

/** Vidéo du passage : fichier .mp4 existant DANS le dossier de travail (liens et « .. » résolus). */
function videoFile() {
  const video = arg("video")
  if (video === undefined) return null
  const root = arg("root")
  if (!root || !existsSync(root) || !existsSync(video)) fail("INVALID_ARGUMENTS", "Vidéo ou dossier de travail introuvable.")
  const realRoot = realpathSync(root)
  const real = realpathSync(video)
  const relative = path.relative(realRoot, real)
  if (relative.startsWith("..") || path.isAbsolute(relative) || path.extname(real).toLowerCase() !== ".mp4" || !statSync(real).isFile()) {
    fail("INVALID_ARGUMENTS", "Vidéo refusée (hors du dossier de travail).")
  }
  return real
}

/** Serveur local éphémère qui ne sert qu'un fichier, avec les requêtes partielles (Range) utilisées par Chrome. */
function serve(file) {
  const token = randomBytes(16).toString("hex")
  const size = statSync(file).size
  const server = createServer((req, res) => {
    // La vidéo est lue par la page de rendu Remotion (<Video> de @remotion/media, http://localhost:<port>) :
    // CORS limité aux origines locales — jamais « * » ; aucune autre origine ne reçoit d'autorisation.
    const origin = req.headers.origin
    const cors = typeof origin === "string" && /^http:\/\/(localhost|127\.0\.0\.1):\d{1,5}$/.test(origin)
      ? { "Access-Control-Allow-Origin": origin, "Vary": "Origin", "Access-Control-Expose-Headers": "Content-Length, Content-Range, Accept-Ranges" }
      : {}
    if (req.method === "OPTIONS") {
      return res.writeHead(cors["Access-Control-Allow-Origin"] ? 204 : 403, { ...cors, "Access-Control-Allow-Methods": "GET, HEAD", "Access-Control-Allow-Headers": "Range" }).end()
    }
    if (req.method !== "GET" && req.method !== "HEAD") return res.writeHead(405).end()
    if (req.url !== `/${token}.mp4`) return res.writeHead(404).end()
    const range = /^bytes=(\d*)-(\d*)$/.exec(req.headers.range ?? "")
    let start = 0
    let end = size - 1
    if (range) {
      start = range[1] === "" ? Math.max(0, size - Number(range[2])) : Number(range[1])
      end = range[1] !== "" && range[2] !== "" ? Math.min(Number(range[2]), size - 1) : end
      if (start > end || start >= size) return res.writeHead(416, { ...cors, "Content-Range": `bytes */${size}` }).end()
    }
    res.writeHead(range ? 206 : 200, {
      ...cors,
      "Content-Type": "video/mp4",
      "Accept-Ranges": "bytes",
      "Content-Length": end - start + 1,
      ...(range ? { "Content-Range": `bytes ${start}-${end}/${size}` } : {}),
    })
    if (req.method === "HEAD") return res.end()
    createReadStream(file, { start, end }).pipe(res)
  })
  return new Promise((resolve) => server.listen(0, "127.0.0.1", () => resolve({ server, url: `http://127.0.0.1:${server.address().port}/${token}.mp4` })))
}

/** Le bundle n'est reconstruit que si une source a changé depuis le dernier rendu. */
function newest(dir) {
  return readdirSync(dir, { withFileTypes: true }).reduce((max, e) => {
    const full = path.join(dir, e.name)
    return Math.max(max, e.isDirectory() ? newest(full) : statSync(full).mtimeMs)
  }, 0)
}

async function bundled() {
  const stamp = path.join(BUNDLE_DIR, "index.html")
  if (existsSync(stamp) && statSync(stamp).mtimeMs > newest(path.join(ROOT, "src"))) return BUNDLE_DIR
  return bundle({ entryPoint: path.join(ROOT, "src", "index.ts"), outDir: BUNDLE_DIR })
}

const propsFile = arg("props")
const out = arg("out")
if (!propsFile || !out || !existsSync(propsFile)) fail("INVALID_ARGUMENTS", "Arguments --props et --out requis.")
let props
try {
  props = JSON.parse(readFileSync(propsFile, "utf8"))
} catch {
  fail("INVALID_PLAN", "Plan JSON illisible.")
}
validate(props)
const video = videoFile()
mkdirSync(path.dirname(out), { recursive: true })

const started = Date.now()
const timings = {}
const lap = (name, from) => (timings[name] = Date.now() - from)
const local = video ? await serve(video) : null
try {
  const inputProps = { ...props, video_src: local?.url ?? null }
  let t = Date.now()
  const serveUrl = await bundled()
  lap("bundle_ms", t)
  const browserExecutable = process.env.REMOTION_BROWSER_EXECUTABLE || null
  t = Date.now()
  const composition = await selectComposition({ serveUrl, id: "YouTubeClip", inputProps, browserExecutable, timeoutInMilliseconds: stepTimeout() })
  lap("select_ms", t)
  t = Date.now()
  let lastPercent = -1
  await renderMedia({
    serveUrl,
    composition,
    inputProps,
    codec: "h264",
    audioCodec: "aac",
    outputLocation: out,
    browserExecutable,
    concurrency: Number(process.env.REMOTION_CONCURRENCY) || 2,
    // Moteur graphique de Chrome (REMOTION_GL : angle, swangle, egl…) ; vide = choix par défaut de Remotion.
    ...(GL ? { chromiumOptions: { gl: GL } } : {}),
    // Délai de chaque étape (démarrage du navigateur, delayRender comme l'extraction d'une image) : 30 s par
    // défaut chez Remotion, dépassé sur une machine chargée (ADR-057). Durée totale bornée par Laravel.
    timeoutInMilliseconds: stepTimeout(),
    overwrite: true,
    // Avancement réel (images rendues puis encodées) et durées des deux phases, pour le diagnostic.
    onProgress: ({ progress, renderedFrames, encodedFrames, renderedDoneIn, encodedDoneIn }) => {
      if (renderedDoneIn !== null) timings.frames_ms = renderedDoneIn
      if (encodedDoneIn !== null) timings.encode_ms = encodedDoneIn
      const percent = Math.floor(progress * 100)
      if (percent > lastPercent) {
        lastPercent = percent
        process.stdout.write(JSON.stringify({ progress, rendered: renderedFrames, encoded: encodedFrames }) + "\n")
      }
    },
  })
  lap("render_ms", t)
  timings.total_ms = Date.now() - started
  process.stdout.write(JSON.stringify({ ok: true, out, frames: composition.durationInFrames, width: composition.width, height: composition.height, ms: timings.total_ms, timings, concurrency: Number(process.env.REMOTION_CONCURRENCY) || 2, gl: GL }) + "\n")
} catch (error) {
  // Détail technique dans le flux d'erreur (journal serveur), jamais dans la réponse à l'utilisateur.
  process.stderr.write(String(error?.stack ?? error) + "\n")
  fail("REMOTION_RENDER_FAILED", "Le rendu Remotion a échoué.")
} finally {
  local?.server.close()
}
