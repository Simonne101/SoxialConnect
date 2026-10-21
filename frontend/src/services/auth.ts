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

/** Mot de passe valide, mais le compte exige son second facteur (POST /auth/two-factor-challenge). */
export class TwoFactorRequired extends Error {
  constructor() {
    super("Code de double authentification requis.")
  }
}

export async function login(input: LoginInput): Promise<UserProfile> {
  if (!isApiEnabled) {
    await delay(600)
    return structuredClone(store.user)
  }
  const res = await http.post<ApiUser>("/auth/login", { email: input.email, password: input.password, remember: input.remember })
  // Fortify répond { two_factor: true } (hors enveloppe) tant que le code n'a pas été saisi.
  if ((res as { two_factor?: boolean } | null)?.two_factor === true) throw new TwoFactorRequired()
  return toUserProfile(res!.data)
}

/** Second facteur : code de l'application (6 chiffres) ou code de secours (usage unique). */
export async function twoFactorChallenge(input: { code?: string; recoveryCode?: string }): Promise<UserProfile> {
  const body = input.recoveryCode ? { recovery_code: input.recoveryCode } : { code: input.code }
  const res = await http.post<ApiUser>("/auth/two-factor-challenge", body)
  return toUserProfile(res!.data)
}

// --- Gestion de la double authentification (Paramètres > Sécurité) ------------------------------
// Toutes ces routes exigent une confirmation récente du mot de passe : sinon ApiError 423.

export const PASSWORD_CONFIRMATION_REQUIRED = 423

export async function confirmPassword(password: string): Promise<void> {
  await http.post("/auth/user/confirm-password", { password })
}

export async function enableTwoFactor(): Promise<void> {
  await http.post("/auth/user/two-factor-authentication")
}

/** QR code (SVG à afficher comme image) et clé à saisir à la main dans l'application. */
export async function twoFactorSetup(): Promise<{ svg: string; secretKey: string }> {
  const qr = (await http.get("/auth/user/two-factor-qr-code")) as unknown as { svg: string }
  const key = (await http.get("/auth/user/two-factor-secret-key")) as unknown as { secretKey: string }
  return { svg: qr.svg, secretKey: key.secretKey }
}

export async function confirmTwoFactor(code: string): Promise<void> {
  await http.post("/auth/user/confirmed-two-factor-authentication", { code })
}

export async function recoveryCodes(): Promise<string[]> {
  return (await http.get("/auth/user/two-factor-recovery-codes")) as unknown as string[]
}

export async function regenerateRecoveryCodes(): Promise<string[]> {
  await http.post("/auth/user/two-factor-recovery-codes")
  return recoveryCodes()
}

export async function disableTwoFactor(): Promise<void> {
  await http.delete("/auth/user/two-factor-authentication")
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
/** Renvoie le lien de vérification de l'adresse e-mail (POST /auth/email/verification-notification). */
export async function resendVerificationEmail(): Promise<void> {
  if (!isApiEnabled) {
    await delay(400)
    return
  }
  await http.post("/auth/email/verification-notification")
}

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
