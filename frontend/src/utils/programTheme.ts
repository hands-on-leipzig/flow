/**
 * Visual identity for FIRST programs in settings / schedule UI.
 * Accents are m_first_program.color_hex (FUTURE_5 is null in the catalog).
 */
export type ProgramKey = 'explore' | 'challenge' | 'future5' | 'future8' | 'shared'

export type ProgramTheme = {
  key: ProgramKey
  /** Short label for scanning (Explore, Challenge, …) */
  shortName: string
  /** Full product line after italic FIRST */
  productName: string
  accent: string
  /** Logo key for programLogoSrc */
  logoKey: string
}

/** Live m_first_program.color_hex. FUTURE_5 has no catalog color. */
export const PROGRAM_COLOR_HEX = {
  EXPLORE: '#00A651',
  CHALLENGE: '#ED1C24',
  DISCOVER: '#662D91',
  FUTURE_8: '#5CD4C2',
} as const

const THEMES: Record<ProgramKey, ProgramTheme> = {
  explore: {
    key: 'explore',
    shortName: 'Explore',
    productName: 'LEGO League',
    accent: PROGRAM_COLOR_HEX.EXPLORE,
    logoKey: 'E',
  },
  challenge: {
    key: 'challenge',
    shortName: 'Challenge',
    productName: 'LEGO League',
    accent: PROGRAM_COLOR_HEX.CHALLENGE,
    logoKey: 'C',
  },
  future5: {
    key: 'future5',
    shortName: 'Future 5+',
    productName: 'LEGO League',
    accent: '#888888',
    logoKey: 'F5',
  },
  future8: {
    key: 'future8',
    shortName: 'Future 8+',
    productName: 'LEGO League',
    accent: PROGRAM_COLOR_HEX.FUTURE_8,
    logoKey: 'F8',
  },
  shared: {
    key: 'shared',
    shortName: 'Gemeinsam',
    productName: 'LEGO League',
    accent: 'var(--color-accent, #F78B1F)',
    logoKey: '',
  },
}

export function getProgramTheme(program: string): ProgramTheme {
  const compact = String(program || '').toLowerCase().replace(/[_-]/g, '')
  if (compact === 'explore' || compact === 'e' || compact === '2') return THEMES.explore
  if (compact === 'challenge' || compact === 'c' || compact === '3') return THEMES.challenge
  if (compact === 'future5' || compact === 'f5' || compact === '7') return THEMES.future5
  if (compact === 'future8' || compact === 'f8' || compact === '8') return THEMES.future8
  if (compact === 'discover' || compact === 'd' || compact === '1') {
    return {
      key: 'shared',
      shortName: 'Discover',
      productName: 'LEGO League',
      accent: PROGRAM_COLOR_HEX.DISCOVER,
      logoKey: '',
    }
  }
  return THEMES[program as ProgramKey] ?? THEMES.shared
}

export function listProgramKeys(includeShared = false): ProgramKey[] {
  const keys: ProgramKey[] = ['challenge', 'explore', 'future5', 'future8']
  return includeShared ? [...keys, 'shared'] : keys
}
