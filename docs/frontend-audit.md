# Audit final — Frontend V1

Date : 2026-09-28. Périmètre : `frontend/` uniquement (aucun backend).
Méthode : contrôles **dans le navigateur** (Chrome piloté par puppeteer) sur le serveur de développement et le build de production :
- 15 routes × 5 largeurs (390, 768, 1024, 1280, 1440) : débordements, textes < 11 px, contrôles hors écran, erreurs console ;
- 30 parcours fonctionnels automatisés (formulaires, Composer par plateforme, publication, brouillon, programmation, édition, OAuth simulé, upload, suppression protégée, export vidéo, IA, notifications, paramètres, calendrier, clavier, focus) ;
- axe-core WCAG 2.1 A/AA sur 15 routes ;
- mesures de chargement (transfert, FCP, LCP) sur le build de production, en desktop et en mobile 3G simulé ;
- comparaison visuelle avec `Images/maquette.png` (planche basse définition : espacements et couleurs comparés à l'œil).

## Corrigé pendant l'audit

| # | Problème | Gravité | Correction |
|---|---|---|---|
| A1 | Réseaux sociaux : débordement horizontal à 768 et 1024 px, bouton « Gérer » de X hors écran | HIGH | Lignes empilées jusqu'à `xl`, colonnes secondaires affichées en `xl` / `2xl` |
| A2 | Composer : la confirmation « Quitter sans enregistrer ? » ne s'affichait jamais (`isDirty` non suivi par React Hook Form) | HIGH | `isDirty` lu pendant le rendu |
| A3 | Video Studio : débordement de 7 px à 1024 px, liste des projets illisible | MEDIUM | Une colonne jusqu'à `xl` |
| A4 | Composer : le choix de la Page Facebook n'était pas enregistré | MEDIUM | `options.accountEntityId` enregistré dans la variante |
| A5 | Tableau de bord : le sélecteur « Aujourd'hui / 7 j / 30 j » ne changeait pas les chiffres | MEDIUM | Données recalculées selon la période |
| A6 | Programmation : fuseau « Europe/Paris » affiché en dur alors que la saisie suit le fuseau du navigateur | MEDIUM | Fuseau réel affiché et envoyé (`lib/time.ts`) |
| A7 | Composer : formats Reel / Short acceptés sans vidéo | MEDIUM | Validation ajoutée |
| A8 | LCP de la landing retardé (image du hero demandée après 3 niveaux de chargement) | MEDIUM | Préchargement du visuel dès le démarrage sur `/` + icônes regroupées en un chunk. LCP desktop 4,6 s → 2,8 s ; 3G 4,6 s → 4,0 s |
| A9 | X : format « Thread » proposé sans éditeur de fil | LOW | Format retiré |
| A10 | Messages « …heures.. » (double ponctuation) | LOW | Corrigé |
| A11 | Badges de durée / GIF en 10 px | LOW | 11 px |
| A12 | Liste des fuseaux dupliquée (onboarding, paramètres) ; ombre de marque avec l'ancienne primaire | LOW | Centralisés |

## Problèmes ouverts

### CRITICAL
Aucun.

### HIGH
| Problème | Impact | Emplacement | Recommandation |
|---|---|---|---|
| Preuves sociales fictives « 10k+ créateurs & entreprises », « 99 % de satisfaction » | Pratique commerciale trompeuse, perte de confiance | `components/landing/Hero.tsx` (`STATS`) | Retirer ou remplacer par des chiffres réels **avant toute mise en ligne publique** (décision porteur de projet, contenu de la maquette) |
| Promesses de sécurité et de fiabilité qui décrivent le futur backend (« autorisations chiffrées », « fichiers vérifiés », « aucune publication en double », « statut en temps réel ») | Fausses si mises en ligne avant le backend | `components/landing/Sections.tsx` (FEATURES, SECURITY) | Conserver pour la démo ; ne publier qu'une fois ces mesures implémentées et vérifiées |
| Aucun test automatisé dans le dépôt | Régressions non détectées lors du branchement des services Laravel | `frontend/` | Ajouter au minimum les smoke tests et les parcours critiques (voir « Tests ») au début de la phase backend |
| Projet non versionné (pas de dépôt Git) | Aucune traçabilité ni retour arrière avant d'attaquer le backend | racine | `git init` + premier commit avant la phase backend |
| Pages légales vides, alors que le formulaire d'inscription y renvoie | Bloquant pour une mise en ligne et pour les revues d'applications des plateformes | `pages/LegalPage.tsx` | Rédiger Confidentialité, CGU, Suppression des données |

### MEDIUM
| Problème | Impact | Emplacement | Recommandation |
|---|---|---|---|
| Section « Tarifs » : « Les offres arrivent bientôt » | Suggère des offres payantes alors que la V1 n'est pas un SaaS | `Sections.tsx` (PricingSection), navbar, footer | Décider : retirer « Tarifs » ou afficher « Gratuit pendant la V1 » |
| « 8+ réseaux sociaux » | 7 réseaux disponibles + Threads « bientôt » : le « + » surévalue | `Hero.tsx` | « 8 réseaux » ou « 7 réseaux + Threads bientôt » |
| Couleur primaire `#5B5EED` (ADR-022) | Impact global sur le design system | `index.css` | **Recommandation : conserver** (voir « Accessibilité ») ; décision à confirmer |
| Video Studio sur mobile : la liste des clips arrive après l'éditeur ; les segments de la timeline sont trop fins pour le tactile | Parcours peu pratique sur téléphone | `components/video/VideoWorkspace.tsx` | À traiter dans la maquette dédiée |
| Composer : une seule entité par plateforme (on choisit une Page Facebook, pas plusieurs) | Le cahier des charges parle de sélection par compte | `NetworkPicker`, `VariantEditor` | Sélection multi-comptes à concevoir quand le backend exposera les comptes |
| Compteur de caractères basé sur `String.length` | X compte les URL et les emojis différemment ; les limites sont indicatives | `composer/model.ts` | Laisser le backend valider (adaptateurs) ; afficher « limite indicative » (déjà dans l'éditeur) |
| « Voir la démo » ouvre l'application sans authentification | Normal en démo, à retirer avec l'authentification réelle | `Hero.tsx`, `Sections.tsx` | Rediriger vers une démo dédiée ou la connexion en phase 2 |

### LOW
| Problème | Impact | Emplacement | Recommandation |
|---|---|---|---|
| Composants shadcn installés mais inutilisés (`card`, `popover`, `scroll-area`, `separator`, `table`) ; la classe `.surface-card` double `Card` | Incohérence mineure, bruit | `components/ui/` | Soit utiliser `Card`, soit retirer les composants non utilisés |
| 2 avertissements de lint (`watch()` de React Hook Form) | Informatif (le React Compiler n'est pas activé) | `ComposerPage`, `LoginPage` | Passer à `useWatch` lors d'un prochain refactor |
| Préférences non persistées (premier jour de la semaine, étape « Personnaliser » de l'onboarding) | Réglage sans effet | `SettingsPanels`, `OnboardingPage` | À brancher avec l'API `/me` |
| Police Caveat (50 Ko) chargée pour la seule accroche manuscrite | Poids de la landing | `index.css` | Acceptable ; éventuellement la remplacer par un SVG |
| `hero-original.png` (1,7 Mo) conservé comme repli | Téléchargé seulement par les navigateurs sans WebP | `assets/images` | Compresser le PNG de repli (~300 Ko) si souhaité |
| Le visuel du hero contient ses propres chiffres (« 12,4K », « +245 % », « 2,8K vues ») | Illustration, peut être lue comme un résultat réel | `Images/Image sect-hero.png` | Acceptable comme illustration ; ne pas les citer comme résultats |
| Nom du produit non tranché (ADR-015) | Marque, domaines, OAuth | global | Décider avant la phase 3 |

## Contenu marketing à valider avant mise en ligne

| ELEMENT | LOCATION | PROBLEM | RECOMMENDATION |
|---|---|---|---|
| « 10k+ créateurs & entreprises » | Hero, bloc de chiffres | Chiffre inventé (maquette) | Retirer, ou remplacer par un chiffre réel et vérifiable |
| « 99 % de satisfaction » | Hero, bloc de chiffres | Aucune mesure ne l'appuie | Retirer, ou afficher une mesure réelle avec sa source |
| « 8+ réseaux sociaux » | Hero | 7 disponibles + 1 « bientôt » | « 8 réseaux » ou « 7 réseaux + Threads bientôt » |
| « Tarifs » + « Les offres arrivent bientôt » | Navbar, section `#tarifs`, footer | V1 non-SaaS ; suggère une offre payante | Décision : retirer ou « Gratuit pendant la V1 » |
| « Commencer gratuitement » | Hero | Vrai seulement si la V1 reste gratuite | Confirmer le modèle |
| Promesses de sécurité et de fiabilité | Sections « Sécurité » et « Publication » | Décrivent des mesures pas encore implémentées | Publier seulement quand elles sont vraies |
| FAQ « Facebook, Instagram, TikTok… pris en charge » | FAQ | Dépend des revues d'applications (`TO_VERIFY`) | Mettre à jour selon les approbations obtenues |
| Statistiques intégrées au visuel du hero | Image | Illustratives | Garder en illustration uniquement |

## Video Studio — à valider dans une maquette dédiée
1. Ordre des zones sur mobile (clips → aperçu → édition) et cibles tactiles de la timeline.
2. Timeline : zoom, poignées de début et de fin déplaçables, aimantation sur les phrases de la transcription.
3. Lecteur réel : contrôles, vitesse, scrubbing, recadrage (zone de cadrage pour le 9:16).
4. Édition de la transcription et des sous-titres (correction du texte, styles, marque).
5. Export groupé, file d'attente, états d'erreur et de nouvelle tentative.
6. Import par URL : consentement explicite sur les droits (ADR-013).
7. Explication du score et des raisons de la détection.
8. Enchaînement avec le Composer (clip exporté → publication pré-remplie avec le média).

## Accessibilité — couleur primaire
- `#6366F1` : blanc 4,47:1 — **échoue de peu** au critère 1.4.3 (4,5:1) sur tous les boutons principaux.
- `#5B5EED` : blanc 4,92:1, sur fond de page 4,60:1 — **conforme AA** ; écart visuel minime, et plus proche de la teinte réellement rendue sur la maquette (`#5C6AF6`).
- **Recommandation : conserver `#5B5EED`.** Décision finale au porteur de projet ou au designer (ADR-022).
- Résultat axe : 0 violation sur 15 routes. Contrôlés dans le navigateur : lien d'évitement, ordre de tabulation, focus visible (anneau de 2 px), labels, `aria-invalid` et messages d'erreur annoncés, Échap ferme les dialogues. Non fait : test avec un lecteur d'écran réel (NVDA / VoiceOver).

## Tests recommandés avant le backend
| Type | Contenu |
|---|---|
| Smoke tests | Chaque route se rend sans erreur console (les 23 routes) ; 404 ; build de production |
| Component tests (Vitest + Testing Library) | `StatusBadge` (mapping statuts), `FilterTabs` (`aria-pressed`), `PlatformIcon`, `MediaThumb`, `validateComposer` (règles par plateforme), `aggregateStatus`, `formatCompact` / `formatChange` (« n/d » pour null) |
| Route tests | Redirections (`/settings` → `/settings/profile`, `/app` → `/dashboard`), barre latérale active, édition `/content/:id/edit` |
| Form tests | Login, Register (12 caractères, confirmation), mot de passe oublié, profil, mot de passe, programmation (date passée refusée) |
| Responsive tests (Playwright) | 390 / 768 / 1024 / 1280 / 1440 : aucun débordement, navbar fixe + Connexion / Inscription visibles |
| Accessibility tests | axe-core sur chaque route dans la CI |
| Critical user flows (Playwright) | Créer → publier ; brouillon ; programmer → calendrier ; garde-fou de sortie ; connecter un réseau ; upload + suppression protégée ; export d'un clip ; IA → Composer |

Les scripts utilisés pour cet audit (puppeteer) couvrent déjà la plupart de ces parcours et peuvent être portés en Playwright.
