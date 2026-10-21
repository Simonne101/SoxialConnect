// Démonstration produit de la section « Pourquoi SoxialConnect » : Images/Img1.png
// (1967 × 800 : Publications + Analytics), exportée en WebP sans agrandissement.
import whyMobile from "@/assets/images/why/why-img1-mobile.webp"
import why640 from "@/assets/images/why/why-img1-640.webp"
import why1024 from "@/assets/images/why/why-img1-1024.webp"
import why1440 from "@/assets/images/why/why-img1-1440.webp"
import why1967 from "@/assets/images/why/why-img1-1967.webp"

/** Desktop / tablette : Publications + Analytics (1967 × 800 à la taille native). */
export const WHY_SRC = why1440
export const WHY_SRCSET = `${why640} 640w, ${why1024} 1024w, ${why1440} 1440w, ${why1967} 1967w`
export const WHY_SIZES = "(min-width: 1280px) 1216px, calc(100vw - 48px)"
export const WHY_SIZE = { width: 1967, height: 800 }

/** Mobile (< 640 px) : recadrage lisible sur la liste des 4 publications illustrées. */
export const WHY_MOBILE_SRC = whyMobile
export const WHY_MOBILE_SIZE = { width: 640, height: 495 }
