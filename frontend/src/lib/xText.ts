// Longueur d'un post X telle que X la compte (docs.x.com « Counting characters », configuration v3
// de twitter-text). Même règle que le serveur (backend XPostLength) :
// - texte normalisé en NFC ;
// - chaque URL http(s) compte 23 caractères (t.co) ;
// - chaque emoji compte 2, même composé (séquences ZWJ, drapeaux, touches) ;
// - plages latines, grecques, cyrilliques… et ponctuation courante : 1 ; tout le reste (CJK…) : 2.

/** Plages de code points de poids 1 (twitter-text v3) ; tout le reste pèse 2. */
const LIGHT_RANGES: ReadonlyArray<readonly [number, number]> = [
  [0x0000, 0x10ff],
  [0x2000, 0x200d],
  [0x2010, 0x201f],
  [0x2032, 0x2037],
]
const URL_LENGTH = 23
const segmenter = new Intl.Segmenter(undefined, { granularity: "grapheme" })

const isLight = (codePoint: number) => LIGHT_RANGES.some(([start, end]) => codePoint >= start && codePoint <= end)

/** Drapeau, touche, pictogramme suivi de U+FE0F ou hors des plages latines (« © » seul reste du texte). */
function isEmoji(grapheme: string): boolean {
  const codePoints = Array.from(grapheme, (c) => c.codePointAt(0) ?? 0)
  if (codePoints.some((cp) => (cp >= 0x1f1e6 && cp <= 0x1f1ff) || cp === 0x20e3)) return true
  if (!/\p{Extended_Pictographic}/u.test(grapheme)) return false
  return codePoints.some((cp) => cp === 0xfe0f || cp > 0x10ff)
}

export function xWeightedLength(text: string): number {
  let urls = 0
  const rest = text.normalize("NFC").replace(/https?:\/\/\S+/giu, () => {
    urls++
    return " "
  })
  let length = urls * URL_LENGTH - urls // chaque URL remplacée par une espace (poids 1)
  for (const { segment } of segmenter.segment(rest)) {
    if (isEmoji(segment)) {
      length += 2
      continue
    }
    for (const char of segment) length += isLight(char.codePointAt(0) ?? 0) ? 1 : 2
  }
  return length
}
