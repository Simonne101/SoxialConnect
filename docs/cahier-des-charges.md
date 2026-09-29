# Cahier des charges fonctionnel — SocialPulse V1

Version : 0.1 (phase 0), 2026-09-28. Ce document formalise le brief initial du projet.

---

## 1. Vision

Offrir **une seule interface** où une personne non technique peut gérer toute sa présence sur les réseaux
sociaux : connecter ses comptes, créer du contenu une fois, l'adapter à chaque plateforme, le programmer,
le publier, puis mesurer ses résultats. L'IA sert à accélérer la création et le découpage vidéo.

Accroche retenue dans la maquette : *« Créez. Planifiez. Publiez. Analysez. Grandissez. »*

## 2. Problème

- Chaque réseau a sa propre interface, ses propres formats et ses propres contraintes.
- Publier le même message sur 5 à 8 plateformes prend du temps et entraîne des erreurs (format, ratio, longueur).
- Les statistiques sont dispersées et ne se comparent pas facilement.
- Tirer des clips courts d'une longue vidéo demande des compétences de montage.

## 3. Objectifs

| # | Objectif | Indicateur de réussite (à confirmer) |
|---|---|---|
| O1 | Connecter un réseau en moins de 2 minutes | Temps médian du parcours d'onboarding |
| O2 | Créer et programmer un post multi-réseaux en une seule fois | Un seul formulaire (composer) |
| O3 | Fiabilité de publication | Aucune publication perdue ni en double ; tout échec est visible et peut être relancé |
| O4 | Vue consolidée des performances | Métriques normalisées, comparables entre plateformes |
| O5 | Réutiliser des vidéos longues | Pipeline qui produit des clips exportables en 9:16, 1:1 et 16:9 |

## 4. Utilisateurs

| Persona | Description | Besoins clés |
|---|---|---|
| Créatrice de contenu (persona de la maquette : « Marie Dubois, Créatrice de contenu ») | Gère seule 4 à 8 comptes | Rapidité, calendrier, IA, clips |
| Indépendant / TPE | Gère la page de son activité | Simplicité, programmation, statistiques de base |
| Community manager (futur) | Gère plusieurs marques | Espaces de travail, rôles : **hors V1**, mais l'architecture doit le permettre |

En V1, **un utilisateur = un propriétaire de données**. Il n'y a ni partage ni collaboration.

## 5. Périmètre fonctionnel V1

### 5.1 Authentification et compte
- Inscription (nom complet, email, mot de passe, confirmation), connexion, « se souvenir de moi », mot de passe oublié.
- Vérification de l'email obligatoire avant de connecter un réseau social.
- Déconnexion, changement de mot de passe, liste des sessions actives et révocation (écran Sécurité).
- Architecture prête pour la 2FA (TOTP). Activation prévue en phase 11 ou avant la production.
- Connexion via Google ou Facebook (visible dans la maquette) : **hors V1 par défaut**, à confirmer (ADR-016).

### 5.2 Onboarding
Trois étapes (maquette) : **1. Connecter** (choix des réseaux) → **2. Personnaliser** (contenu à définir :
fuseau horaire, langue, préférences de notification — `TO_DEFINE`) → **3. Bienvenue**.

### 5.3 Dashboard (Tableau de bord)
Publications publiées, publications programmées, impressions/portée, taux d'engagement, vues, courbe de
performance (7 jours par défaut), réseaux connectés et leur statut, prochaines publications, activité récente,
alertes (token expiré, publication échouée, permission manquante).

### 5.4 Contenu et Composer (Créer du contenu)
- Créer, modifier, supprimer, dupliquer un post ; l'enregistrer comme brouillon.
- Sélectionner des comptes sociaux cibles (et pas seulement des plateformes : un utilisateur peut avoir 2 pages Facebook).
- Texte principal, puis **variante par plateforme ou compte** (texte, médias, format, options spécifiques).
- Ajouter des images et des vidéos depuis la médiathèque ou par upload ; ajouter des hashtags.
- Générer ou reformuler avec l'IA.
- Prévisualiser chaque variante, avec les contraintes de la plateforme (longueur, ratio, durée, nombre de médias).
- Publier maintenant, ou programmer à une date et heure précises dans le fuseau de l'utilisateur.
- Validation bloquante si une variante viole une contrainte connue de sa plateforme.

**Statuts d'un post** : `draft`, `scheduled`, `processing`, `partially_published`, `published`, `failed`, `cancelled`.
Le statut du post est **agrégé** à partir des statuts de ses variantes (voir database.md).

### 5.5 Calendrier éditorial
Vues mois, semaine, jour et liste. Filtres par statut, plateforme et compte. Clic sur une date pour créer une
publication. Glisser-déposer pour reprogrammer (souhaitable, V1.x). Panneau « Publications du jour ».
Légende des statuts.

### 5.6 Réseaux sociaux (comptes)
Connecter, reconnecter, déconnecter et synchroniser un compte ; voir son statut (`connected`, `expired`,
`revoked`, `error`), ses permissions accordées ou manquantes, et les sous-entités (pages, chaînes).
Une plateforme peut être marquée « Bientôt disponible » (maquette : Threads).

### 5.7 Médiathèque
Upload par bouton et glisser-déposer, recherche, filtres (type, statut), dossiers, tags, aperçu, suppression.
Types : `image`, `video`, `audio`, `document`. Statuts : `uploading`, `processing`, `ready`, `failed`, `deleted`.

### 5.8 Video Studio (Clipping vidéo)
Pipeline : source (upload ou URL autorisée) → analyse → transcription → détection des passages → clips
proposés → modification → export.
Chaque clip a un début, une fin, une durée, un score, un titre, un ratio (9:16, 1:1, 16:9), des sous-titres
(activables et stylables) et un export vers la médiathèque, réutilisable dans le composer.
Limite de la maquette : MP4, MOV, AVI, 1 Go maximum (à valider, voir security.md).

### 5.9 Assistant IA
Génération de posts et de captions, idées de contenu, hashtags, reformulation, scripts, adaptation du ton,
analyse vidéo, détection de clips. Le fournisseur d'IA est interchangeable. Chaque génération est historisée.

### 5.10 Analytics
Impressions, portée, engagement, vues, clics, abonnés ; évolution sur une période ; top contenus ;
comparaison entre plateformes. Onglets de la maquette : Vue d'ensemble, Réseaux, Contenus, Audience.
Les métriques non fournies par une plateforme s'affichent « non disponible » et **jamais 0**.

### 5.11 Notifications
Centre de notifications (Toutes / Non lues) : publication réussie ou échouée, vidéo ou clip prêt, compte
déconnecté ou expiré, permission manquante. Email pour les événements critiques (échec de publication,
token expiré). Préférences dans Paramètres › Notifications.

### 5.12 Paramètres et sécurité
Compte (nom, email, fuseau, langue), notifications, sécurité (mot de passe, sessions, 2FA future, journal
d'activité de sécurité issu des audit logs).

## 6. Contraintes

### 6.1 Contraintes produit
- **Non-SaaS en V1** : aucune table ni aucun code pour abonnements, plans, facturation, factures ou paiements.
- Architecture compatible avec l'ajout futur de Workspaces, de membres et de rôles.
- UX simple, en français par défaut, prête pour l'i18n.

### 6.2 Contraintes plateformes
- **APIs officielles uniquement.** Aucun scraping, aucune automatisation de navigateur, aucune API non officielle.
- Les capacités diffèrent selon la plateforme et le type de compte. Elles sont déclarées par adaptateur et ne sont jamais supposées.
- Chaque plateforme impose une revue d'application ou un audit, une politique de confidentialité, des CGU, et
  parfois un callback de suppression des données. Ces démarches conditionnent la mise en production (voir api-integration.md).

### 6.3 Contraintes techniques
Voir architecture.md et security.md. Points essentiels : tokens OAuth chiffrés, stockage privé des médias,
jobs idempotents, FFmpeg isolé, webhooks vérifiés.

### 6.4 Contraintes légales (à compléter)
- RGPD : base légale, registre de traitement, export et suppression des données, sous-traitants (hébergeur, fournisseurs IA).
- Envoi de contenus utilisateur à un fournisseur IA : à mentionner dans la politique de confidentialité.
- Droits sur les vidéos importées par URL (voir ADR-013).

## 7. Hors périmètre V1

Abonnements et facturation ; workspaces et collaboration ; boîte de réception unifiée (réponse aux
commentaires et messages) ; publicité (Ads APIs) ; applications mobiles natives ; social listening ;
approbation de contenu ; connexion via Google ou Facebook (sauf décision contraire).

## 8. Évolutions futures

1. Workspaces, membres, rôles et permissions.
2. Offre SaaS : plans, quotas, facturation.
3. Boîte de réception unifiée (commentaires, mentions) via webhooks.
4. Workflows d'approbation.
5. Nouvelles plateformes (Bluesky, Mastodon, Google Business Profile… `TO_VERIFY`).
6. Intégrations publicitaires.
7. Temps réel (WebSocket) pour les statuts de publication et de rendu.

## 9. Glossaire

| Terme | Définition |
|---|---|
| Plateforme | Un réseau social (Facebook, TikTok…) : table `social_platforms` |
| Compte social | Une entité publiable sur une plateforme (page, profil, chaîne) : table `social_accounts` |
| Post | Contenu « maître » créé dans le composer |
| Variante | Déclinaison d'un post pour un compte social cible ; c'est l'unité de publication |
| Tentative | Un essai de publication d'une variante (`publication_attempts`) |
| Capacité | Ce qu'une plateforme permet via son API officielle (texte, image, vidéo, story…) |
