import { Link } from "react-router"
import { LegalLayout, LegalList, LegalSection } from "@/components/legal/LegalLayout"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"

const UPDATED_AT = "2026-10-01"

const TOC = [
  { id: "service", label: "Présentation du service" },
  { id: "compte", label: "Compte utilisateur" },
  { id: "utilisation", label: "Utilisation acceptable du service" },
  { id: "contenu", label: "Gestion et publication de contenu" },
  { id: "connexions", label: "Connexions aux plateformes sociales" },
  { id: "responsabilites", label: "Responsabilités de l'utilisateur" },
  { id: "propriete", label: "Propriété intellectuelle" },
  { id: "disponibilite", label: "Disponibilité du service" },
  { id: "suspension", label: "Suspension et résiliation" },
  { id: "responsable-limitation", label: "Limitation de responsabilité" },
  { id: "modifications", label: "Modifications des présentes conditions" },
  { id: "contact", label: "Contact" },
]

/** Page publique des Conditions d'utilisation (route /terms). */
export default function TermsPage() {
  useDocumentTitle("Conditions d'utilisation")
  return (
    <LegalLayout
      title="Conditions d'utilisation"
      updatedAt={UPDATED_AT}
      toc={TOC}
      intro={
        <p>
          Les présentes conditions d'utilisation (« Conditions ») régissent l'accès et l'utilisation
          de SoxialConnect, plateforme web de gestion de réseaux sociaux. En créant un compte ou en
          utilisant le service, vous acceptez ces Conditions. Si vous n'acceptez pas ces Conditions,
          vous ne devez pas utiliser le service. Nous vous invitons également à consulter notre{" "}
          <Link to="/privacy" className="text-brand-600 hover:underline">
            Politique de confidentialité
          </Link>
          , qui fait partie intégrante de ces Conditions.
        </p>
      }
    >
      <LegalSection id="service" title="1. Présentation du service">
        <p>
          SoxialConnect est une application web permettant de centraliser la gestion de vos comptes
          sur plusieurs réseaux sociaux. Le service couvre notamment&nbsp;:
        </p>
        <LegalList
          items={[
            "la connexion de comptes sociaux via les interfaces d'autorisation officielles des plateformes (OAuth)&nbsp;;",
            "la création, l'organisation et la planification de contenus (texte, images, vidéos)&nbsp;;",
            "la publication de ces contenus sur les plateformes que vous avez autorisées&nbsp;;",
            "la consultation d'informations et de statistiques fournies par les API officielles des plateformes connectées.",
          ]}
        />
        <p>
          Les fonctionnalités disponibles dépendent des plateformes effectivement connectées et des
          capacités de leurs API publiques&nbsp;; certaines fonctionnalités peuvent être limitées,
          adaptées ou interrompues sans préavis, notamment en cas d'évolution des API tierces.
        </p>
      </LegalSection>

      <LegalSection id="compte" title="2. Compte utilisateur">
        <p>
          Pour utiliser tout ou partie du service, vous devez créer un compte et fournir une adresse
          électronique valide ainsi que des informations exactes. Vous êtes responsable du maintien
          de la confidentialité de vos identifiants et de toute activité effectuée depuis votre
          compte.
        </p>
        <LegalList
          items={[
            "Vous devez avoir la capacité juridique de conclure un contrat (à défaut, l'accès se fait sous la supervision d'un représentant légal).",
            "Vous vous engagez à nous informer rapidement de toute utilisation non autorisée de votre compte.",
            "Nous pouvons exiger la vérification de votre adresse électronique avant l'accès complet au service.",
          ]}
        />
      </LegalSection>

      <LegalSection id="utilisation" title="3. Utilisation acceptable du service">
        <p>Vous vous engagez à ne pas utiliser le service pour&nbsp;:</p>
        <LegalList
          items={[
            "publier, transmettre ou stocker des contenus illicites, haineux, harcelants, trompeurs, pornographiques portant atteinte à des droits de tiers, ou contrevenant aux règles des plateformes sociales concernées&nbsp;;",
            "violer les conditions d'utilisation et les politiques de développement des plateformes tierces (TikTok, YouTube/Google, Meta, LinkedIn, X, Pinterest, etc.)&nbsp;;",
            "collecter des données utilisateurs sans consentement, envoyer du spam ou mener des actions de manipulation&nbsp;;",
            "tentatives d'accès non autorisé, rétro-ingénierie, sondes de vulnérabilité, attaques par déni de service ou contournement des mesures de sécurité&nbsp;;",
            "utiliser le service pour automatiser des actions interdites par les API officielles, ou dépasser les quotas autorisés&nbsp;;",
            "revendre, sous-licencier ou exploiter commercialement le service sans autorisation écrite.",
          ]}
        />
      </LegalSection>

      <LegalSection id="contenu" title="4. Gestion et publication de contenu">
        <p>
          Vous conservez tous vos droits sur les contenus (textes, images, vidéos, documents) que
          vous importez, téléversez ou publiez via SoxialConnect. Vous nous accordez uniquement la
          licence nécessaire à l'exécution du service&nbsp;: stockage, prévisualisation, conversion
          éventuelle aux formats requis et transmission aux plateformes que vous avez explicitement
          sélectionnées pour chaque publication.
        </p>
        <p>
          Vous garantissez disposer de tous les droits nécessaires sur les contenus publiés et
          assumez l'entière responsabilité de leur conformité. La publication effective dépend des
          API des plateformes cibles&nbsp;; SoxialConnect ne garantit pas qu'un contenu sera accepté,
          conservé ou diffusé par une plateforme tierce.
        </p>
      </LegalSection>

      <LegalSection id="connexions" title="5. Connexions aux plateformes sociales">
        <p>
          Les connexions aux réseaux sociaux s'effectuent exclusivement via les mécanismes d'auto-
          risation officiels (protocole OAuth 2.0) proposés par chaque plateforme. Lors de la
          connexion d'un compte, la plateforme concernée vous demande d'autoriser SoxialConnect à ac-
          céder à certaines données et à publier en son nom&nbsp;; vous pouvez révoquer cette auto-
          risation à tout moment, soit depuis SoxialConnect, soit depuis les réglages du compte sur
          la plateforme concernée.
        </p>
        <p>
          L'utilisation des API des plateformes tierces reste soumise à leurs propres conditions
          d'utilisation et politiques de confidentialité. En cas de modification, de suspension ou
          de suppression d'une API tierce, certaines fonctionnalités peuvent devenir indisponibles
          sans que cela constitue un manquement de notre part.
        </p>
      </LegalSection>

      <LegalSection id="responsabilites" title="6. Responsabilités de l'utilisateur">
        <p>En utilisant le service, vous êtes responsable&nbsp;:</p>
        <LegalList
          items={[
            "des contenus que vous créez, planifiez et publiez&nbsp;;",
            "de la conformité de ces contenus vis-à-vis du droit applicable et des règles des plateformes sociales&nbsp;;",
            "de l'exactitude des informations de votre compte et des comptes sociaux connectés&nbsp;;",
            "de toute action effectuée via les autorisations que vous avez accordées depuis votre compte SoxialConnect.",
          ]}
        />
        <p>
          Vous nous garantissez contre tout recours lié à un manquement à ces obligations, dans les
          limites autorisées par le droit applicable.
        </p>
      </LegalSection>

      <LegalSection id="propriete" title="7. Propriété intellectuelle">
        <p>
          La plateforme SoxialConnect elle-même (interface, marque, logo, code source, design, docs
          produits) est protégée par le droit de la propriété intellectuelle. Sauf indication
          contraire, rien dans les présentes Conditions ne vous confère de droit sur la plateforme.
          Les marques des réseaux sociaux tiers (TikTok, YouTube, Instagram, Facebook, LinkedIn, X,
          Pinterest, etc.) appartiennent à leurs titulaires respectifs&nbsp;; elles ne sont mention-
          nées que pour désigner les services auxquels SoxialConnect se connecte.
        </p>
        <p>
          Vos contenus vous appartiennent&nbsp;: voir l'article 4. Après suppression de votre compte
          ou de vos contenus, nous pouvons conserver des copies transitoires strictement nécessaires
          (sauvegardes, obligations légales), conformément à la Politique de confidentialité.
        </p>
      </LegalSection>

      <LegalSection id="disponibilite" title="8. Disponibilité du service">
        <p>
          Nous mettons tout en œuvre pour assurer la disponibilité et la fiabilité du service, mais
          celui-ci est fourni «&nbsp;en l'état&nbsp;». Des interruptions peuvent survenir (maintenance,
          incidents, pannes des API tierces, limitations de débit imposées par les plateformes). Les
          publications programmées peuvent échouer pour des raisons indépendantes de notre volonté
          (indisponibilité d'une plateforme, révocation d'un jeton, refus de contenu). Nous ne ga-
          rantissons pas un niveau de service minimal sauf engagement écrit contraire.
        </p>
      </LegalSection>

      <LegalSection id="suspension" title="9. Suspension et résiliation">
        <p>
          Vous pouvez cesser d'utiliser le service et supprimer votre compte à tout moment. Nous
          pouvons suspendre ou limiter l'accès à votre compte, temporairement ou définitivement, en
          cas de manquement aux présentes Conditions, de comportement frauduleux ou abusif, de déci-
          sion d'une plateforme tierce concernant vos comptes connectés, ou lorsque la loi l'exige.
          Sauf faute grave de votre part, nous nous efforçons de vous notifier la mesure et de vous
          laisser un délai raisonnable pour exporter vos contenus.
        </p>
        <p>
          Après résiliation, les autorisations OAuth associées à votre compte sont révoquées côté
          SoxialConnect et les données sont traitées conformément à la Politique de confidentialité.
        </p>
      </LegalSection>

      <LegalSection id="responsable-limitation" title="10. Limitation de responsabilité">
        <p>
          Dans les limites permises par le droit applicable, SoxialConnect ne peut être tenu respon-
          sable des dommages indirects ou imprévisibles résultant de l'utilisation ou de l'impossibi-
          lité d'utiliser le service, notamment perte de données, interruption d'activité, pénalités
          ou sanctions infligées par une plateforme sociale, ou conséquences d'une publication effec-
          tuée sur une plateforme tierce. Notre responsabilité pour des dommages directs ne peut excé-
          der, cumulativement, le montant payé pour le service au cours des douze derniers mois pré-
          cédant le fait générateur (ou, en cas d'usage gratuit, cent euros). Ces limitations ne
          s'appliquent pas en cas de faute lourde ou dolosive, ni dans les cas où la loi interdit
          une telle limitation.
        </p>
      </LegalSection>

      <LegalSection id="modifications" title="11. Modifications des présentes conditions">
        <p>
          Nous pouvons modifier les présentes Conditions, notamment pour nous conformer à des évo-
          lutions légales, réglementaires ou techniques (y compris les exigences des plateformes so-
          ciales). La version en vigueur est publiée à l'adresse{" "}
          <Link to="/terms" className="text-brand-600 hover:underline">/terms</Link>
          {" "}avec sa date de dernière mise à jour. En cas de modification substantielle, nous vous
          en informerons par un moyen approprié (notification dans l'application ou courriel). La
          poursuite de l'utilisation du service après l'entrée en vigueur vaut acceptation des Condi-
          tions révisées.
        </p>
      </LegalSection>

      <LegalSection id="contact" title="12. Contact">
        <p>
          Pour toute question relative aux présentes Conditions, vous pouvez nous contacter à
          l'adresse suivante&nbsp;:
        </p>
        <p className="rounded-lg border border-border bg-card px-4 py-3 text-sm">
          E-mail&nbsp;: <span className="font-medium text-ink">[À COMPLÉTER&nbsp;: adresse e-mail de contact]</span>
          <br />
          Éditeur du service&nbsp;: <span className="font-medium text-ink">[À COMPLÉTER&nbsp;: raison sociale et forme juridique de l'exploitant de SoxialConnect]</span>
          <br />
          Adresse&nbsp;: <span className="font-medium text-ink">[À COMPLÉTER&nbsp;: adresse de siège social]</span>
        </p>
        <p>
          Les présentes Conditions sont régies par le droit français. À défaut d'accord amiable,
          compétence est attribuée aux tribunaux compétents, sous réserve des règles applicables en
          matière de protection des consommateurs.
        </p>
      </LegalSection>
    </LegalLayout>
  )
}
