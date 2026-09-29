import { QueryClient, QueryClientProvider } from "@tanstack/react-query"
import { StrictMode, Suspense } from "react"
import { createRoot } from "react-dom/client"
import { RouterProvider } from "react-router"
import { PageLoader } from "@/components/shared/PageLoader"
import { Toaster } from "@/components/ui/sonner"
import { TooltipProvider } from "@/components/ui/tooltip"
import { preloadHeroImage } from "@/components/landing/heroImage"
import { router } from "@/routes/router"
import "./index.css"

// Landing : l'image du hero (élément LCP) est demandée sans attendre le chunk de la page.
if (window.location.pathname === "/") preloadHeroImage()

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: false },
  },
})

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <TooltipProvider delayDuration={200}>
        <Suspense fallback={<PageLoader />}>
          <RouterProvider router={router} />
        </Suspense>
        <Toaster />
      </TooltipProvider>
    </QueryClientProvider>
  </StrictMode>,
)
