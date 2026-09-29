# UX / UI

Source : [`Images/maquette.png`](../Images/maquette.png) — **une planche raster unique de 1536×1024 px** qui
regroupe 14 écrans en vignettes, plus [`Images/Image sect-hero.png`](../Images/Image%20sect-hero.png) (illustration du hero).
Il n'existe ni fichier source (Figma ou autre), ni export par écran, ni code HTML ou CSS.

> **Limite importante.** La résolution ne permet pas de mesurer précisément les espacements, rayons et tailles.
> Les valeurs marquées `≈` sont des estimations. Plusieurs textes de la planche sont illisibles ou incohérents
> (dates du calendrier dupliquées, écran « Paramètres » qui montre un upload vidéo) : la maquette a sans doute
> été générée ou retouchée automatiquement. **Il faut demander les écrans en haute définition, ou le fichier source.**

La maquette fixe la **direction artistique** et ne doit pas être remplacée. Les écarts avec le cahier des charges sont listés en §7.

---

## 1. Inventaire des écrans

| # | Écran (titre de la maquette) | Route proposée | Contenu observé |
|---|---|---|---|
| 1 | Landing | `/` | Nav : Accueil, Fonctionnalités, **Tarifs**, Ressources ▾, Se connecter, « Commencer gratuitement » ; hero « Gérez tous vos réseaux sociaux depuis une seule plateforme » ; CTA « Commencer gratuitement », « Voir la démo » ; 8 icônes de réseaux, « + d'autres plateformes bientôt » ; chiffres « 8+ réseaux », « 10k+ créateurs », « 99 % de satisfaction » ; accroche manuscrite |
| 2 | Onboarding — Connectez vos réseaux sociaux | `/onboarding/connect` | Stepper 1 Connecter · 2 Personnaliser · 3 Bienvenue ; grille 4×2 de cartes réseau avec un bouton « Connecter » ; Retour / Suivant → |
| 3 | Connexion | `/login` | Email, mot de passe (afficher/masquer), « Se souvenir de moi », « Mot de passe oublié ? », « Se connecter », **« Ou continuer avec » Google / Facebook**, lien « S'inscrire » |
| 4 | Créer un compte | `/register` | Nom complet, email, mot de passe, confirmation, « Créer mon compte », lien « Se connecter » |
| 5 | Tableau de bord | `/app` | 4 cartes KPI (Publications publiées, Programmées, Total d'impressions, Taux d'engagement, avec variation « vs hier ») ; sélecteur « Aujourd'hui » ; graphique « Performance globale » (Impressions, Engagement, 7 derniers jours) ; « Réseaux connectés » ; « Prochaines publications » (Voir tout) |
| 6 | Créer du contenu | `/app/compose` (plein écran ou panneau, avec fermeture ×) | Zone de texte « Quoi souhaitez-vous partager aujourd'hui ? » + compteur **0/280** ; « Ajouter des médias » (images, vidéos, GIF) ; sélection des réseaux (icônes) ; « Versions par réseau » + « Générer avec l'IA » ; « Aperçu des versions » (Instagram 1080×1080 image carrée, TikTok 1080×1920 vidéo verticale, Facebook 1200×630 lien/image, LinkedIn 1200×627 image paysage) ; Enregistrer comme brouillon · Programmer ▾ · Publier maintenant |
| 7 | Calendrier éditorial | `/app/calendar` | Navigation par mois, onglets Mois / Semaine / Jour, « + Nouvelle publication », grille mensuelle, panneau « Publications du jour », légende Publiée / Programmée / Brouillon / En erreur |
| 8 | Réseaux sociaux | `/app/accounts` | Liste : plateforme, badge de statut (Connecté / Non connecté / Bientôt disponible), sous-entités (« Pages (2) », « Chaîne (1) »…), bouton Gérer ou Connecter |
| 9 | Bibliothèque média | `/app/media` | Onglets Tous / Images / Vidéos / GIF, recherche, bouton Importer, grille de vignettes avec durée, tuile « + » |
| 10 | Analyses | `/app/analytics` | Sélecteur de période ; 4 KPI (Impressions, Engagement, Clics sur le lien, Followers) ; onglets Vue d'ensemble / Réseaux / Contenus / Audience ; courbe « cette période » contre « période précédente » |
| 11 | « Paramètres » (**en réalité Clipping vidéo**) | `/app/video-studio` | Onglets Compte / Notifications / Sécurité (incohérent) ; zone « Glissez votre vidéo ici » (MP4, MOV, AVI, max 1 Go) ; « Analyse automatique » : détection des moments clés, génération de clips courts, adaptation aux formats sociaux ; bouton « Analyser la vidéo » |
| 12 | Notifications | `/app/notifications` | Onglets Toutes / Non lues ; éléments : Publication publiée avec succès, Votre vidéo est prête, **Nouveau commentaire**, Réseau déconnecté, **Mise à jour disponible** |
| 13 | Design system | — | Couleurs, typographie, boutons, champs, toggle, checkbox, états |
| 14 | Responsive | — | Mockups laptop et mobile (trop petits pour être exploitables) |

Écrans **absents** de la maquette (à concevoir dans la même direction artistique) : Paramètres (vrai contenu),
Sécurité, mot de passe oublié et réinitialisation, vérification email, onboarding étapes 2 et 3, Video Studio
(timeline, liste de clips, édition, export), détail d'un post ou d'une publication échouée, vue liste du calendrier,
dossiers et tags de la médiathèque, détail et permissions d'un compte social, pages légales (Confidentialité, CGU,
suppression des données), pages d'erreur (404, 403, 500), états vides.

## 2. Navigation

Barre latérale sombre, fixe à gauche, sur toutes les pages de l'application :

```text
[Logo SocialPulse]
Tableau de bord          /app
Créer du contenu         /app/compose
Calendrier               /app/calendar
Réseaux sociaux          /app/accounts
Médias                   /app/media
Clipping vidéo           /app/video-studio
Analytics                /app/analytics
Notifications  (badge)   /app/notifications
──────────────
Paramètres               /app/settings
Sécurité                 /app/security
──────────────
[Avatar] Marie Dubois — Créatrice de contenu   (menu : profil, déconnexion)
```

Élément actif : fond primaire (indigo) arrondi, texte blanc. Badge rouge (pastille) pour les notifications non lues.
Libellés à harmoniser : « Analyses » (titre de page) contre « Analytics » (menu) ; « Clipping vidéo » contre « Video Studio » (cahier des charges) ; « Médias » contre « Bibliothèque média ».

## 3. Design system (extrait de la maquette)

### 3.1 Couleurs
| Token | Hex (lu sur la maquette) | Équivalent Tailwind probable | Usage |
|---|---|---|---|
| `primary` | `#6366F1` | indigo-500 | Boutons principaux, élément actif, liens |
| `secondary` | `#8B5CF6` | violet-500 | Accents, dégradés, IA |
| `success` | `#10B981` | emerald-500 | Connecté, publiée, variations positives |
| `warning` | `#F59E0B` | amber-500 | Permission requise, bientôt |
| `error` | `#EF4444` | red-500 | Erreur, échec, badge de notification |
| `neutral` | `#64748B` | slate-500 | Textes secondaires |
| `sidebar` | ≈ `#0F1629` – `#111827` (bleu nuit) | ≈ slate-900 / gray-900 | Barre latérale |
| `background` | ≈ `#F5F6FF` (blanc lavande) | — | Fond d'application |
| `surface` | `#FFFFFF` | white | Cartes |

Les hex ont été lus sur une image compressée : **à confirmer** (demander la palette source). Couleurs des marques
sociales : utiliser les logos officiels selon les chartes de chaque plateforme (règles d'usage des marques `TO_VERIFY`).

### 3.2 Typographie
Police **Inter**. Échelle lisible sur la planche (taille/interligne, valeurs partiellement illisibles, `TO_VERIFY`) :
Titres 24/32/40/48 · Sous-titres ≈ 16–20 · Texte 14/20 · Légende 12/16.
Proposition Tailwind : `text-xs` 12/16, `text-sm` 14/20, `text-base` 16/24, `text-xl` 20/28, `text-2xl` 24/32, `text-4xl` 36/40, `text-5xl` 48/1.

### 3.3 Forme et espacement (estimations)
Rayon des cartes ≈ 12–16 px (`rounded-xl` / `rounded-2xl`), boutons et champs ≈ 8–10 px (`rounded-lg`) ; ombres
très légères (`shadow-sm`) ; bordures fines `slate-200` ; grille d'espacement de 4 px (Tailwind par défaut) ;
padding des cartes ≈ 20–24 px.

### 3.4 Composants → shadcn/ui
| Composant maquette | Implémentation |
|---|---|
| Bouton principal / secondaire / lien | `Button` variants `default`, `outline`, `link` (+ `destructive`) |
| Champ, mot de passe avec icône œil | `Input` + composant `PasswordInput` |
| Toggle, checkbox | `Switch`, `Checkbox` |
| Cartes KPI | `Card` + composant `StatCard` (valeur, variation, icône colorée) |
| Badges de statut | `Badge` + composant `StatusBadge` (mapping statut → couleur et libellé) |
| Onglets | `Tabs` |
| Menus, sélecteur de période | `DropdownMenu`, `Select`, `Popover` + `Calendar` |
| Programmer ▾ | `Button` groupé + `DropdownMenu` (programmer, date et heure) |
| Modale du composer | `Dialog` ou `Sheet` plein écran |
| Graphiques | `Chart` (Recharts) |
| Tableaux (comptes, listes) | `Table` (+ TanStack Table si tri et filtres) |
| Notifications éphémères | `Sonner` (toast) |
| Stepper d'onboarding | composant maison |
| Zone de dépôt (drag & drop) | composant maison `Dropzone` |
| Icônes | `lucide-react` + icônes de marques en SVG officiels |

### 3.5 États (planche « États »)
| Libellé maquette | Couleur | Correspondance technique |
|---|---|---|
| Disponible | vert | `social_platforms.availability = available` |
| Bientôt | orange | `availability = coming_soon` |
| Permission requise | orange | `social_permissions` manquante |
| Non disponible | rouge | `availability = disabled` / capacité non supportée |
| En cours | bleu | `processing`, `publishing`, `rendering` |
| Réussi | vert | `published`, `ready`, `rendered` |
| Échoué | rouge | `failed` |
| Déconnecté | noir/gris | `social_accounts.status = revoked` |

Libellés à ajouter pour couvrir tous les statuts du cahier des charges : Brouillon, Programmé, Partiellement publié,
Annulé, Expiré (reconnexion requise), Erreur de compte, Upload en cours, Traitement, Prêt, Supprimé.

## 4. États d'interface obligatoires (chaque écran de données)

Chargement (squelettes), vide (message et action principale, par exemple « Connectez votre premier réseau »),
erreur (message clair et « Réessayer »), succès (toast), hors ligne ou session expirée (redirection vers la connexion),
permission manquante (bandeau avec « Reconnecter »), désactivé avec explication (« Instagram n'accepte pas les posts sans image »).

## 5. Responsive

La maquette montre un rendu laptop et mobile, mais sans détail exploitable → **à concevoir** :
- ≥ 1280 px : barre latérale fixe et contenus en grilles de 3 à 4 colonnes (comme la maquette).
- 768–1279 px : barre latérale repliable (icônes seules), grilles de 2 colonnes.
- < 768 px : barre latérale remplacée par un tiroir (`Sheet`) et une barre inférieure (Accueil, Créer, Calendrier, Notifications) ; calendrier en vue liste par défaut ; composer plein écran.
Accessibilité : contraste AA (vérifier le texte gris sur fond lavande), focus visible, navigation au clavier, `alt` sur les médias, libellés ARIA pour les boutons-icônes (réseaux).

## 6. Parcours utilisateur

1. **Inscription → vérification email → onboarding** : Connecter (≥ 0 réseau, possibilité de passer) → Personnaliser (fuseau, langue, préférences, `TO_DEFINE`) → Bienvenue → Tableau de bord.
2. **Connecter un réseau** : carte → explication des prérequis (par exemple « compte Instagram professionnel requis ») → OAuth → retour → choix des pages ou chaînes à gérer → statut Connecté et permissions affichées ; en cas de refus d'un scope, bandeau « Permission requise ».
3. **Créer et programmer** : Créer du contenu → texte → médias → réseaux → versions par réseau (validation des contraintes) → aperçu → Programmer (date, heure, fuseau) → toast → apparition dans le calendrier.
4. **Échec de publication** : notification et email → détail de la variante (message compréhensible et action : reconnecter, remplacer le média, réessayer).
5. **Clipping** : upload → progression par étape (analyse, transcription, détection) → liste des clips avec score → édition (bornes, titre, ratio, sous-titres) → export → média prêt → « Créer un post avec ce clip ».
6. **Token expiré** : bandeau dans le tableau de bord et statut Expiré → « Reconnecter ».

## 7. Divergences entre la maquette et le cahier des charges

| # | Problème | Impact | Recommandation | Priorité |
|---|---|---|---|---|
| UX-01 | Landing : lien « Tarifs » et CTA « Commencer gratuitement » | Contredit le modèle non-SaaS de la V1 | Retirer « Tarifs » ou le remplacer par « Contact » ; CTA « Créer un compte » | HIGH |
| UX-02 | Landing : « 10k+ créateurs », « 99 % de satisfaction » | Allégations non vérifiables (pratique commerciale trompeuse) | Retirer avant la mise en ligne ou remplacer par des chiffres réels | HIGH |
| UX-03 | Connexion via Google / Facebook | Non prévu au brief ; surface OAuth supplémentaire | Masquer en V1, décision ADR-016 | MEDIUM |
| UX-04 | L'écran titré « Paramètres » montre l'upload du Clipping vidéo | Écran Paramètres réel absent ; confusion | Considérer cet écran comme l'étape 1 du Video Studio ; concevoir Paramètres | HIGH |
| UX-05 | Video Studio : ni timeline, ni liste de clips, ni score, ni sous-titres, ni export | Module majeur non maquetté | Maquetter avant la phase 10 | HIGH |
| UX-06 | Écran Sécurité absent (présent dans le menu) | Exigence de sécurité centrale sans interface | Maquetter : mot de passe, sessions, 2FA, journal | MEDIUM |
| UX-07 | Composer : compteur global 0/280 | 280 = limite X ; chaque plateforme a sa limite | Compteur par variante, piloté par les capacités | MEDIUM |
| UX-08 | Composer : sélection par plateforme (icônes) | Un utilisateur peut avoir plusieurs comptes par plateforme | Sélection par compte (avatar + icône de plateforme) | MEDIUM |
| UX-09 | Composer : pas de champs propres aux plateformes (tableau Pinterest, titre YouTube, visibilité et options TikTok) | Publication impossible ou non conforme (TikTok impose des règles d'interface) | Panneau d'options par variante | HIGH |
| UX-10 | Calendrier : pas de vue Liste ni de filtres | Exigences du cahier des charges non couvertes | Ajouter l'onglet Liste et des filtres statut/plateforme/compte | MEDIUM |
| UX-11 | Légende du calendrier : 4 statuts sur 7 | Statuts `processing`, `partially_published`, `cancelled` absents | Étendre la légende (§3.5) | LOW |
| UX-12 | Réseaux sociaux : statuts Connecté / Non connecté / Bientôt | Le cahier des charges exige connected / expired / revoked / error + permissions | Ajouter Expiré, Révoqué, Erreur, le détail des permissions et « Synchroniser » | MEDIUM |
| UX-13 | Médiathèque : onglets Images / Vidéos / GIF | Le cahier des charges prévoit image / video / audio / document, dossiers et tags | Onglets alignés sur les types, GIF comme sous-type d'image ; ajouter dossiers, tags et filtres | MEDIUM |
| UX-14 | Tableau de bord : ni « alertes », ni « activité récente », ni « portée », ni « vues » | Exigences non couvertes | Ajouter un bandeau d'alertes et un bloc Activité ; KPI configurables | LOW |
| UX-15 | Notifications : « Nouveau commentaire » | La gestion des commentaires est hors V1 (scopes et webhooks supplémentaires) | Retirer en V1 | LOW |
| UX-16 | Notifications : « Mise à jour disponible » | Sans objet pour une application web | Retirer | LOW |
| UX-17 | Analytics : l'élément de menu actif est « Médias » | Erreur de maquette | Corriger à l'implémentation | LOW |
| UX-18 | Onboarding : l'étape « Personnaliser » n'est pas définie | Contenu inconnu | Définir (fuseau, langue, préférences) | LOW |
| UX-19 | Pages légales absentes | **Bloquant pour les revues d'applications des plateformes** | Confidentialité, CGU, suppression des données | HIGH |
| UX-20 | Nom : « SocialPulse » (maquette) contre « Sociopulse » (projet) | Marque, domaines, noms d'applications OAuth | Trancher avant de créer les applications développeur (ADR-015) | HIGH |
| UX-21 | Upload vidéo : AVI accepté, limite 1 Go | AVI mal adapté ; 1 Go = coûts de stockage et de traitement | Valider les formats et limites (security.md §7) | LOW |
| UX-22 | Planche basse définition, sans source | Intégration imprécise (espacements, couleurs) | Obtenir les écrans HD ou le fichier Figma | MEDIUM |

---

## 8. Implémentation Frontend V1 (2026-09-28)

Routes finales : voir `frontend/README.md` (ADR-023). Choix faits pendant l'implémentation, **maquette = référence visuelle, documentation = référence fonctionnelle** :

| Écart | Choix retenu dans le frontend V1 |
|---|---|
| UX-01 « Tarifs » | Lien conservé (maquette) → section « Les offres arrivent bientôt », sans prix inventé. Libellés de la navbar : « Connexion » / « Inscription » (brief phase 1). |
| UX-02 chiffres « 10k+ / 99 % » | Conservés (maquette), marqués `TODO(UX-02)` dans le code : **à retirer ou remplacer avant mise en ligne**. |
| UX-03 Google / Facebook | Affichés **désactivés** avec l'infobulle « Bientôt disponible ». |
| UX-04 écran « Paramètres » | Cet écran sert d'étape d'import du Video Studio ; une vraie page Paramètres (6 sections) a été créée. |
| UX-05 Video Studio | Interface complète proposée (import, pipeline, lecteur simulé, timeline, transcription, clips, ratios, sous-titres, export), signalée comme aperçu fonctionnel. **À valider par une maquette dédiée.** |
| UX-07 / UX-08 / UX-09 Composer | Compteur calé sur la limite la plus stricte des réseaux choisis ; sélection par plateforme (comme la maquette) + choix de la Page pour les comptes à plusieurs entités ; panneau d'options par variante (TikTok, YouTube, Pinterest…). |
| UX-10 Calendrier | Vues Mois, Semaine, Jour, **Liste** + filtres réseau et statut. |
| UX-12 statuts des comptes | Connecté, Non connecté, Expiré, Permission requise, Erreur, Bientôt disponible. |
| UX-13 Médiathèque | Onglets Tous / Images / Vidéos / **GIF** (maquette) + Audio / Documents ; dossiers, tags, grille/liste. |
| UX-14 Dashboard | 4 KPI de la maquette + portée et vues dans « Performance globale », alertes, activité récente, raccourcis. |
| UX-15 / UX-16 Notifications | « Nouveau commentaire » et « Mise à jour disponible » non repris (hors V1). |
| UX-17 Analytics | Élément de menu actif corrigé. 6 KPI (maquette : 4) pour couvrir portée et vues. |
| UX-19 pages légales | Pages `/legal/*` créées (contenu à rédiger). |
| Navigation | Deux entrées ajoutées à la barre latérale : « Publications » et « Assistant IA ». |
