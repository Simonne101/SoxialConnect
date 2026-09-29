// Client HTTP de l'API Laravel (/api/v1) — authentification par session Sanctum (SPA).
// - cookies envoyés (credentials: "include") ; aucun token stocké dans le navigateur ;
// - protection CSRF : cookie XSRF-TOKEN obtenu via /sanctum/csrf-cookie, renvoyé dans X-XSRF-TOKEN ;
// - erreurs normalisées { message, errors } converties en ApiError.

const API_URL = (import.meta.env.VITE_API_URL ?? "").replace(/\/+$/, "")

/** Vrai si le frontend est branché sur le backend (sinon : données de démonstration). */
export const isApiEnabled = API_URL !== ""

export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }

  /** Premier message d'erreur d'un champ (validation 422). */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

/**
 * Message affichable pour une erreur d'API : le premier message de validation (422), le message
 * métier du serveur (401, 403, 404, 409, 419, 429, réseau), sinon le message de repli.
 */
export function apiErrorMessage(error: unknown, fallback = "Une erreur est survenue. Réessayez."): string {
  if (!(error instanceof ApiError)) return fallback
  if (error.status === 422) return Object.values(error.errors)[0]?.[0] ?? error.message
  if (error.status >= 500) return fallback
  return error.message || fallback
}

export interface ApiEnvelope<T> {
  data: T
  message: string | null
  meta: Record<string, unknown>
}

function readCookie(name: string): string | null {
  const match = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

let csrfReady: Promise<void> | null = null

/** Initialise le cookie CSRF (une fois, puis à nouveau après une erreur 419). */
export function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && csrfReady) return csrfReady
  csrfReady = fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: "include", headers: { Accept: "application/json" } }).then((r) => {
    if (!r.ok) throw new ApiError(r.status, "Impossible de joindre le serveur.")
  })
  csrfReady.catch(() => {
    csrfReady = null
  })
  return csrfReady
}

type Method = "GET" | "POST" | "PUT" | "PATCH" | "DELETE"

async function request<T>(method: Method, path: string, body?: unknown, retried = false): Promise<ApiEnvelope<T> | null> {
  const mutating = method !== "GET"
  if (mutating) await ensureCsrfCookie()

  const headers: Record<string, string> = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }
  if (body !== undefined) headers["Content-Type"] = "application/json"
  const xsrf = readCookie("XSRF-TOKEN")
  if (mutating && xsrf) headers["X-XSRF-TOKEN"] = xsrf

  let response: Response
  try {
    response = await fetch(`${API_URL}/api/v1${path}`, {
      method,
      headers,
      credentials: "include",
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  } catch {
    throw new ApiError(0, "Impossible de joindre le serveur. Vérifiez votre connexion.")
  }

  // Jeton CSRF expiré : on le renouvelle une fois puis on rejoue la requête.
  if (response.status === 419 && !retried) {
    await ensureCsrfCookie(true)
    return request<T>(method, path, body, true)
  }

  if (response.status === 204) return null

  let json: unknown = null
  try {
    json = await response.json()
  } catch {
    json = null
  }

  if (!response.ok) {
    const payload = (json ?? {}) as { message?: string; errors?: Record<string, string[]> }
    throw new ApiError(response.status, payload.message ?? "Une erreur est survenue.", payload.errors ?? {})
  }

  return json as ApiEnvelope<T>
}

/**
 * Envoi multipart avec suivi de progression (XMLHttpRequest : fetch ne fournit pas la progression
 * d'envoi). Même protection CSRF et même format d'erreurs que request().
 */
async function upload<T>(path: string, form: FormData, onProgress?: (percent: number) => void, retried = false): Promise<ApiEnvelope<T>> {
  await ensureCsrfCookie()
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open("POST", `${API_URL}/api/v1${path}`)
    xhr.withCredentials = true
    xhr.setRequestHeader("Accept", "application/json")
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest")
    const xsrf = readCookie("XSRF-TOKEN")
    if (xsrf) xhr.setRequestHeader("X-XSRF-TOKEN", xsrf)
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) onProgress?.(Math.min(100, Math.round((e.loaded / e.total) * 100)))
    }
    xhr.onerror = () => reject(new ApiError(0, "Impossible de joindre le serveur. Vérifiez votre connexion."))
    xhr.onload = () => {
      if (xhr.status === 419 && !retried) {
        ensureCsrfCookie(true).then(() => upload<T>(path, form, onProgress, true)).then(resolve, reject)
        return
      }
      let json: unknown = null
      try {
        json = JSON.parse(xhr.responseText)
      } catch {
        json = null
      }
      if (xhr.status === 413) {
        reject(new ApiError(413, "Fichier trop volumineux pour le serveur."))
        return
      }
      if (xhr.status < 200 || xhr.status >= 300) {
        const payload = (json ?? {}) as { message?: string; errors?: Record<string, string[]> }
        reject(new ApiError(xhr.status, payload.message ?? "L'envoi a échoué.", payload.errors ?? {}))
        return
      }
      resolve(json as ApiEnvelope<T>)
    }
    xhr.send(form)
  })
}

export const http = {
  upload,
  get: <T>(path: string) => request<T>("GET", path),
  post: <T>(path: string, body?: unknown) => request<T>("POST", path, body),
  put: <T>(path: string, body?: unknown) => request<T>("PUT", path, body),
  patch: <T>(path: string, body?: unknown) => request<T>("PATCH", path, body),
  delete: <T>(path: string) => request<T>("DELETE", path),
}
