

// Bilder aus dem Backend laden
export function imageUrl(path: string) {
  const cleanPath = path.startsWith('/') ? path.slice(1) : path
  const parts = cleanPath.split('/')
  const encodedParts = parts.map(p => encodeURIComponent(p))
  return '/' + encodedParts.join('/');
}

// FIRST program Logo als img-Tag zurückgeben
export function programLogoSrc(first_program: string | number) {
  const key = String(first_program || '').toLowerCase()

  if (['2', 'e', 'explore'].includes(key)) {
    return imageUrl('/flow/fll_explore_v.png')
  }
  if (['3', 'c', 'challenge'].includes(key)) {
    return imageUrl('/flow/fll_challenge_v.png')
  }
  return imageUrl('/flow/first_v.png')
}

export function programLogoAlt(first_program: string | number) {
  const key = String(first_program || '').toLowerCase()

  if (['2', 'e', 'explore'].includes(key)) return 'FIRST LEGO League Explore Logo'
  if (['3', 'c', 'challenge'].includes(key)) return 'FIRST LEGO League Challenge Logo'
  return 'Logo unbekannt'
}