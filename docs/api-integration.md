# Intégrations des réseaux sociaux

> **Avertissement.** Les APIs des plateformes changent souvent : versions, scopes, quotas, tarifs, dépréciations.
> Ce document liste ce qui est **connu à la date de rédaction (2026-09-28) d'après la documentation publique
> connue de l'auteur**. Il n'a **pas** été revérifié en ligne pendant la phase 0.
> **Toute ligne marquée `TO_VERIFY` doit être confirmée dans la documentation officielle avant l'implémentation
> de l'adaptateur (phase 3/5).** Aucune capacité `TO_VERIFY` n'est activée en production.
> Seules les **APIs officielles** sont autorisées : ni scraping, ni automatisation de navigateur, ni API non officielle.

---

## 1. Matrice des capacités (hypothèses de départ)

Légende : ✅ supporté (sous conditions) · ❌ non supporté par l'API officielle · 🔒 soumis à une revue ou un audit · ❓ `TO_VERIFY`

| Capacité | Facebook (Page) | Instagram (pro) | TikTok | YouTube | LinkedIn | X | Pinterest | Threads |
|---|---|---|---|---|---|---|---|---|
| Texte seul | ✅ | ❌ (média requis) | ❌ | ❌ | ✅ | ✅ | ❌ (image ou vidéo requise) | ✅ |
| Image | ✅ | ✅ | ❓ (photo post) | ❌ | ✅ | ✅ | ✅ | ✅ |
| Carrousel / multi-images | ❓ | ✅ | ❓ | ❌ | ❓ | ✅ (≤ 4) ❓ | ❓ | ✅ ❓ |
| Vidéo | ✅ | ✅ (Reels) | ✅ 🔒 | ✅ 🔒 | ✅ | ✅ | ✅ | ✅ |
| Stories | ❓ | ✅ ❓ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Reels / Shorts | ✅ ❓ (Reels de Page) | ✅ | n/a (format natif) | ❓ (Shorts détectés automatiquement) | ❌ | ❌ | ❌ | ❌ |
| Programmation native | ✅ ❓ | ❌ | ❌ | ✅ (`publishAt`) | ❌ ❓ | ❌ | ❓ | ❌ |
| Analytics compte | ✅ ❓ (métriques dépréciées récemment) | ✅ | ❓ (limitées) | ✅ (YouTube Analytics API) | 🔒 (pages d'organisation) | ❓ (selon l'offre) | ✅ (comptes business) | ✅ |
| Analytics post | ✅ ❓ | ✅ | ✅ ❓ (compteurs publics) | ✅ | 🔒 | ✅ ❓ | ✅ | ✅ |
| Webhooks | ✅ | ✅ | ❓ | ❓ (PubSubHubbub, limité) | ❓ | ❓ (Account Activity, offre payante) | ❓ | ❓ |
| Publicité | hors V1 | hors V1 | hors V1 | hors V1 | hors V1 | hors V1 | hors V1 | hors V1 |

**Conséquence produit** : le composer doit être piloté par ces capacités (sélection du format, champs requis,
avertissements). La maquette affiche 8 plateformes au même niveau : c'est vrai pour la connexion, pas pour les formats.

## 2. Contraintes transverses

1. **Revue ou audit d'application** obligatoire presque partout pour publier au nom de tiers (Meta App Review +
   Business Verification, audit TikTok, vérification OAuth Google, accès Community Management LinkedIn, accès Standard Pinterest).
   **Délais longs et incertains : risque n°1 du planning**. Les soumissions doivent démarrer dès la phase 3 (voir la roadmap).
2. Pré-requis de soumission : politique de confidentialité, CGU, domaine vérifié, vidéo de démonstration (screencast)
   de chaque permission, callback de suppression des données (Meta). **Ces pages sont absentes de la maquette.**
3. **Médias par URL publique** : Instagram, Threads, Pinterest (images) et TikTok (`PULL_FROM_URL`) récupèrent le média
   depuis une URL HTTPS publique → URLs signées courtes (ADR-012) ; TikTok exige en plus une vérification du domaine.
4. **Pas d'idempotence** côté plateformes → gestion des tentatives `unknown` et réconciliation (architecture.md §4.3).
5. **Comptes personnels** : Facebook ne permet pas de publier sur un profil personnel via l'API ; Instagram exige un
   compte professionnel (Business ou Creator). L'UX doit l'expliquer dès l'onboarding.
6. **Durée des tokens** très variable : refresh préventif et statut `expired` explicite dans l'interface.
7. **Coûts** : l'API X est payante (modèle tarifaire à confirmer : `TO_VERIFY`). Cet impact budgétaire doit être validé avant la phase 5.

## 3. Fiches par plateforme

Chaque fiche utilise les champs : OAuth · Scopes · Endpoints · Publication · Médias · Analytics · Webhooks · Limitations · Statut.
**Statut de l'intégration** : `NOT_STARTED` pour toutes les plateformes (phase 0).

### 3.1 Facebook (Pages)
| Champ | Contenu |
|---|---|
| API | Meta Graph API, version courante `vXX.0` — `TO_VERIFY` |
| OAuth | Facebook Login (for Business) ; token utilisateur → échange contre un token longue durée → **page access tokens** via `/me/accounts` |
| Scopes | `pages_show_list`, `pages_manage_posts`, `pages_read_engagement`, `read_insights` ; `business_management` si Business Manager — `TO_VERIFY` (noms et nécessité) |
| Endpoints | `POST /{page-id}/feed` (texte, lien), `/{page-id}/photos`, `/{page-id}/videos` (upload repris), Reels de Page via `/{page-id}/video_reels` — `TO_VERIFY` |
| Publication | Texte, lien, photo(s), vidéo ; `published=false` + `scheduled_publish_time` pour la programmation native (fenêtre min/max `TO_VERIFY`) |
| Médias | Upload direct ou par URL ; limites de taille et de durée `TO_VERIFY` |
| Analytics | `/{page-id}/insights`, `/{post-id}/insights` ; **Meta a déprécié plusieurs métriques de Page (impressions…) au profit de nouvelles (« views »)** — liste exacte `TO_VERIFY` |
| Webhooks | Webhooks Meta (objet `page`) : vérification `hub.challenge`, signature `X-Hub-Signature-256` |
| Limitations | Pas de publication sur un profil personnel ; API Groups dépréciée ; App Review + Business Verification ; rate limiting BUC par page |
| Obligatoire | Callback de désautorisation et callback de suppression des données (`signed_request`) |

### 3.2 Instagram (comptes professionnels)
| Champ | Contenu |
|---|---|
| API | Instagram Platform (Graph) — deux modes : **Instagram API with Instagram Login** (sans Page Facebook) ou **with Facebook Login** (compte lié à une Page) — choix `TO_VERIFY` en phase 3 |
| Scopes | Instagram Login : `instagram_business_basic`, `instagram_business_content_publish`, `instagram_business_manage_insights` ; Facebook Login : `instagram_basic`, `instagram_content_publish`, `instagram_manage_insights`, `pages_show_list` — `TO_VERIFY` |
| Endpoints | `POST /{ig-user-id}/media` (création du container) → polling `GET /{container-id}?fields=status_code` → `POST /{ig-user-id}/media_publish` ; `GET /{ig-user-id}/content_publishing_limit` |
| Publication | Image, carrousel (≤ 10 ❓), Reels (`media_type=REELS`), Stories (`media_type=STORIES`, `TO_VERIFY` selon le type de compte) ; **texte seul impossible** |
| Médias | Récupérés par **URL publique** ; JPEG (image) ; ratios et durées acceptés `TO_VERIFY` |
| Analytics | `/{ig-user-id}/insights`, `/{ig-media-id}/insights` ; métriques renommées ou dépréciées récemment — `TO_VERIFY` |
| Webhooks | Commentaires, mentions (hors V1 côté produit) |
| Limitations | Comptes Business ou Creator uniquement ; **quota de publications API par 24 h** (valeur `TO_VERIFY`) ; pas de programmation native |

### 3.3 TikTok
| Champ | Contenu |
|---|---|
| API | TikTok for Developers — **Content Posting API** (Direct Post / Upload) + Login Kit (OAuth v2) |
| Scopes | `user.info.basic`, `video.publish` (Direct Post), `video.upload` (brouillon envoyé dans l'app) ; `video.list` (lecture) — `TO_VERIFY` |
| Endpoints | `POST /v2/post/publish/creator_info/query/` (obligatoire avant publication), `POST /v2/post/publish/video/init/`, `POST /v2/post/publish/status/fetch/`, photos : `/v2/post/publish/content/init/` — `TO_VERIFY` |
| Publication | Vidéo ; photos `TO_VERIFY` ; **tant que l'application n'est pas auditée, les contenus sont limités à la visibilité privée (`SELF_ONLY`)** et le nombre d'utilisateurs est plafonné — `TO_VERIFY` |
| Médias | `FILE_UPLOAD` (par morceaux) ou `PULL_FROM_URL` (domaine vérifié) |
| **Exigences UX** | TikTok impose des règles d'interface : afficher le compte cible, laisser l'utilisateur **choisir explicitement** la visibilité (sans valeur par défaut), les options commentaires/duo/stitch selon `creator_info`, la déclaration de contenu commercial, et le consentement. **Impact direct sur le composer, absent de la maquette** — `TO_VERIFY` |
| Analytics | Compteurs par vidéo via `video.list` / `video.query` ; analytics de compte limitées — `TO_VERIFY` |
| Webhooks | Événements de statut de publication — `TO_VERIFY` |
| Limitations | Pas de programmation native ; quotas de publication par jour et par créateur `TO_VERIFY` ; durée du token d'accès courte (refresh) `TO_VERIFY` |

### 3.4 YouTube
| Champ | Contenu |
|---|---|
| API | YouTube Data API v3 + YouTube Analytics API ; Google OAuth 2.0 |
| Scopes | `https://www.googleapis.com/auth/youtube.upload`, `…/youtube.readonly`, `…/yt-analytics.readonly` — scopes sensibles → **vérification OAuth Google** |
| Endpoints | `videos.insert` (upload repris), `thumbnails.set`, `channels.list`, `videos.list` ; Analytics : `reports.query` |
| Publication | Vidéo uniquement ; `status.privacyStatus` + `status.publishAt` (programmation native) |
| Shorts | Pas d'endpoint dédié : vidéo verticale et courte, classée Short automatiquement (durée max `TO_VERIFY`) |
| Limitations | **Quota quotidien en unités** (10 000 par défaut ❓ ; `videos.insert` coûte cher, ~1 600 ❓) → peu d'uploads par jour sans extension de quota ; **projets non audités : vidéos forcées en privé** — `TO_VERIFY` |
| Webhooks | Notifications push PubSubHubbub (nouvelles vidéos d'une chaîne) — utilité limitée |

### 3.5 LinkedIn
| Champ | Contenu |
|---|---|
| API | LinkedIn Marketing / Community Management API, API REST versionnée (en-tête `LinkedIn-Version: YYYYMM`) — `TO_VERIFY` |
| Scopes | Profil membre : `openid`, `profile`, `w_member_social` (produit « Share on LinkedIn ») ; Pages d'organisation : `w_organization_social`, `r_organization_social`, `rw_organization_admin` (**Community Management API, sur approbation**) — `TO_VERIFY` |
| Endpoints | `POST /rest/posts` ; `POST /rest/images?action=initializeUpload` ; `POST /rest/videos?action=initializeUpload` ; statistiques : `organizationalEntityShareStatistics` — `TO_VERIFY` |
| Publication | Texte, image, multi-images ❓, vidéo, document ❓ ; pas de programmation native ❓ |
| Limitations | Token d'environ 60 jours ; refresh tokens réservés à certains partenaires ❓ ; l'application Community Management peut devoir être dédiée à ce produit ❓ |

### 3.6 X
| Champ | Contenu |
|---|---|
| API | X API v2 |
| OAuth | OAuth 2.0 Authorization Code **avec PKCE** ; `offline.access` pour le refresh token |
| Scopes | `tweet.read`, `tweet.write`, `users.read`, `offline.access`, `media.write` — `TO_VERIFY` |
| Endpoints | `POST /2/tweets` ; upload de média v2 (`/2/media/upload`, l'upload v1.1 étant déprécié) — `TO_VERIFY` |
| Publication | Texte (280 caractères pour les comptes standard ; plus pour les abonnés, `TO_VERIFY`), images (≤ 4), vidéo, threads (réponses chaînées) |
| Analytics | `public_metrics` ; `non_public_metrics` et `organic_metrics` limitées aux 30 derniers jours et au contexte utilisateur — `TO_VERIFY` |
| Limitations | **API payante, quotas par offre ou à l'usage : impact budgétaire à valider** ; pas de programmation native (hors Ads) ; webhooks (Account Activity) réservés aux offres supérieures — `TO_VERIFY` |

### 3.7 Pinterest
| Champ | Contenu |
|---|---|
| API | Pinterest API v5 |
| Scopes | `user_accounts:read`, `boards:read`, `boards:write`, `pins:read`, `pins:write` — `TO_VERIFY` |
| Endpoints | `POST /v5/pins` (tableau obligatoire), `GET /v5/boards`, `POST /v5/media` (vidéo), `GET /v5/user_account/analytics`, `GET /v5/pins/{id}/analytics` |
| Publication | Épingle image (URL ou base64) ou vidéo ; lien de destination ; **choix du tableau requis → absent de la maquette** |
| Limitations | Accès « Trial » restreint avant l'accès « Standard » (sur revue) — `TO_VERIFY` ; analytics réservées aux comptes business |

### 3.8 Threads
| Champ | Contenu |
|---|---|
| API | Threads API (`graph.threads.net`), Meta |
| Scopes | `threads_basic`, `threads_content_publish`, `threads_manage_insights` (+ `threads_read_replies`, `threads_manage_replies` hors V1) — `TO_VERIFY` |
| Endpoints | `POST /{user-id}/threads` (container) → `POST /{user-id}/threads_publish` ; insights `/{media-id}/insights`, `/{user-id}/threads_insights` |
| Publication | Texte (500 caractères ❓), image, vidéo, carrousel ❓ ; médias par URL publique |
| Limitations | Quota de publications par 24 h `TO_VERIFY` ; token longue durée d'environ 60 jours, rafraîchissable ❓ ; maquette : « Bientôt disponible » (`availability = coming_soon` en V1 ?) |

## 4. Variables de configuration

Par plateforme : `<PLATFORM>_CLIENT_ID`, `<PLATFORM>_CLIENT_SECRET`, `<PLATFORM>_REDIRECT_URI`, plus
`META_WEBHOOK_VERIFY_TOKEN`, `<PLATFORM>_API_VERSION` si utile. Voir [`.env.example`](../.env.example).
Facebook, Instagram et Threads peuvent partager une application Meta (ou non, `TO_VERIFY`).

## 5. Checklist de vérification (phase 3, par plateforme)

- [ ] Version d'API et calendrier de dépréciation
- [ ] Scopes exacts et produit ou accès requis
- [ ] Processus de revue ou d'audit, pièces demandées, délai constaté
- [ ] Formats, tailles, durées, ratios acceptés
- [ ] Quotas (appels, publications par 24 h) et coût éventuel
- [ ] Durée de vie des tokens et mécanisme de refresh ou de révocation
- [ ] Mécanisme de webhook et de signature
- [ ] Métriques disponibles et définitions (pour la normalisation analytics)
- [ ] Exigences UX imposées (TikTok notamment)
- [ ] Mise à jour de la matrice §1 et de `capabilities()` dans l'adaptateur
