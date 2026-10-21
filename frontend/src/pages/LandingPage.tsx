import { LandingMotion } from "@/components/landing/LandingMotion";
import {
  NetworkSection,
  VideoSpotlight,
  AudienceSection,
} from "@/components/landing/SpotlightSections";
import "@/components/landing/landing.css";
import { Hero } from "@/components/landing/Hero";
import { LandingNavbar } from "@/components/landing/LandingNavbar";
import {
  CtaSection,
  FeaturesSection,
  FaqSection,
  HowItWorksSection,
  LandingFooter,
  PlatformPreviewSection,
  ProblemSection,
} from "@/components/landing/Sections";
import { useDocumentTitle } from "@/hooks/useDocumentTitle";

export default function LandingPage() {
  useDocumentTitle("Gérez tous vos réseaux sociaux");
  return (
    <LandingMotion>
      <a
        href="#accueil"
        className="sr-only focus:not-sr-only focus:fixed focus:top-20 focus:left-4 focus:z-[60] focus:rounded-lg focus:bg-card focus:px-4 focus:py-2 focus:shadow-float"
      >
        Aller au contenu
      </a>
      <LandingNavbar />
      <main>
        <Hero />
        <NetworkSection />
        <ProblemSection />
        <FeaturesSection />
        <VideoSpotlight />
        <HowItWorksSection />
        <PlatformPreviewSection />
        <AudienceSection />
        <FaqSection />
        <CtaSection />
      </main>
      <LandingFooter />
    </LandingMotion>
  );
}
