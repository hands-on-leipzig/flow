

// Bilder aus dem Backend laden
export function imageUrl(path: string) {
  const cleanPath = path.startsWith('/') ? path.slice(1) : path
  const parts = cleanPath.split('/')
  const encodedParts = parts.map(p => encodeURIComponent(p))
  return '/' + encodedParts.join('/');
}

// FIRST program Logo als img-Tag zurückgeben
export function programLogoSrc(first_program: string | number, orientation: 'v' | 'h' = 'v') {
  const key = String(first_program || '').toLowerCase()

  if (['2', 'e', 'explore'].includes(key)) {
    return imageUrl(`/flow/fll_explore_${orientation}.png`)
  }
  if (['3', 'c', 'challenge'].includes(key)) {
    return imageUrl(`/flow/fll_challenge_${orientation}.png`)
  }
  if (['8', 'f8', 'future8', 'future_8', 'future-8'].includes(key)) {
    return imageUrl(`/flow/fll_future8_${orientation}.png`)
  }
  return imageUrl(`/flow/first+fll_${orientation}.png`)
}

export function programLogoAlt(first_program: string | number) {
  const key = String(first_program || '').toLowerCase()

  if (['2', 'e', 'explore'].includes(key)) return 'FIRST LEGO League Explore Logo'
  if (['3', 'c', 'challenge'].includes(key)) return 'FIRST LEGO League Challenge Logo'
  if (['8', 'f8', 'future8', 'future_8', 'future-8'].includes(key)) {
    return 'FIRST LEGO League Future 8+ Logo'
  }
  if (['7', 'f5', 'future5', 'future_5', 'future-5'].includes(key)) {
    return 'FIRST LEGO League Future 5+ Logo'
  }
  return 'FIRST LEGO League Logo'
}

/** Season challenge logo, e.g. BIOGLOW → /flow/season_bioglow_v.png */
export function seasonLogoSrc(seasonName: string | null | undefined, orientation: 'v' | 'h' = 'v') {
  if (!seasonName) return imageUrl(`/flow/first+fll_${orientation}.png`)
  const key = String(seasonName).toLowerCase().trim().replace(/\s+/g, '_')
  return imageUrl(`/flow/season_${key}_${orientation}.png`)
}

export function seasonLogoAlt(seasonName: string | null | undefined) {
  if (!seasonName) return 'Saison-Logo'
  return `FIRST LEGO League ${seasonName} Logo`
}