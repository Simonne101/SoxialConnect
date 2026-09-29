import { Hero } from "@/components/landing/Hero";
import { LandingNavbar } from "@/components/landing/LandingNavbar";
import {
  CtaSection,
  FeaturesSection,
  HowItWorksSection,
  LandingFooter,
  PlatformPreviewSection,
  ProblemSection,
} from "@/components/landing/Sections";
import { useDocumentTitle } from "@/hooks/useDocumentTitle";

export default function LandingPage() {
  useDocumentTitle("Gérez tous vos réseaux sociaux");
  return (
    <div className="min-h-dvh bg-landing">
      <a
        href="#accueil"
        className="sr-only focus:not-sr-only focus:fixed focus:top-20 focus:left-4 focus:z-[60] focus:rounded-lg focus:bg-card focus:px-4 focus:py-2 focus:shadow-float"
      >
        Aller au contenu
      </a>
      <LandingNavbar />
      <main>
        <Hero />
        <ProblemSection />
        <FeaturesSection />
        <HowItWorksSection />
        <PlatformPreviewSection />
        <CtaSection />
      </main>
      <LandingFooter />
    </div>
  );
}
