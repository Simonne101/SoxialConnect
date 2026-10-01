import { Link } from "react-router"
import { LegalLayout, LegalList, LegalSection } from "@/components/legal/LegalLayout"
import { useDocumentTitle } from "@/hooks/useDocumentTitle"

const UPDATED_AT = "2026-10-01"

const TOC = [
  { id: "responsable", label: "Responsable de traitement" },
  { id: "donnees", label: "Données que nous traitons" },
  { id: "finalites", label: "Finalités du traitement" },
  { id: "base-legale", label: "Bases légales" },
  { id: "oauth", label: "OAuth et jetons d'accès" },
  { id: "plateformes", label: "Intégrations avec les plateformes sociales" },
  { id: "duree", label: "Durée de conservation" },
  { id: "suppression", label: "Suppression des données" },
  { id: "securite", label: "Stockage et sécurité" },
  { id: "destinataires", label: "Destinataires et services tiers" },
  { id: "transferts", label: "Transferts hors Union européenne" },
  { id: "droits", label: "Vos droits" },
  { id: "cookies", label: "Cookies et technologies similaires" },
  { id: "evolutions", label: "Évolutions de la politique" },
  { id: "contact", label: "Contact" },
]

/** Page publique de la Politique de confidentialité (route /privacy). */
export default function PrivacyPage() {
  useDocumentTitle("Politique de confidentialité")
  return (
    <LegalLayout
      title="Politique de confidentialité"
      updatedAt={UPDATED_AT}
      toc={TOC}
      intro={
        <p>
          Cette politique décrit comment SoxialConnect collecte, utilise et protège vos données per-
          sonnelles lorsque vous utilisez notre plateforme de gestion de réseaux sociaux. Elle s'a-
          pplique au site web et à l'application SoxialConnect. Elle est complémentaire de nos{" "}
          <Link to="/terms" className="text-brand-600 hover:underline">
            Conditions d'utilisation
          </Link>
          .
        </p>
      }
    >
      <LegalSection id="responsable" title="1. Responsable de traitement">
        <p>
          Le responsable du traitement des données est l'entité qui exploite SoxialConnect&nbsp;:
        </p>
        <p className="rounded-lg border border-border bg-card px-4 py-3 text-sm">
          Éditeur&nbsp;: <span className="font-medium text-ink">[À COMPLÉTER&nbsp;: raison sociale de l'exploitant de SoxialConnect]</span>
          <br />
          E-mail&nbsp;: <span className="font-medium text-ink">[À COMPLÉTER&nbsp;: adresse e-mail de contact]</span>
          <br />
          Adresse&nbsp;: <span className="font-medium text-ink">[À COMPLÉTER&nbsp;: adresse de siège social]</span>
        </p>
      </LegalSection>

      <LegalSection id="donnees" title="2. Données que nous traitons">
        <p>Selon votre utilisation, nous traitons les catégories suivantes&nbsp;:</p>
        <LegalList
          items={[
            <><strong>Données de compte&nbsp;:</strong> nom, adresse électronique, mot de passe (stocké sous forme de hachage), langue et fuseau horaire choisis, statut de vérification de l'adresse, date de création.</>,
            <><strong>Données de profil&nbsp;:</strong> champs que vous complétez librement dans votre profil (titre d'affichage («&nbsp;headline&nbsp;»), biographie) ainsi que vos préférences (fuseau horaire, locale).</>,
            <><strong>Données liées aux connexions OAuth&nbsp;:</strong> identifiant technique du compte sur la plateforme tierce (par exemple identifiant d'open ID chez TikTok ou identifiant de chaîne YouTube), nom d'affichage et visuel du compte social, type de compte, périmètres d'autorisation (scopes) accordés, dates de connexion et d'expiration de l'autorisation.</>,
            <><strong>Jetons d'accès et de rafraîchissement (access/refresh tokens)&nbsp;:</strong> nécessaires pour agir pour votre compte auprès des API officielles&nbsp;; ils sont chiffrés au repos (voir §&nbsp;5 et §&nbsp;9).</>,
            <><strong>Données provenant des plateformes sociales connectées&nbsp;:</strong> métadonnées de comptes et statistiques exposées par les API officielles que vous avez autorisées (portée, interactions, rapports agrégés), selon les périmètres accordés.</>,
            <><strong>Contenus importés ou téléversés&nbsp;:</strong> textes, légendes, hashtags, images, vidéos, fichiers audio et documents que vous versez dans la médiathèque ou planifiez pour publication, avec leurs métadonnées (format, taille, dossier, plateforme cible, horodatage de planification).</>,
            <><strong>Journal technique applicatif (journal d'audit)&nbsp;:</strong> enregistrements en ajout seul décrivant les actions effectuées sur le service (création/modification de publications, connexions de comptes, opérations sensibles) avec, le cas échéant, adresse IP, utilisateur-agent et identifiant de requête.</>,
            <><strong>Logs serveur&nbsp;:</strong> informations techniques standard enregistrées par l'infrastructure (horodatages, requêtes HTTP, erreurs).</>,
          ]}
        />
        <p>
          Nous ne sollicitions volontairement aucune donnée sensible (santé, opinions, etc.) et
          n'utilisons pas de publicités comportementales ni de traceurs tiers à des fins marketing.
        </p>
      </LegalSection>

      <LegalSection id="finalites" title="3. Finalités du traitement">
        <LegalList
          items={[
            "fournir le service : authentification, gestion du compte et des préférences ;",
            "connecter vos comptes sociaux via OAuth et publier/planifier les contenus que vous nous demandez de transmettre aux plateformes que vous avez sélectionnées ;",
            "récupérer et afficher les données et statistiques mises à disposition par les API officielles des plateformes connectées ;",
            "stocker et organiser vos médias et brouillons ;",
            "assurer la sécurité du service : détection d'abus, journalisation des actions sensibles, limitation de débit ;",
            "respecter nos obligations légales (comptabilité, demandes d'autorités compétentes).",
          ]}
        />
      </LegalSection>

      <LegalSection id="base-legale" title="4. Bases légales">
        <p>
          Conformément au Règlement général sur la protection des données (RGPD), les traitements
          reposent principalement sur&nbsp;: l'exécution du contrat (fourniture du service et des
          fonctionnalités que vous utilisez), votre consentement (connexion de comptes via OAuth,
          périmètres demandés par les plateformes — retirable à tout moment), notre intérêt légitime
          (sécurité, prévention des abus) et l'obligation légale.
        </p>
      </LegalSection>

      <LegalSection id="oauth" title="5. OAuth et jetons d'accès">
        <p>
          Les connexions aux réseaux sociaux utilisent le protocole OAuth&nbsp;2.0 via les interfaces
          officielles des plateformes (notamment Google/YouTube et TikTok&nbsp;; Facebook/Instagram
          via Meta). Lors de l'autorisation, la plateforme tierce délivre des jetons d'accès et, se-
          lon les cas, de rafraîchissement, ainsi que des informations publiques de votre compte so-
          cial.
        </p>
        <p>
          Ces jetons sont stockés côté serveur dans notre base de données, <strong>chiffrés au repos
          </strong> à l'aide d'un chiffrement AES-256-GCM avec une clé dédiée, distincte des autres
          secrets de l'application. Ils ne sont jamais affichés en clair dans l'interface, transmis à
          des tiers non autorisés ni écrits dans les journaux applicatifs. Ils sont utilisés unique-
          ment pour les appels aux API de la plateforme concernée, conformément aux périmètres que
          vous avez accordés.
        </p>
        <p>
          Vous pouvez révoquer une connexion à tout moment depuis la page «&nbsp;Comptes sociaux&nbsp;»
          de l'application ou depuis les réglages de la plateforme tierce&nbsp;; la révocation rend
          les jetons inutilisables et entraîne leur suppression ou leur invalidation dans les meil-
          leurs délais.
        </p>
      </LegalSection>

      <LegalSection id="plateformes" title="6. Intégrations avec les plateformes sociales">
        <p>
          SoxialConnect interagit avec les API officielles des réseaux sociaux que vous connectez
          (à ce jour&nbsp;: TikTok, YouTube/Google, Facebook et Instagram via Meta, LinkedIn, X et
          Pinterest, selon disponibilité). Chaque plateforme agit en tant que destinataire des don-
          nées strictement nécessaires à l'exécution des actions que vous demandez (publication,
          lecture de statistiques…) et traite ces données selon sa propre politique de confidentialité&nbsp;:
        </p>
        <LegalList
          items={[
            <><a href="https://www.tiktok.com/legal/page/global/privacy-policy/en" target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">Politique de confidentialité de TikTok</a></>,
            <><a href="https://policies.google.com/privacy" target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">Politique de confidentialité de Google (YouTube)</a></>,
            <><a href="https://www.facebook.com/about/privacy" target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">Politique de données de Meta (Facebook, Instagram)</a></>,
          ]}
        />
        <p>
          Lorsque vous utilisez des fonctions IA expérimentales, les seules données envoyées au
          prestataire sont celles strictement nécessaires à la génération demandée&nbsp;; nous ne
          vendons jamais vos données à des tiers et n'autorisons pas leur utilisation pour entraîner
          des modèles tiers sans mesure contractuelle appropriée.
        </p>
      </LegalSection>

      <LegalSection id="duree" title="7. Durée de conservation">
        <LegalList
          items={[
            "Données de compte et de profil : pendant toute la durée de vos relations contractuelles, puis effacement après suppression du compte (sous réserve des obligations légales).",
            "Contenus, médias et publications : tant que vous les conservez dans l'application ; supprimés à votre demande ou lors de la suppression du compte.",
            "Jetons OAuth : jusqu'à révocation, expiration ou déconnexion du compte social concerné.",
            "Journal d'audit et logs techniques : durée limitée proportionnée aux finalités de sécurité et de preuve, puis anonymisation ou suppression.",
          ]}
        />
      </LegalSection>

      <LegalSection id="suppression" title="8. Suppression des données">
        <p>
          Vous pouvez supprimer un contenu, un média ou une connexion sociale à tout moment depuis
          l'application. La suppression de votre compte entraîne la suppression de vos données de
          compte, contenus et jetons associés, dans un délai maximal de trente (30) jours, hors co-
          pies de sauvegarde qui sont purgées lors des cycles de rotation et hors données dont la
          conservation est imposée par la loi. Des informations conservées côté plateformes tierces
          (publications déjà diffusées, par exemple) relèvent de leurs propres mécanismes de sup-
          pression.
        </p>
      </LegalSection>

      <LegalSection id="securite" title="9. Stockage et sécurité">
        <p>
          Vos données sont hébergées sur nos serveurs d'exploitation. Nous appliquons des mesures
          techniques et organisationnelles conformes à l'état de l'art&nbsp;: échanges chiffrés en
          transport (HTTPS), mots de passe hachés, jetons OAuth chiffrés au repos (AES-256-GCM, clé
          dédiée), sessions chiffrées, cloisonnement des accès, journalisation des actions sensibles,
          limitation de débit et contrôles réguliers. Aucun système n'étant infaillible, nous ne ga-
          rantissons pas une sécurité absolue, mais nous notifions les violations de données suscep-
          tibles d'affecter vos droits conformément aux obligations légales.
        </p>
      </LegalSection>

      <LegalSection id="destinataires" title="10. Destinataires et services tiers">
        <p>Sont susceptibles de recevoir des données, dans la stricte limite des besoins&nbsp;:</p>
        <LegalList
          items={[
            "les plateformes sociales que vous connectez (voir § 6) ;",
            "nos prestataires techniques d'infrastructure (hébergement, bases de données, file de traitement asynchrone, stockage de fichiers, envoi de courriels transactionnels) — agissant comme sous-traitants, liés par des garanties contractuelles appropriées ;",
            "le cas échéant, les autorités judiciaires ou administratives dûment habilitées.",
          ]}
        />
        <p>Nous ne vendons pas vos données personnelles.</p>
      </LegalSection>

      <LegalSection id="transferts" title="11. Transferts hors Union européenne">
        <p>
          Certaines plateformes sociales tierces et certains prestataires peuvent traiter des don-
          nées hors de l'Union européenne. Dans ce cas, des garanties appropriées sont mises en
          œuvre (décision d'adéquation de la Commission européenne, clauses contractuelles types et
          mesures complémentaires).
        </p>
      </LegalSection>

      <LegalSection id="droits" title="12. Vos droits">
        <p>
          Conformément au RGPD et à la loi applicable, vous disposez d'un droit d'accès, de rectifi-
          cation, d'effacement, de limitation, d'opposition, à la portabilité de vos données, ainsi
          que d'instructions sur le sort de vos données après votre décès. Vous pouvez exercer ces
          droits via les fonctionnalités de l'application (profil, médiathèque, comptes sociaux, su-
          ppression de compte) ou en nous contactant à l'adresse indiquée au §&nbsp;15. Une pièce
          d'identité peut être demandée en cas de doute raisonnable sur votre identité. Vous pouvez
          introduire une réclamation auprès de l'autorité de contrôle compétente (en France&nbsp;:
          CNIL).
        </p>
      </LegalSection>

      <LegalSection id="cookies" title="13. Cookies et technologies similaires">
        <p>
          Nous utilisons exclusivement des cookies techniques nécessaires au fonctionnement du ser-
          vice (session authentifiée, jeton de protection anti-CSRF, préférences d'affichage). Ces
          cookies ne nécessitent pas votre consentement au sens de la directive ePrivacy et ne sont
          pas utilisés à des fins publicitaires ou de profilage.
        </p>
      </LegalSection>

      <LegalSection id="evolutions" title="14. Évolutions de cette politique">
        <p>
          Cette politique peut être mise à jour pour refléter des évolutions légales, techniques ou
          fonctionnelles. La version en vigueur est publiée à l'adresse{" "}
          <Link to="/privacy" className="text-brand-600 hover:underline">/privacy</Link>{" "}
          avec sa date de dernière mise à jour&nbsp;; toute modification substantielle vous sera si-
          gnifiée par un moyen approprié.
        </p>
      </LegalSection>

      <LegalSection id="contact" title="15. Contact">
        <p>
          Pour toute question relative à cette politique ou à l'exercice de vos droits&nbsp;:
        </p>
        <p className="rounded-lg border border-border bg-card px-4 py-3 text-sm">
          E-mail&nbsp;: <span className="font-medium text-ink">[À COMPLÉTER&nbsp;: adresse e-mail de contact confidentialité]</span>
        </p>
      </LegalSection>
    </LegalLayout>
  )
}
