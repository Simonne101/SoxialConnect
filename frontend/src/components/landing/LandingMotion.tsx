import { useEffect, useRef, type ReactNode } from "react";

/** Progressive enhancement: content stays readable without IntersectionObserver. */
export function LandingMotion({ children }: { children: ReactNode }) {
  const root = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const element = root.current;
    if (!element || !("IntersectionObserver" in window)) return;
    const preference = window.matchMedia("(prefers-reduced-motion: reduce)");
    let observer: IntersectionObserver | undefined;
    const loopObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) =>
          entry.target.classList.toggle(
            "is-loop-visible",
            entry.isIntersecting,
          ),
        );
      },
      { threshold: 0.1 },
    );
    element
      .querySelectorAll("[data-loop]")
      .forEach((item) => loopObserver.observe(item));
    const onVisibility = () => {
      element.dataset.pageVisible = String(!document.hidden);
    };
    onVisibility();
    document.addEventListener("visibilitychange", onVisibility);
    const setup = () => {
      observer?.disconnect();
      const items = element.querySelectorAll<HTMLElement>("[data-reveal]");
      items.forEach((item) => item.classList.remove("reveal-pending"));
      if (preference.matches) return;
      observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              entry.target.classList.remove("reveal-pending");
              entry.target.classList.add("reveal-visible");
              observer?.unobserve(entry.target);
            }
          });
        },
        { threshold: 0.08 },
      );
      items.forEach((item) => {
        // Anchor targets and the initial viewport must never wait to become readable.
        if (item.getBoundingClientRect().top < window.innerHeight) return;
        item.classList.add("reveal-pending");
        observer?.observe(item);
      });
    };
    setup();
    preference.addEventListener("change", setup);
    return () => {
      observer?.disconnect();
      loopObserver.disconnect();
      document.removeEventListener("visibilitychange", onVisibility);
      preference.removeEventListener("change", setup);
      element
        .querySelectorAll(".reveal-pending")
        .forEach((item) => item.classList.remove("reveal-pending"));
    };
  }, []);
  return (
    <div ref={root} className="landing-experience" data-motion="running">
      {children}
    </div>
  );
}
