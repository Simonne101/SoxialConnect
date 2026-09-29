// Service d'authentification.
// - Backend branché (VITE_API_URL défini) : Laravel Fortify + Sanctum (session HttpOnly).
// - Sinon : mode démonstration (aucune vérification réelle, comme en phase frontend).
import type { UserProfile } from "@/types"
import { ApiError, http, isApiEnabled } from "@/lib/http"
import { delay, store } from "./mockStore"

/** Utilisateur tel que renvoyé par l'API (backend/app/Http/Resources/UserResource.php). */
interface ApiUser {
  id: string
  name: string
  email: string
  email_verified: boolean
  headline: string | null
  bio: string | null
  timezone: string
  locale: string
  two_factor_enabled: boolean
}

export function toUserProfile(u: ApiUser): UserProfile {
  return {
    id: u.id,
    name: u.name,
    email: u.email,
    role: u.headline ?? "",
    bio: u.bio ?? "",
    timezone: u.timezone,
    locale: u.locale === "en" ? "en" : "fr",
    emailVerified: u.email_verified,
    twoFactorEnabled: u.two_factor_enabled,
  }
}

export interface LoginInput {
  email: string
  password: string
  remember: boolean
}

export interface RegisterInput {
  name: string
  email: string
  password: string
  passwordConfirmation: string
}

export async function login(input: LoginInput): Promise<UserProfile> {
  if (!isApiEnabled) {
    await delay(600)
    return structuredClone(store.user)
  }
  const res = await http.post<ApiUser>("/auth/login", { email: input.email, password: input.password, remember: input.remember })
  return toUserProfile(res!.data)
}

export async function register(input: RegisterInput): Promise<UserProfile> {
  if (!isApiEnabled) {
    await delay(700)
    return { ...structuredClone(store.user), name: input.name, email: input.email, emailVerified: false }
  }
  const res = await http.post<ApiUser>("/auth/register", {
    name: input.name,
    email: input.email,
    password: input.password,
    password_confirmation: input.passwordConfirmation,
  })
  return toUserProfile(res!.data)
}

export async function logout(): Promise<void> {
  if (!isApiEnabled) return
  try {
    await http.post("/auth/logout")
  } catch (e) {
    // Session déjà expirée : l'utilisateur est de toute façon déconnecté.
    if (!(e instanceof ApiError && e.status === 401)) throw e
  }
}

export async function forgotPassword(email: string): Promise<void> {
  if (!isApiEnabled) {
    await delay(600)
    return
  }
  await http.post("/auth/forgot-password", { email })
}

/** Utilisateur courant ; ApiError 401 si aucune session. */
export async function currentUser(): Promise<UserProfile> {
  if (!isApiEnabled) {
    await delay(150)
    return structuredClone(store.user)
  }
  const res = await http.get<ApiUser>("/users/me")
  return toUserProfile(res!.data)
}

export async function updateCurrentUser(patch: Partial<UserProfile>): Promise<UserProfile> {
  if (!isApiEnabled) {
    await delay(500)
    Object.assign(store.user, patch)
    return structuredClone(store.user)
  }
  const body: Record<string, unknown> = {}
  if (patch.name !== undefined) body.name = patch.name
  if (patch.role !== undefined) body.headline = patch.role || null
  if (patch.bio !== undefined) body.bio = patch.bio || null
  if (patch.timezone !== undefined) body.timezone = patch.timezone
  const res = await http.patch<ApiUser>("/users/me", body)
  return toUserProfile(res!.data)
}
