// Lanceur de DÉVELOPPEMENT SoxialConnect (aucun effet sur la production).
//
//   npm run dev           démarre tout ; si SoxialConnect tourne déjà, réutilise l'instance existante
//   npm run dev:stop      arrête l'instance SoxialConnect en cours (et ses processus orphelins)
//   npm run dev:restart   arrête puis redémarre (ex. après un changement de .env)
//   option --no-browser   n'ouvre pas le navigateur (ou SOXIAL_NO_BROWSER=1)
//   option --workers=ai,video   démarre aussi le worker IA et/ou le worker de rendu vidéo
//                         (désactivés par défaut ; à repasser avec dev:restart, ex.
//                         `npm run dev:restart -- --workers=ai`)
//
// 1. vérifie MySQL et Redis (Laragon) ; 2. détecte une instance déjà lancée et les processus
// orphelins d'une instance précédente ; 3. démarre l'API Laravel (localhost:8000), le worker,
// le planificateur et le site React (localhost:5173) ; 4. Ctrl + C arrête tout ce qu'il a lancé.
//
// Sécurité : seuls les processus de CETTE copie du projet sont arrêtés — ceux lancés par ce script
// (enregistrés dans backend/storage/framework/cache/dev-launcher.json, avec leur date de création
// contre la réutilisation d'un PID) ou dont la ligne de commande contient le chemin du projet.
// Jamais d'arrêt global (« taskkill /IM php.exe ») : d'autres projets PHP tournent sur ce poste.
//
// Worker en développement : `queue:listen` (un processus PHP neuf par job) → le code modifié sur
// le disque est toujours utilisé, sans redémarrage. La production garde `queue:work`/Horizon avec
// redémarrage contrôlé (`queue:restart` au déploiement), voir docs/publishing.md §4.
// Aucune dépendance : uniquement Node.js.
import { spawn, spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import net from 'node:net'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const backend = path.join(root, 'backend')
const frontend = path.join(root, 'frontend')
const STATE_FILE = path.join(backend, 'storage', 'framework', 'cache', 'dev-launcher.json')
const APP_URL = 'http://localhost:5173'
const API_URL = 'http://localhost:8000/api/v1/health'
const PORTS = [['API', 8000], ['Site', 5173]]
const isWindows = process.platform === 'win32'
const argv = new Set(process.argv.slice(2))
const mode = argv.has('--stop') ? 'stop' : argv.has('--restart') ? 'restart' : 'start'
const openBrowser = !argv.has('--no-browser') && process.env.SOXIAL_NO_BROWSER !== '1'
// Workers optionnels (ADR-042) : file « ai » (connexion redis) et file « video » (connexion redis-video).
const OPTIONAL_WORKERS = ['ai', 'video']
const workersArg = process.argv.slice(2).find((a) => a.startsWith('--workers='))
const extraWorkers = new Set((workersArg ? workersArg.slice('--workers='.length) : '').split(',').map((w) => w.trim()).filter(Boolean))

const color = { api: '\x1b[35m', web: '\x1b[36m', worker: '\x1b[34m', 'worker-ai': '\x1b[34m', 'worker-video': '\x1b[34m', cron: '\x1b[90m', info: '\x1b[32m', warn: '\x1b[33m', err: '\x1b[31m', reset: '\x1b[0m' }
const say = (tag, text, c = color.info) => console.log(`${c}[${tag}]${color.reset} ${text}`)
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

const portOpenOn = (host, port) => new Promise((resolve) => {
  const socket = net.connect({ host, port })
  socket.setTimeout(1000)
  socket.once('connect', () => { socket.destroy(); resolve(true) })
  socket.once('timeout', () => { socket.destroy(); resolve(false) })
  socket.once('error', () => resolve(false))
})
// « localhost » peut écouter en IPv4 (127.0.0.1) ou en IPv6 (::1) selon le programme : on teste les deux.
const portOpen = async (port) => (await portOpenOn('127.0.0.1', port)) || (await portOpenOn('::1', port))

async function waitFor(check, timeoutMs) {
  const end = Date.now() + timeoutMs
  while (Date.now() < end) {
    if (await check()) return true
    await sleep(1000)
  }
  return false
}

// --- Processus du système (lecture seule) ----------------------------------------------------------
// [{ pid, ppid, created, name, cmd }] ; created sert à ne jamais confondre un PID réutilisé.
function snapshot() {
  if (isWindows) {
    const script = "Get-CimInstance Win32_Process | ForEach-Object { [pscustomobject]@{ pid = [int]$_.ProcessId; ppid = [int]$_.ParentProcessId; created = if ($_.CreationDate) { $_.CreationDate.ToString('o') } else { '' }; name = $_.Name; cmd = [string]$_.CommandLine } } | ConvertTo-Json -Compress"
    const res = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', script], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, windowsHide: true })
    try {
      const list = JSON.parse(res.stdout || '[]')
      return Array.isArray(list) ? list : [list]
    } catch {
      return []
    }
  }
  const res = spawnSync('ps', ['-eo', 'pid=,ppid=,lstart=,args='], { encoding: 'utf8' })
  return (res.stdout || '').split('\n').filter(Boolean).map((line) => {
    const m = line.trim().match(/^(\d+)\s+(\d+)\s+(\w{3}\s+\w{3}\s+\d+\s+[\d:]+\s+\d{4})\s+(.*)$/)
    return m ? { pid: +m[1], ppid: +m[2], created: m[3], name: m[4].split(' ')[0], cmd: m[4] } : null
  }).filter(Boolean)
}

/** PID qui écoutent sur un port (lecture seule). */
function portOwners(port) {
  if (isWindows) {
    const res = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', `Get-NetTCPConnection -LocalPort ${port} -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique`], { encoding: 'utf8', windowsHide: true })
    return (res.stdout || '').split(/\r?\n/).map((s) => parseInt(s, 10)).filter((n) => n > 0)
  }
  const res = spawnSync('lsof', ['-t', `-iTCP:${port}`, '-sTCP:LISTEN'], { encoding: 'utf8' })
  return (res.stdout || '').split('\n').map((s) => parseInt(s, 10)).filter((n) => n > 0)
}

const norm = (s) => String(s || '').toLowerCase().replace(/\//g, '\\')
/**
 * Serveurs explicitement de CETTE copie de SoxialConnect, reconnus à leur signature exacte (et non
 * à la seule présence du chemin, qu'on trouve aussi dans un éditeur ou un terminal) :
 * - serveur PHP de l'API : php … -S localhost:8000 <backend>\vendor\…\server.php ;
 * - Vite du site : node <frontend>\node_modules\…\vite\bin\vite.js.
 */
const hasProjectPath = (p) => {
  const cmd = norm(p.cmd)
  return (/^php/i.test(p.name) && /\s-s\s+localhost:8000\s/.test(cmd) && cmd.includes(norm(backend)))
    || (/^node/i.test(p.name) && /vite\\bin\\vite\.js/.test(cmd) && cmd.includes(norm(frontend)))
}
const isLauncher = (p) => /node/i.test(p.name) && /scripts[\\/]dev\.mjs/i.test(p.cmd)
/** Jamais arrêtés, même s'ils descendent du lanceur (navigateur ouvert au démarrage). */
const isProtected = (p) => /^(chrome|firefox|msedge|code)(\.exe)?$/i.test(p.name)
const alive = (snap, rec) => snap.find((p) => p.pid === rec.pid && (!rec.created || p.created === rec.created))

function descendants(snap, pid) {
  const out = []
  const queue = [pid]
  while (queue.length) {
    const current = queue.shift()
    for (const p of snap) {
      if (p.ppid === current && p.pid !== pid && !out.includes(p) && !isProtected(p)) {
        out.push(p)
        queue.push(p.pid)
      }
    }
  }
  return out
}

function readState() {
  try {
    return JSON.parse(readFileSync(STATE_FILE, 'utf8'))
  } catch {
    return null
  }
}
function writeState(state) {
  try {
    mkdirSync(path.dirname(STATE_FILE), { recursive: true })
    writeFileSync(STATE_FILE, JSON.stringify(state, null, 2))
  } catch { /* état facultatif : le lanceur fonctionne sans */ }
}

/**
 * Instances SoxialConnect en cours (lanceur vivant + ses processus) et processus orphelins
 * (de ce projet, sans lanceur vivant). Rien n'est arrêté ici.
 */
function inspect(snap) {
  const state = readState()
  const instances = []
  for (const launcher of snap.filter(isLauncher)) {
    if (launcher.pid === process.pid) continue
    const tree = descendants(snap, launcher.pid)
    const recorded = state?.launcher && state.launcher.pid === launcher.pid && state.launcher.created === launcher.created
    if (recorded || tree.some(hasProjectPath)) instances.push({ launcher, tree })
  }
  const owned = new Set(instances.flatMap((i) => [i.launcher.pid, ...i.tree.map((p) => p.pid)]))
  const orphans = new Map()
  // Processus enregistrés par un lanceur précédent (même PID ET même date de création).
  for (const rec of state?.processes ?? []) {
    const p = alive(snap, rec)
    if (p && !owned.has(p.pid) && p.pid !== process.pid && !isProtected(p)) orphans.set(p.pid, p)
  }
  // Processus dont la commande contient le chemin du projet (serveur PHP de l'API, Vite).
  for (const p of snap.filter(hasProjectPath)) {
    if (!owned.has(p.pid) && p.pid !== process.pid && !isLauncher(p)) orphans.set(p.pid, p)
  }
  // Leurs descendants appartiennent aussi au projet.
  for (const p of [...orphans.values()]) for (const d of descendants(snap, p.pid)) if (!owned.has(d.pid)) orphans.set(d.pid, d)
  return { instances, orphans: [...orphans.values()] }
}

/**
 * Arrête UNIQUEMENT les PID donnés, un par un (pas de « /T » : un arbre complet pourrait contenir
 * un processus protégé, comme le navigateur). Les listes sont établies en amont (arbre parcouru,
 * processus protégés exclus).
 */
function kill(pids) {
  for (const pid of pids) {
    if (pid === process.pid) continue
    if (isWindows) spawnSync('taskkill', ['/PID', String(pid), '/F'], { stdio: 'ignore', windowsHide: true })
    else try { process.kill(pid, 'SIGTERM') } catch { /* déjà arrêté */ }
  }
}

const label = (p) => {
  const cmd = p.cmd.replace(/\s+/g, ' ').trim() || `${p.name} (processus enfant)`
  return `PID ${p.pid} — ${cmd.length > 110 ? `${cmd.slice(0, 110)}…` : cmd}`
}
const role = (p) => /queue:(work|listen)/.test(p.cmd) ? 'worker' : /schedule:work/.test(p.cmd) ? 'planificateur'
  : /artisan serve|-S localhost:8000/.test(p.cmd) ? 'API' : /vite/i.test(p.cmd) ? 'site (Vite)' : null

/** Arrête des instances et des orphelins de CE projet, puis vérifie. */
async function stopProject(found, reason) {
  const targets = [...found.instances.map((i) => i.launcher), ...found.orphans]
  if (!targets.length) return true
  say('info', reason, color.warn)
  for (const i of found.instances) say('info', `  instance : lanceur PID ${i.launcher.pid} (+${i.tree.length} processus)`, color.warn)
  for (const o of found.orphans) say('info', `  orphelin : ${label(o)}`, color.warn)
  // Lanceur d'abord (il ne réagit plus à l'arrêt de ses enfants), puis son arbre, puis les orphelins.
  kill([...found.instances.flatMap((i) => [i.launcher.pid, ...i.tree.map((p) => p.pid)]), ...found.orphans.map((p) => p.pid)])
  const gone = await waitFor(async () => {
    const left = inspect(snapshot())
    return left.instances.length === 0 && left.orphans.length === 0
  }, 15_000)
  if (gone) rmSync(STATE_FILE, { force: true })
  say('info', gone ? 'Instance précédente arrêtée.' : 'Certains processus n\'ont pas pu être arrêtés.', gone ? color.info : color.err)
  return gone
}

// --- 0. État (npm run dev:status, lecture seule) et arrêt demandé (npm run dev:stop) -------------
if (argv.has('--status')) {
  const found = inspect(snapshot())
  if (!found.instances.length && !found.orphans.length) say('info', 'Aucune instance SoxialConnect en cours.')
  for (const i of found.instances) {
    say('info', `Instance : lanceur PID ${i.launcher.pid} (démarré ${new Date(i.launcher.created).toLocaleTimeString('fr-FR')})`)
    for (const p of i.tree) say('info', `  ${role(p) ? `[${role(p)}] ` : ''}${label(p)}`)
  }
  for (const o of found.orphans) say('info', `Orphelin : ${label(o)}`, color.warn)
  for (const [name, port] of PORTS) say('info', `Port ${port} (${name}) : ${(await portOpen(port)) ? `occupé par PID ${portOwners(port).join(', ')}` : 'libre'}`)
  process.exit(0)
}

if (mode === 'stop') {
  const found = inspect(snapshot())
  if (!found.instances.length && !found.orphans.length) {
    say('info', 'Aucune instance SoxialConnect en cours.')
    rmSync(STATE_FILE, { force: true })
    process.exit(0)
  }
  process.exit((await stopProject(found, 'Arrêt de SoxialConnect :')) ? 0 : 1)
}

// --- 1. Services Laragon ------------------------------------------------------------------------
const services = [['MySQL', 3306], ['Redis', 6379]]
const missing = async () => (await Promise.all(services.map(async ([name, port]) => ((await portOpen(port)) ? null : name)))).filter(Boolean)
const down = await missing()
if (down.length) {
  say('info', `${down.join(' et ')} ne répond pas : ouvrez Laragon et cliquez sur « Start All ». J'attends (2 minutes)…`, color.warn)
  const ok = await waitFor(async () => (await missing()).length === 0, 120_000)
  if (!ok) {
    say('info', `${(await missing()).join(' et ')} toujours injoignable. Démarrez Laragon puis relancez « npm run dev ».`, color.err)
    process.exit(1)
  }
}
say('info', 'MySQL et Redis : OK')

// --- 2. Instance existante, orphelins, ports ----------------------------------------------------
{
  let found = inspect(snapshot())
  if (found.instances.length && mode === 'start') {
    // Déjà lancé (autre fenêtre) : réutilisation, rien n'est démarré en double.
    const tree = found.instances.flatMap((i) => i.tree)
    const roles = new Map()
    for (const p of tree) if (role(p) && !roles.has(role(p))) roles.set(role(p), p)
    const since = new Date(found.instances[0].launcher.created || Date.now()).toLocaleTimeString('fr-FR')
    say('info', `SoxialConnect est déjà lancé dans une autre fenêtre (lanceur PID ${found.instances[0].launcher.pid}, depuis ${since}).`)
    say('info', (await portOpen(8000)) ? 'API déjà active, réutilisation de l\'instance existante (http://localhost:8000).' : 'API : non joignable dans l\'instance existante.', (await portOpen(8000)) ? color.info : color.warn)
    say('info', (await portOpen(5173)) ? `Site déjà actif, réutilisation de l'instance existante (${APP_URL}).` : 'Site : non joignable dans l\'instance existante.', (await portOpen(5173)) ? color.info : color.warn)
    for (const r of ['worker', 'planificateur']) say('info', roles.has(r) ? `${r[0].toUpperCase()}${r.slice(1)} déjà actif (PID ${roles.get(r).pid}), réutilisé.` : `${r} : absent de l'instance existante.`, roles.has(r) ? color.info : color.warn)
    say('info', 'Rien n\'a été démarré en double. Pour relancer : « npm run dev:restart » ; pour arrêter : « npm run dev:stop » (ou Ctrl + C dans la fenêtre d\'origine).')
    process.exit(0)
  }
  if (found.instances.length && mode === 'restart') {
    if (!(await stopProject(found, 'Redémarrage : arrêt de l\'instance en cours…'))) process.exit(1)
    found = inspect(snapshot())
  }
  if (found.orphans.length && !(await stopProject({ instances: [], orphans: found.orphans }, 'Processus SoxialConnect orphelins d\'un lancement précédent (sans fenêtre) : nettoyage…'))) {
    process.exit(1)
  }

  // Port encore occupé : par un programme qui n'est PAS SoxialConnect → jamais arrêté.
  for (const [name, port] of PORTS) {
    if (!(await portOpen(port))) continue
    const snap = snapshot()
    const owners = portOwners(port).map((pid) => snap.find((p) => p.pid === pid)).filter(Boolean)
    say('info', `Le port ${port} (${name}) est occupé par un autre programme que SoxialConnect :`, color.err)
    for (const o of owners) say('info', `  ${label(o)}`, color.err)
    say('info', 'Il n\'a pas été arrêté. Fermez-le (ou libérez le port), puis relancez « npm run dev ».', color.err)
    process.exit(1)
  }
}

// --- 3. Démarrage ---------------------------------------------------------------------------------
const self = snapshot().find((p) => p.pid === process.pid)
const state = { root, launcher: { pid: process.pid, created: self?.created ?? '' }, startedAt: new Date().toISOString(), processes: [] }
writeState(state)

const children = []
function run(tag, command, args, cwd, extraEnv = {}) {
  // Windows : `php` et `npm` sont des scripts .cmd, lancés via cmd.exe (sans l'option shell dépréciée).
  const [bin, cmdArgs] = isWindows ? ['cmd.exe', ['/d', '/s', '/c', [command, ...args].join(' ')]] : [command, args]
  // Pas de windowsHide : sous Windows, les enfants doivent partager la console (artisan serve plante
  // sans console, et Ctrl + C doit leur parvenir).
  const child = spawn(bin, cmdArgs, { cwd, env: { ...process.env, ...extraEnv } })
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

/** Enregistre tous les processus lancés (et leurs enfants) : base du nettoyage des orphelins. */
function recordProcesses() {
  const snap = snapshot()
  const tree = descendants(snap, process.pid)
  const known = new Map((state.processes ?? []).map((p) => [p.pid, p]))
  for (const p of tree) known.set(p.pid, { pid: p.pid, created: p.created, cmd: p.cmd.slice(0, 200) })
  state.processes = [...known.values()].filter((p) => alive(snap, p))
  writeState(state)
}

let stopping = false
function stop(code = 0) {
  if (stopping) return
  stopping = true
  // Arbre réel des processus lancés + ceux enregistrés (même si leur parent a déjà disparu).
  const snap = snapshot()
  const pids = new Set(descendants(snap, process.pid).map((p) => p.pid))
  for (const rec of state.processes ?? []) if (alive(snap, rec)) pids.add(rec.pid)
  for (const child of children) if (child.exitCode === null && child.pid) pids.add(child.pid)
  for (const p of snap) if (pids.has(p.pid) && isProtected(p)) pids.delete(p.pid)
  kill([...pids])
  rmSync(STATE_FILE, { force: true })
  say('info', `SoxialConnect arrêté (${pids.size} processus).`)
  process.exit(code)
}
process.on('SIGINT', () => { say('info', 'Arrêt…'); stop(0) })
process.on('SIGTERM', () => stop(0))
process.on('SIGHUP', () => stop(0)) // fenêtre fermée

say('info', 'Démarrage de l\'API (http://localhost:8000)…')
// Réglages PHP du projet (GD, limites d'upload, OPcache) sans toucher au php.ini système.
const phpEnv = { PHP_INI_SCAN_DIR: path.join(backend, 'dev') }
run('api', 'php', ['artisan', 'serve', '--host=localhost', '--port=8000'], backend, phpEnv)

// Worker de DÉVELOPPEMENT : files « publishing » (moteur de publication), « default » (Telegram,
// notifications) et « media ». `queue:listen` exécute chaque job dans un processus PHP neuf : le
// code modifié est pris en compte sans redémarrage, et --timeout est réellement appliqué (même sous
// Windows). --timeout inférieur à retry_after (360 s) ; chaque job fixe lui-même ses tentatives.
say('info', 'Démarrage du worker de file d\'attente (rechargement du code à chaque job)…')
run('worker', 'php', ['artisan', 'queue:listen', 'redis', '--queue=publishing,default,media', '--sleep=1', '--timeout=300', '--memory=256'], backend, phpEnv)

// Workers optionnels (--workers=ai,video). « video » exécute les rendus du Video Studio
// (RenderVideoJob) : sans lui, un rendu demandé reste « en file ». Sans FFmpeg, le worker démarre
// quand même et chaque rendu échoue proprement (RENDERER_UNAVAILABLE).
for (const w of extraWorkers) {
  if (!OPTIONAL_WORKERS.includes(w)) say('warn', `Worker inconnu ignoré : « ${w} » (valeurs possibles : ${OPTIONAL_WORKERS.join(', ')}).`, color.warn)
}
if (extraWorkers.has('ai')) {
  // Appels IA bornés par config ai.timeout (60 s) : --timeout bien inférieur au retry_after de « redis » (360 s).
  say('info', 'Démarrage du worker IA (file « ai »)…')
  run('worker-ai', 'php', ['artisan', 'queue:listen', 'redis', '--queue=ai', '--sleep=1', '--timeout=120', '--memory=256'], backend, phpEnv)
}
if (extraWorkers.has('video')) {
  // Connexion « redis-video » : retry_after (1 900 s) supérieur au plus long rendu (--timeout, video.render.timeout_seconds).
  say('info', 'Démarrage du worker vidéo (file « video », connexion redis-video)…')
  run('worker-video', 'php', ['artisan', 'queue:listen', 'redis-video', '--queue=video', '--sleep=3', '--timeout=1800', '--memory=512'], backend, phpEnv)
}

// Planificateur Laravel (chaque minute, dans un processus neuf) : publications programmées arrivées
// à échéance, reprise des envois bloqués, purge des médias supprimés (03:30).
say('info', 'Démarrage du planificateur…')
run('cron', 'php', ['artisan', 'schedule:work'], backend, phpEnv)

say('info', 'Démarrage du site (http://localhost:5173)…')
run('web', 'npm', ['run', 'dev', '--', '--port', '5173', '--strictPort', '--host', 'localhost'], frontend)

setTimeout(recordProcesses, 5_000)
setInterval(() => { if (!stopping) recordProcesses() }, 30_000).unref()

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
recordProcesses()
if (ready) {
  say('info', `SoxialConnect est prêt : ${APP_URL}  (Ctrl + C pour tout arrêter)`)
  if (!openBrowser) {
    // Navigateur non ouvert (--no-browser).
  } else if (isWindows) {
    // Chrome en priorité, sinon Firefox, sinon le navigateur par défaut de Windows.
    const pf = [process.env.ProgramFiles, process.env['ProgramFiles(x86)'], process.env.LOCALAPPDATA].filter(Boolean)
    const candidates = pf.flatMap((dir) => [path.join(dir, 'Google', 'Chrome', 'Application', 'chrome.exe'), path.join(dir, 'Mozilla Firefox', 'firefox.exe')])
    const browser = candidates.sort((a) => (a.includes('chrome') ? -1 : 1)).find((p) => existsSync(p))
    // Ouvert via « start » : le navigateur n'est pas un enfant du lanceur (jamais fermé par Ctrl + C).
    spawn('cmd', ['/d', '/c', 'start', '""', ...(browser ? [`"${browser}"`] : []), APP_URL], { stdio: 'ignore', detached: true, windowsVerbatimArguments: true }).unref()
  } else spawn(process.platform === 'darwin' ? 'open' : 'xdg-open', [APP_URL], { stdio: 'ignore', detached: true }).unref()
} else {
  say('info', `Le démarrage prend plus de temps que prévu. Ouvrez ${APP_URL} dans quelques instants.`, color.warn)
}
