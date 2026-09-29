// Visuels des publications fictives (paysage, productivité, café, voyage).
//
// PROVISOIRE : ces 4 vignettes sont extraites de la maquette Images/sect2.png (taille native 50 × 50 px,
// sans agrandissement). Elles conviennent aux vignettes de liste (44–56 px) mais pas aux grands aperçus.
// Pour les remplacer par de vraies photos haute résolution, il suffit de remplacer les fichiers
// src/assets/images/posts/<clé>.webp (même nom) et de mettre à jour width / height ci-dessous.
import cafe from "@/assets/images/posts/cafe.webp"
import paysage from "@/assets/images/posts/paysage.webp"
import productivite from "@/assets/images/posts/productivite.webp"
import voyage from "@/assets/images/posts/voyage.webp"

export interface PostImage {
  mediaId: string
  fileName: string
  src: string
  width: number
  height: number
  sizeBytes: number
  tags: string[]
}

export const POST_IMAGES = {
  paysage: { mediaId: "m-18", fileName: "paysage-lac-montagne.webp", src: paysage, width: 50, height: 50, sizeBytes: 1_126, tags: ["nature", "paysage"] },
  productivite: { mediaId: "m-19", fileName: "bureau-ordinateur.webp", src: productivite, width: 50, height: 50, sizeBytes: 1_536, tags: ["productivité", "bureau"] },
  cafe: { mediaId: "m-20", fileName: "tasse-cafe.webp", src: cafe, width: 50, height: 50, sizeBytes: 1_024, tags: ["café", "inspiration"] },
  voyage: { mediaId: "m-21", fileName: "voyage-decouverte.webp", src: voyage, width: 50, height: 50, sizeBytes: 1_638, tags: ["voyage", "découverte"] },
} satisfies Record<string, PostImage>
