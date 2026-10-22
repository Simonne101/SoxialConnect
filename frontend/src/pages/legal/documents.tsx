// Contenu des pages légales publiques (/terms, /privacy, /legal/data-deletion).
// Rédigé à partir du fonctionnement réel de SoxialConnect (docs/security.md, docs/publishing.md) :
// ne citer aucune technologie ni aucun prestataire qui n'existe pas dans le projet.
// Informations de l'éditeur renseignées le 22/10/2026 (Global Prime Axis, droit béninois, hébergement Hostinger en France).
import type { ReactNode } from "react"
import { External, Internal } from "./LegalInline"

export const LEGAL_UPDATED_AT = "22 octobre 2026"

export interface LegalSection {
  id: string
  title: string
  body: ReactNode
}

export interface LegalDocument {
  title: string
  intro: ReactNode
  sections: LegalSection[]
}

const CONTACT = <External href="mailto:contacts@globalprimeaxis.com">contacts@globalprimeaxis.com</External>
const PUBLISHER = "Global Prime Axis"

// --- Conditions d'utilisation ---------------------------------------------------------------

const terms: LegalDocument = {
  title: "Conditions d'utilisation",
  intro: (
    <p>
      Les présentes conditions d'utilisation encadrent l'accès et l'utilisation de SoxialConnect, service édité par {PUBLISHER}.
      En créant un compte ou en utilisant le service, vous acceptez ces conditions. Notre{" "}
      <Internal to="/privacy">Politique de confidentialité</Internal> explique comment vos données sont traitées.
    </p>
  ),
  sections: [
    {
      id: "service",
      title: "1. Présentation du service",
      body: (
        <>
          <p>
            SoxialConnect est une application Web de gestion des réseaux sociaux. Elle permet notamment de rédiger des publications,
            d'y associer des médias (images, vidéos), d'organiser ses contenus (brouillons, calendrier), de programmer une publication
            ou de la publier immédiatement sur un ou plusieurs comptes, de suivre le résultat de chaque envoi et de consulter les
            statistiques de ses publications. Elle propose aussi des outils de création : assistant de rédaction et génération d'images
            par intelligence artificielle, montage vidéo et création de clips courts à partir de vidéos longues.
          </p>
          <p>
            La publication passe par les interfaces officielles des plateformes sociales que vous connectez vous-même. Les intégrations
            disponibles à ce jour sont YouTube, TikTok, LinkedIn (profils personnels), X et Telegram (chaînes et groupes, par
            l'intermédiaire du bot SoxialConnect). Les autres réseaux affichés dans l'application seront proposés lorsque leur intégration
            officielle sera disponible.
          </p>
        </>
      ),
    },
    {
      id: "compte",
      title: "2. Compte utilisateur",
      body: (
        <ul>
          <li>Vous devez fournir une adresse e-mail valide et la vérifier ; certaines fonctionnalités exigent une adresse vérifiée.</li>
          <li>Vous êtes responsable de la confidentialité de votre mot de passe et de toute activité réalisée depuis votre compte.</li>
          <li>Vous pouvez protéger votre compte par une double authentification (code à usage unique d'une application d'authentification).</li>
          <li>Les informations de votre compte doivent être exactes et tenues à jour.</li>
          <li>Vous devez avoir l'âge requis par la loi applicable et par les plateformes sociales que vous connectez pour utiliser le service.</li>
          <li>Prévenez-nous sans délai à {CONTACT} en cas d'utilisation non autorisée de votre compte.</li>
        </ul>
      ),
    },
    {
      id: "utilisation-acceptable",
      title: "3. Utilisation acceptable",
      body: (
        <>
          <p>Il est interdit d'utiliser SoxialConnect pour :</p>
          <ul>
            <li>publier ou stocker des contenus illicites, haineux, violents, trompeurs, diffamatoires ou portant atteinte aux droits d'autrui ;</li>
            <li>envoyer du spam, manipuler artificiellement l'audience ou contourner les règles et limites des plateformes sociales ;</li>
            <li>usurper l'identité d'une personne ou publier sur un compte que vous n'êtes pas autorisé à gérer ;</li>
            <li>téléverser des fichiers malveillants ou tenter de compromettre la sécurité du service ;</li>
            <li>accéder ou tenter d'accéder aux données d'autres utilisateurs, ou extraire massivement des données du service ;</li>
            <li>utiliser le service d'une manière contraire à la loi applicable.</li>
          </ul>
        </>
      ),
    },
    {
      id: "contenus",
      title: "4. Gestion et publication de contenu",
      body: (
        <>
          <p>
            Vous restez seul responsable des contenus que vous créez, téléversez, programmez ou publiez avec SoxialConnect, ainsi que des
            réglages que vous choisissez (texte, médias, visibilité, interactions, mention de contenu commercial, etc.).
          </p>
          <p>
            SoxialConnect n'envoie une publication qu'à votre demande : immédiatement (« Publier maintenant ») ou à la date que vous avez
            programmée. Chaque envoi est historisé avec son résultat. Une publication n'est indiquée comme « publiée » que lorsque la
            plateforme concernée a confirmé sa création.
          </p>
          <p>
            Chaque plateforme applique ses propres règles, formats, quotas et procédures de modération : elle peut refuser, retarder,
            restreindre la visibilité d'un contenu ou le retirer. SoxialConnect ne peut pas garantir qu'un contenu sera accepté ou
            diffusé par une plateforme.
          </p>
        </>
      ),
    },
    {
      id: "creation",
      title: "5. Outils de création : intelligence artificielle, vidéos et clips",
      body: (
        <>
          <p>
            Les textes, images, transcriptions et propositions de passages produits par les outils d'intelligence artificielle peuvent être
            inexacts ou inadaptés : relisez-les avant toute publication. Aucune publication n'est déclenchée automatiquement par ces outils ;
            elle reste toujours à votre initiative.
          </p>
          <p>
            Pour créer des clips à partir d'une vidéo YouTube, vous pouvez importer le fichier ou, lorsque le service le propose, demander
            la récupération d'une copie de travail d'une vidéo publique ou non répertoriée. Dans les deux cas, vous déclarez détenir les droits
            sur cette vidéo ou l'autorisation de l'utiliser : il est interdit de découper, transformer ou republier une œuvre sans l'accord de
            ses ayants droit. La copie de travail est supprimée automatiquement après quelques jours.
          </p>
          <p>
            Les fichiers que vous envoyez sont analysés par un antivirus avant d'être acceptés ; un fichier détecté comme malveillant est
            refusé et n'est pas conservé.
          </p>
        </>
      ),
    },
    {
      id: "plateformes",
      title: "6. Connexion aux plateformes sociales",
      body: (
        <>
          <p>
            La connexion d'un compte social utilise exclusivement la procédure d'autorisation officielle (OAuth) de la plateforme :
            vous vous identifiez directement auprès d'elle et choisissez les autorisations accordées. SoxialConnect ne reçoit jamais
            votre mot de passe de réseau social.
          </p>
          <p>
            Vous pouvez déconnecter un compte à tout moment depuis la page « Réseaux sociaux » : SoxialConnect demande alors la révocation
            de l'accès à la plateforme lorsque celle-ci le permet et efface les jetons d'accès enregistrés. Vous pouvez aussi retirer
            l'accès depuis les paramètres de la plateforme.
          </p>
          <p>
            SoxialConnect utilise les Services d'API YouTube. En connectant une chaîne YouTube, vous acceptez d'être lié par les{" "}
            <External href="https://www.youtube.com/t/terms">Conditions d'utilisation de YouTube</External>.
          </p>
          <p>
            Votre utilisation de chaque plateforme reste soumise à ses propres conditions : les{" "}
            <External href="https://www.tiktok.com/legal/terms-of-service">Conditions d'utilisation de TikTok</External>, les{" "}
            <External href="https://www.linkedin.com/legal/user-agreement">Conditions d'utilisation de LinkedIn</External>, les{" "}
            <External href="https://x.com/tos">Conditions d'utilisation de X</External> et les{" "}
            <External href="https://telegram.org/tos">Conditions d'utilisation de Telegram</External>.
          </p>
        </>
      ),
    },
    {
      id: "responsabilites",
      title: "7. Responsabilités de l'utilisateur",
      body: (
        <ul>
          <li>Disposer de tous les droits nécessaires sur les contenus publiés (textes, images, vidéos, musiques, marques, personnes représentées).</li>
          <li>Respecter les règles de chaque plateforme, y compris l'obligation d'indiquer un contenu sponsorisé ou promotionnel.</li>
          <li>Vérifier vos contenus et réglages avant leur publication ou leur programmation.</li>
          <li>Conserver vos propres copies des contenus importants : SoxialConnect n'est pas un service d'archivage.</li>
        </ul>
      ),
    },
    {
      id: "propriete",
      title: "8. Propriété intellectuelle",
      body: (
        <>
          <p>
            Vous conservez l'ensemble de vos droits sur vos contenus. Vous accordez à SoxialConnect, pour la seule durée nécessaire, une
            autorisation limitée d'héberger, de traiter (par exemple pour générer une vignette) et de transmettre ces contenus aux
            plateformes que vous avez choisies, dans le seul but de fournir le service.
          </p>
          <p>
            Le service, son code, son interface, ses textes et ses éléments graphiques sont protégés et restent la propriété de leur
            éditeur ou de leurs ayants droit. Les noms et logos des plateformes sociales appartiennent à leurs titulaires respectifs ;
            SoxialConnect n'est ni affilié ni approuvé par ces plateformes.
          </p>
        </>
      ),
    },
    {
      id: "disponibilite",
      title: "9. Disponibilité du service",
      body: (
        <p>
          Nous nous efforçons d'assurer un service accessible et fiable, sans pouvoir garantir une disponibilité continue. Le service peut
          être interrompu pour maintenance ou mise à jour. Les publications dépendent aussi des plateformes sociales (disponibilité de leurs
          interfaces, quotas, changements de règles) : une publication peut être retardée ou échouer pour des raisons qui leur sont propres.
          Lorsqu'un envoi échoue, son statut et la raison connue sont affichés dans l'application.
        </p>
      ),
    },
    {
      id: "resiliation",
      title: "10. Suspension et résiliation",
      body: (
        <>
          <p>
            Vous pouvez cesser d'utiliser le service à tout moment et demander la suppression de votre compte à {CONTACT} (voir la{" "}
            <Internal to="/privacy">Politique de confidentialité</Internal>).
          </p>
          <p>
            Nous pouvons suspendre ou fermer un compte en cas de manquement aux présentes conditions, d'utilisation frauduleuse ou
            présentant un risque pour la sécurité du service ou d'autres utilisateurs. Un compte suspendu ne peut plus publier ni modifier
            ses contenus.
          </p>
        </>
      ),
    },
    {
      id: "responsabilite",
      title: "11. Limitation de responsabilité",
      body: (
        <p>
          Dans les limites permises par la loi applicable, SoxialConnect ne saurait être tenu responsable des contenus publiés par les
          utilisateurs, des décisions prises par les plateformes sociales (refus, modération, suspension de compte, modification de leurs
          interfaces), ni des dommages indirects liés à l'utilisation du service ou à son indisponibilité. Rien dans les présentes
          conditions ne limite les droits dont vous bénéficiez en vertu de dispositions légales impératives.
        </p>
      ),
    },
    {
      id: "modifications",
      title: "12. Modification des conditions",
      body: (
        <p>
          Ces conditions peuvent évoluer, notamment lors de l'ajout de nouvelles fonctionnalités ou intégrations. La date de dernière mise
          à jour figure en haut de cette page. En cas de modification importante, vous en serez informé avant son entrée en vigueur.
        </p>
      ),
    },
    {
      id: "droit-applicable",
      title: "13. Droit applicable",
      body: (
        <p>
          Les présentes conditions sont régies par le droit béninois. Tout litige relatif à leur interprétation ou à leur exécution
          relève de la compétence des tribunaux de Cotonou, sous réserve des dispositions légales impératives applicables.
        </p>
      ),
    },
    {
      id: "contact",
      title: "14. Contact",
      body: <p>Pour toute question relative à ces conditions : {CONTACT}.</p>,
    },
  ],
}

// --- Politique de confidentialité -----------------------------------------------------------

const privacy: LegalDocument = {
  title: "Politique de confidentialité",
  intro: (
    <p>
      Cette politique explique quelles données SoxialConnect traite, pourquoi, combien de temps et avec quelles protections. Le
      responsable du traitement est {PUBLISHER}. SoxialConnect ne vend pas vos données et ne les utilise pas à des fins publicitaires.
    </p>
  ),
  sections: [
    {
      id: "donnees-compte",
      title: "1. Données de compte et de profil",
      body: (
        <ul>
          <li>Nom, adresse e-mail et statut de vérification de l'adresse.</li>
          <li>Mot de passe : jamais stocké en clair, uniquement sous forme d'empreinte (hachage).</li>
          <li>Si vous activez la double authentification : sa clé secrète et vos codes de secours, enregistrés chiffrés.</li>
          <li>Préférences du profil, comme votre fuseau horaire (utilisé pour programmer vos publications à la bonne heure).</li>
          <li>Dates de création et de mise à jour du compte.</li>
        </ul>
      ),
    },
    {
      id: "oauth",
      title: "2. Connexions OAuth et jetons d'accès",
      body: (
        <>
          <p>
            Lorsque vous connectez un réseau social, vous vous identifiez directement auprès de la plateforme ; SoxialConnect ne reçoit
            jamais votre mot de passe de réseau social. La plateforme nous transmet des jetons d'accès (et, selon les cas, des jetons de
            renouvellement) correspondant aux seules autorisations que vous avez accordées.
          </p>
          <ul>
            <li>
              Ces jetons sont chiffrés avant d'être enregistrés (AES-256-GCM), avec une clé dédiée distincte de celle du reste de
              l'application.
            </li>
            <li>Ils ne sont jamais transmis à votre navigateur, ni affichés, ni écrits dans les journaux.</li>
            <li>Ils ne servent qu'à exécuter les actions que vous demandez (publier, vérifier le résultat d'une publication, lire le nom du compte).</li>
            <li>Ils sont effacés lorsque vous déconnectez le compte ; SoxialConnect demande aussi leur révocation à la plateforme lorsqu'elle le permet.</li>
          </ul>
        </>
      ),
    },
    {
      id: "plateformes",
      title: "3. Données provenant des plateformes sociales",
      body: (
        <>
          <p>Nous ne demandons que les autorisations nécessaires aux fonctionnalités proposées :</p>
          <ul>
            <li>
              <strong>YouTube (Google)</strong> — autorisations « youtube.upload » (mettre en ligne des vidéos) et « youtube.readonly »
              (identifier la chaîne connectée et lire les statistiques publiques de la chaîne et des vidéos publiées via SoxialConnect :
              vues, mentions « J'aime », commentaires, abonnés). Données conservées : identifiant, nom et identifiant public de la chaîne,
              autorisations accordées, identifiant des vidéos que vous publiez via SoxialConnect et leurs statistiques.
            </li>
            <li>
              <strong>TikTok</strong> — autorisations « user.info.basic » (identifiant et nom affiché du compte) et « video.publish »
              (publier des vidéos). Avant chaque publication, SoxialConnect consulte les options de publication du compte (confidentialités
              disponibles, interactions autorisées, durée maximale) pour les respecter. Données conservées : identifiant et nom affiché du
              compte, autorisations accordées, identifiants de suivi et de publication des vidéos envoyées.
            </li>
            <li>
              <strong>LinkedIn</strong> — autorisations « openid », « profile » et « email » (identifier votre profil) et
              « w_member_social » (publier sur votre profil personnel). Données conservées : identifiant et nom du profil, autorisations
              accordées, identifiants des publications envoyées.
            </li>
            <li>
              <strong>X</strong> — autorisations « users.read » et « tweet.read » (identifier le compte et lire vos publications envoyées
              via SoxialConnect), « tweet.write » et « media.write » (publier des messages et leurs médias) et « offline.access » (rester
              connecté sans vous redemander l'autorisation). Données conservées : identifiant et nom du compte, autorisations accordées,
              identifiants des publications envoyées.
            </li>
            <li>
              <strong>Telegram</strong> — aucune connexion à votre compte personnel : vous ajoutez le bot SoxialConnect comme
              administrateur d'une chaîne ou d'un groupe. Données conservées : identifiant et nom de la chaîne ou du groupe, et vérification
              que vous en êtes administrateur. Le contenu des messages reçus par le bot n'est pas conservé.
            </li>
          </ul>
          <p>
            Les autres réseaux présentés dans l'application ne sont pas encore connectables ; cette politique sera mise à jour avant
            l'ouverture de chaque nouvelle intégration.
          </p>
          <p>
            L'utilisation des données reçues des API Google respecte les{" "}
            <External href="https://developers.google.com/terms/api-services-user-data-policy">Règles d'utilisation des données des services API Google</External>,
            y compris les exigences d'usage limité. Les données Google sont aussi traitées selon les{" "}
            <External href="https://policies.google.com/privacy">Règles de confidentialité de Google</External>. Vous pouvez retirer l'accès
            de SoxialConnect à tout moment depuis la{" "}
            <External href="https://myaccount.google.com/permissions">page des autorisations de votre compte Google</External>, depuis les
            paramètres de l'application TikTok, depuis les{" "}
            <External href="https://www.linkedin.com/psettings/permitted-services">services autorisés de LinkedIn</External>, depuis les{" "}
            <External href="https://x.com/settings/connected_apps">applications connectées de X</External>, ou en retirant le bot de
            votre chaîne ou groupe Telegram. Voir aussi les politiques de confidentialité de{" "}
            <External href="https://www.tiktok.com/legal/privacy-policy">TikTok</External>,{" "}
            <External href="https://www.linkedin.com/legal/privacy-policy">LinkedIn</External>,{" "}
            <External href="https://x.com/privacy">X</External> et{" "}
            <External href="https://telegram.org/privacy">Telegram</External>.
          </p>
        </>
      ),
    },
    {
      id: "contenus",
      title: "4. Contenus et médias",
      body: (
        <ul>
          <li>Textes de vos publications, réglages par réseau (titres, hashtags, visibilité, interactions), dates de programmation.</li>
          <li>
            Médias que vous téléversez (images, vidéos) et leurs informations techniques (type, taille, dimensions, durée). Ils sont stockés
            de manière privée : ils ne sont jamais publics et ne sont accessibles qu'à votre compte, par des liens temporaires valables
            quelques minutes, ou envoyés directement par nos serveurs à la plateforme que vous avez choisie.
          </li>
          <li>
            Historique de chaque tentative de publication : date, origine (publication immédiate, programmée, nouvelle tentative), résultat,
            message d'erreur éventuel et identifiant renvoyé par la plateforme.
          </li>
          <li>
            Statistiques de vos comptes et publications, collectées auprès des plateformes connectées qui les fournissent, pour les afficher
            dans votre tableau de bord.
          </li>
        </ul>
      ),
    },
    {
      id: "creation",
      title: "5. Outils d'intelligence artificielle, vidéos et clips",
      body: (
        <>
          <p>
            Lorsque vous utilisez un outil d'intelligence artificielle (assistant de rédaction, génération d'images, analyse d'une vidéo
            pour proposer des passages), les éléments nécessaires sont transmis au fournisseur d'IA configuré par SoxialConnect : votre
            consigne ou votre texte, la transcription ou un extrait audio de la vidéo, ou le lien d'une vidéo YouTube publique. Les
            fournisseurs utilisés sont OpenAI et Google (Gemini), selon la fonctionnalité. Ces envois n'ont lieu que lorsque vous
            déclenchez la fonctionnalité concernée ; les résultats vous sont présentés pour relecture et ne sont jamais publiés
            automatiquement.
          </p>
          <p>
            Certains traitements sont réalisés sur nos propres serveurs, sans prestataire extérieur : la transcription locale de la parole,
            l'analyse antivirus des fichiers envoyés et le montage des vidéos.
          </p>
          <p>
            Pour créer des clips, une copie de travail d'une vidéo YouTube publique ou non répertoriée peut être récupérée à votre demande,
            après votre déclaration que vous détenez les droits nécessaires. Cette copie est conservée de manière privée puis supprimée
            automatiquement ; les fichiers de travail intermédiaires sont effacés sous 24 heures.
          </p>
        </>
      ),
    },
    {
      id: "journaux",
      title: "6. Journaux techniques et cookies",
      body: (
        <>
          <ul>
            <li>
              Journal de sécurité des actions importantes (connexion, création ou suppression de contenus, connexion ou déconnexion d'un
              réseau, publication), avec l'adresse IP et le navigateur utilisés. Les secrets (mots de passe, jetons, codes d'autorisation)
              sont systématiquement masqués.
            </li>
            <li>Journaux techniques d'erreurs, nécessaires au fonctionnement et à la sécurité du service, eux aussi expurgés des secrets.</li>
          </ul>
          <p>
            SoxialConnect n'utilise que des cookies strictement nécessaires : le cookie de session (maintien de votre connexion) et un cookie
            de protection contre les requêtes frauduleuses (CSRF). Aucun cookie publicitaire, aucun outil de mesure d'audience tiers et
            aucune police de caractères chargée depuis un service externe.
          </p>
        </>
      ),
    },
    {
      id: "finalites",
      title: "7. Finalités du traitement",
      body: (
        <ul>
          <li>Fournir le service : compte, rédaction, stockage des médias, programmation et publication à votre demande.</li>
          <li>Afficher le résultat et les statistiques de vos publications, et vous permettre de les relancer.</li>
          <li>Fournir les outils de création que vous utilisez (assistant de rédaction, images, montage vidéo, clips).</li>
          <li>Assurer la sécurité du service : authentification, prévention des abus, limitation du nombre de requêtes, journal de sécurité.</li>
          <li>Vous envoyer les e-mails indispensables (vérification de l'adresse, réinitialisation du mot de passe).</li>
          <li>Respecter nos obligations légales.</li>
        </ul>
      ),
    },
    {
      id: "securite",
      title: "8. Stockage et sécurité",
      body: (
        <>
          <ul>
            <li>Connexion chiffrée (HTTPS) en production ; cookies de session inaccessibles au code de la page.</li>
            <li>Mots de passe hachés ; jetons des réseaux sociaux chiffrés avec une clé dédiée.</li>
            <li>Cloisonnement des données : chaque utilisateur n'accède qu'à ses propres publications, médias et comptes connectés.</li>
            <li>Médias stockés hors de tout espace public, vérification du type réel et de la taille des fichiers à l'envoi, analyse antivirus.</li>
            <li>Limitation du nombre de tentatives de connexion et de requêtes sensibles ; double authentification disponible.</li>
            <li>Journaux expurgés : les mots de passe, jetons et clés n'y sont jamais écrits.</li>
          </ul>
          <p>
            Hébergement des données : Hostinger, centre de données situé en France. Les plateformes sociales que vous connectez
            peuvent traiter les contenus que vous leur publiez dans d'autres pays, selon leurs propres politiques.
          </p>
        </>
      ),
    },
    {
      id: "conservation",
      title: "9. Durées de conservation",
      body: (
        <ul>
          <li>Données de compte, publications et historique des envois : tant que votre compte existe.</li>
          <li>Jetons d'accès des réseaux sociaux : jusqu'à la déconnexion du compte social ou la suppression de votre compte.</li>
          <li>Médias supprimés : fichiers définitivement effacés 30 jours après leur suppression.</li>
          <li>Copies de travail des vidéos YouTube récupérées pour créer des clips : 7 jours ; fichiers intermédiaires de montage : 24 heures.</li>
          <li>Statistiques : réponses brutes des plateformes conservées 13 mois ; indicateurs agrégés conservés tant que votre compte existe.</li>
          <li>Session : expire après une période d'inactivité ; vous pouvez aussi vous déconnecter à tout moment.</li>
          <li>Journal de sécurité : 12 mois.</li>
        </ul>
      ),
    },
    {
      id: "suppression",
      title: "10. Suppression des données",
      body: (
        <>
          <ul>
            <li>Supprimer une publication ou un média depuis l'application (les fichiers des médias sont effacés sous 30 jours).</li>
            <li>Déconnecter un réseau social depuis la page « Réseaux sociaux » : révocation demandée à la plateforme et effacement immédiat des jetons.</li>
            <li>
              Supprimer votre compte et l'ensemble de vos données : sur simple demande à {CONTACT} depuis l'adresse e-mail du compte. La
              suppression en libre-service depuis l'application sera ajoutée prochainement.
            </li>
          </ul>
          <p>
            Les publications déjà diffusées sur un réseau social restent en ligne sur ce réseau : supprimez-les directement sur la plateforme
            si vous le souhaitez. Voir aussi <Internal to="/legal/data-deletion">Suppression de vos données</Internal>.
          </p>
        </>
      ),
    },
    {
      id: "droits",
      title: "11. Vos droits",
      body: (
        <p>
          Selon la loi applicable, vous disposez d'un droit d'accès, de rectification, d'effacement, de limitation, d'opposition et de
          portabilité de vos données, ainsi que du droit de retirer votre consentement à tout moment (par exemple en déconnectant un réseau
          social). Pour les exercer, écrivez à {CONTACT}. Vous pouvez également introduire une réclamation auprès de l'autorité de protection
          des données compétente.
        </p>
      ),
    },
    {
      id: "tiers",
      title: "12. Services tiers",
      body: (
        <>
          <p>Vos données ne sont communiquées qu'aux services strictement nécessaires :</p>
          <ul>
            <li>
              Les plateformes sociales que vous connectez (Google / YouTube, TikTok, LinkedIn, X, Telegram), uniquement pour les actions
              que vous demandez : elles reçoivent les contenus que vous publiez et les demandes faites avec vos autorisations.
            </li>
            <li>
              Les fournisseurs d'intelligence artificielle (OpenAI, Google Gemini), uniquement pour les fonctionnalités d'IA que vous
              utilisez (voir la section consacrée aux outils d'intelligence artificielle).
            </li>
            <li>Notre hébergeur : Hostinger (serveur privé virtuel).</li>
            <li>Notre service d'envoi d'e-mails : Hostinger (messagerie).</li>
          </ul>
          <p>
            Certains de ces prestataires (notamment Google, OpenAI, LinkedIn et X) peuvent traiter des données hors de l'Union européenne.
            Ces transferts sont encadrés par les garanties prévues par ces prestataires, comme les clauses contractuelles types de la
            Commission européenne.
          </p>
          <p>Aucune donnée n'est vendue, louée ou partagée à des fins publicitaires.</p>
        </>
      ),
    },
    {
      id: "modifications",
      title: "13. Modifications de cette politique",
      body: (
        <p>
          Cette politique sera mise à jour à chaque évolution importante, notamment avant l'ouverture d'une nouvelle intégration. La date de
          dernière mise à jour figure en haut de cette page.
        </p>
      ),
    },
    {
      id: "contact",
      title: "14. Contact",
      body: <p>Pour toute question ou demande concernant vos données : {CONTACT}.</p>,
    },
  ],
}

// --- Suppression des données ---------------------------------------------------------------

const dataDeletion: LegalDocument = {
  title: "Suppression de vos données",
  intro: <p>Vous gardez la maîtrise de vos données à tout moment. Voici comment les supprimer.</p>,
  sections: [
    {
      id: "reseaux",
      title: "1. Déconnecter un réseau social",
      body: (
        <p>
          Depuis la page « Réseaux sociaux », choisissez le compte puis « Déconnecter ». SoxialConnect demande la révocation de l'accès à la
          plateforme lorsqu'elle le permet et efface immédiatement les jetons d'accès enregistrés. Vous pouvez aussi retirer l'accès depuis
          les paramètres de la plateforme (compte Google, application TikTok, services autorisés de LinkedIn, applications connectées de
          X) ou, pour Telegram, en retirant le bot SoxialConnect de votre chaîne ou de votre groupe.
        </p>
      ),
    },
    {
      id: "contenus",
      title: "2. Supprimer des publications ou des médias",
      body: (
        <p>
          Supprimez-les directement depuis l'application. Les fichiers des médias supprimés sont définitivement effacés sous 30 jours. Les
          publications déjà diffusées restent en ligne sur les réseaux sociaux : supprimez-les sur la plateforme concernée si nécessaire.
        </p>
      ),
    },
    {
      id: "compte",
      title: "3. Supprimer votre compte",
      body: (
        <p>
          Envoyez votre demande à {CONTACT} depuis l'adresse e-mail de votre compte. Votre compte, vos contenus, vos médias et vos jetons
          d'accès seront supprimés. Plus de détails dans la <Internal to="/privacy">Politique de confidentialité</Internal>.
        </p>
      ),
    },
  ],
}

export const LEGAL_DOCUMENTS: Record<string, LegalDocument> = {
  terms,
  privacy,
  "data-deletion": dataDeletion,
}
