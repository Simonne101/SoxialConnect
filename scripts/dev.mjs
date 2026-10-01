// Lanceur de développement SoxialConnect : `npm run dev` depuis la racine du projet.
// 1. vérifie MySQL et Redis (Laragon) ; 2. démarre l'API Laravel (localhost:8000), le worker de
// file d'attente (publication, médias) et le planificateur ; 3. démarre le site React
// (localhost:5173) ; 4. ouvre le navigateur. Ctrl + C arrête tout.
// Aucune dépendance : uniquement Node.js.
import { spawn, spawnSync } from 'node:child_process'
import net from 'node:net'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const backend = path.join(root, 'backend')
const frontend = path.join(root, 'frontend')
const APP_URL = 'http://localhost:5173'
const API_URL = 'http://localhost:8000/api/v1/health'
const isWindows = process.platform === 'win32'

const color = { api: '\x1b[35m', web: '\x1b[36m', worker: '\x1b[34m', cron: '\x1b[90m', info: '\x1b[32m', warn: '\x1b[33m', err: '\x1b[31m', reset: '\x1b[0m' }
const say = (tag, text, c = color.info) => console.log(`${c}[${tag}]${color.reset} ${text}`)

const portOpenOn = (host, port) => new Promise((resolve) => {
  const socket = net.connect({ host, port })
  socket.setTimeout(1000)
  socket.once('connect', () => { socket.destroy(); resolve(true) })
  socket.once('timeout', () => { socket.destroy(); resolve(false) })
  socket.once('error', () => resolve(false))
})
// « localhost » peut écouter en IPv4 (127.0.0.1) ou en IPv6 (::1) selon le programme : on teste les deux.
const portOpen = async (port) => (await portOpenOn('127.0.0.1', port)) || (await portOpenOn('::1', port))
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function waitFor(check, timeoutMs) {
  const end = Date.now() + timeoutMs
  while (Date.now() < end) {
    if (await check()) return true
    await sleep(1000)
  }
  return false
}

// --- 1. Services Laragon ------------------------------------------------------------------------
const services = [['MySQL', 3306], ['Redis', 6379]]
const missing = async () => (await Promise.all(services.map(async ([name, port]) => ((await portOpen(port)) ? null : name)))).filter(Boolean)
let down = await missing()
if (down.length) {
  say('info', `${down.join(' et ')} ne répond pas : ouvrez Laragon et cliquez sur « Start All ». J'attends (2 minutes)…`, color.warn)
  const ok = await waitFor(async () => (await missing()).length === 0, 120_000)
  if (!ok) {
    say('info', `${(await missing()).join(' et ')} toujours injoignable. Démarrez Laragon puis relancez « npm run dev ».`, color.err)
    process.exit(1)
  }
}
say('info', 'MySQL et Redis : OK')

// --- 2. Ports libres ---------------------------------------------------------------------------
for (const [name, port] of [['API', 8000], ['Site', 5173]]) {
  if (await portOpen(port)) {
    say('info', `Le port ${port} (${name}) est déjà utilisé : SoxialConnect est peut-être déjà lancé. Ouvrez ${APP_URL} ou fermez l'autre fenêtre, puis relancez.`, color.err)
    process.exit(1)
  }
}

// --- 3. Démarrage de l'API et du site -----------------------------------------------------------
const children = []
function run(tag, command, args, cwd, extraEnv = {}) {
  // Windows : `php` et `npm` sont des scripts .cmd, lancés via cmd.exe (sans l'option shell dépréciée).
  const [bin, argv] = isWindows ? ['cmd.exe', ['/d', '/s', '/c', [command, ...args].join(' ')]] : [command, args]
  const child = spawn(bin, argv, { cwd, env: { ...process.env, ...extraEnv } })
  // Le journal des requêtes de `artisan serve` (heure, chemin, points, durée) est masqué :
  // seuls les messages utiles (démarrage, avertissements, erreurs) restent affichés.
  const noise = /^\s*(~\s|\.{2,}|\d{4}-\d{2}-\d{2}|\/\S*\s*$|\/\S*\s+\.{2,}|\d+(\.\d+)?\s?(ms|s)\s*$)/
  const print = (data) => data.toString().split(/\r?\n/)
    .filter((l) => l.trim() && !(tag === 'api' && noise.test(l)))
    .forEach((l) => say(tag, l, color[tag]))
  child.stdout.on('data', print)
  child.stderr.on('data', print)
  child.on('exit', (code) => {
    if (!stopping) {
      say(tag, `s'est arrêté (code ${code}). Arrêt de SoxialConnect.`, color.err)
      stop(1)
    }
  })
  children.push(child)
  return child
}

let stopping = false
function stop(code = 0) {
  if (stopping) return
  stopping = true
  for (const child of children) {
    if (child.exitCode !== null) continue
    if (isWindows) spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' })
    else child.kill('SIGTERM')
  }
  process.exit(code)
}
process.on('SIGINT', () => { say('info', 'Arrêt…'); stop(0) })
process.on('SIGTERM', () => stop(0))

say('info', 'Démarrage de l\'API (http://localhost:8000)…')
// Réglages PHP du projet (GD, limites d'upload, OPcache) sans toucher au php.ini système.
const phpEnv = { PHP_INI_SCAN_DIR: path.join(backend, 'dev') }
run('api', 'php', ['artisan', 'serve', '--host=localhost', '--port=8000'], backend, phpEnv)

// Worker SoxialConnect : files « publishing » (moteur de publication), « default » et « media ».
// --timeout inférieur à retry_after (360 s) ; chaque job fixe lui-même ses tentatives.
say('info', 'Démarrage du worker de file d\'attente (publication, médias)…')
run('worker', 'php', ['artisan', 'queue:work', 'redis', '--queue=publishing,default,media', '--sleep=1', '--timeout=300'], backend, phpEnv)

// Planificateur Laravel (chaque minute) : publications programmées arrivées à échéance, reprise
// des envois bloqués, purge des médias supprimés (03:30).
say('info', 'Démarrage du planificateur…')
run('cron', 'php', ['artisan', 'schedule:work'], backend, phpEnv)

say('info', 'Démarrage du site (http://localhost:5173)…')
run('web', 'npm', ['run', 'dev', '--', '--port', '5173', '--strictPort', '--host', 'localhost'], frontend)

// --- 4. Navigateur ------------------------------------------------------------------------------
const httpOk = async (url) => {
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(20_000) })
    return res.ok
  } catch {
    return false
  }
}
const ready = await waitFor(async () => (await httpOk(API_URL)) && (await httpOk(APP_URL)), 120_000)
if (ready) {
  say('info', `SoxialConnect est prêt : ${APP_URL}  (Ctrl + C pour tout arrêter)`)
  if (isWindows) {
    // Chrome en priorité, sinon Firefox, sinon le navigateur par défaut de Windows.
    const pf = [process.env.ProgramFiles, process.env['ProgramFiles(x86)'], process.env.LOCALAPPDATA].filter(Boolean)
    const candidates = pf.flatMap((dir) => [path.join(dir, 'Google', 'Chrome', 'Application', 'chrome.exe'), path.join(dir, 'Mozilla Firefox', 'firefox.exe')])
    const { existsSync } = await import('node:fs')
    const browser = candidates.sort((a) => (a.includes('chrome') ? -1 : 1)).find((p) => existsSync(p))
    if (browser) spawn(browser, [APP_URL], { stdio: 'ignore', detached: true }).unref()
    else spawn('cmd', ['/c', 'start', '', APP_URL], { stdio: 'ignore', detached: true }).unref()
  }
  else spawn(process.platform === 'darwin' ? 'open' : 'xdg-open', [APP_URL], { stdio: 'ignore', detached: true }).unref()
} else {
  say('info', `Le démarrage prend plus de temps que prévu. Ouvrez ${APP_URL} dans quelques instants.`, color.warn)
}
