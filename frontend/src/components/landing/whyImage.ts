// Démonstration produit de la section « Pourquoi SoxialConnect » : Images/sect2.png
// (zone Publications + Analytics), exportée en WebP sans agrandissement.
import whyMobile from "@/assets/images/why/why-demo-mobile.webp"
import why640 from "@/assets/images/why/why-demo-640.webp"
import why1024 from "@/assets/images/why/why-demo-1024.webp"
import why1370 from "@/assets/images/why/why-demo-1370.webp"

/** Desktop / tablette : Publications + Analytics (1370 × 552 à la taille native). */
export const WHY_SRC = why1370
export const WHY_SRCSET = `${why640} 640w, ${why1024} 1024w, ${why1370} 1370w`
export const WHY_SIZES = "(min-width: 1280px) 1216px, calc(100vw - 48px)"
export const WHY_SIZE = { width: 1370, height: 552 }

/** Mobile (< 640 px) : recadrage lisible sur la liste des 4 publications illustrées. */
export const WHY_MOBILE_SRC = whyMobile
export const WHY_MOBILE_SIZE = { width: 382, height: 332 }
