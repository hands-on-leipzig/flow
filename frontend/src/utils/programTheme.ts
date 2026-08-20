/**
 * Visual identity for FIRST programs in settings / schedule UI.
 * Extend here when Future 5+ / 8+ land in the generator.
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

const THEMES: Record<ProgramKey, ProgramTheme> = {
  explore: {
    key: 'explore',
    shortName: 'Explore',
    productName: 'LEGO League',
    accent: '#00A651',
    logoKey: 'E',
  },
  challenge: {
    key: 'challenge',
    shortName: 'Challenge',
    productName: 'LEGO League',
    accent: '#ED1C24',
    logoKey: 'C',
  },
  future5: {
    key: 'future5',
    shortName: 'Future 5+',
    productName: 'LEGO League',
    // Placeholder until brand assets/colors are finalized
    accent: '#7B2D8E',
    logoKey: 'F5',
  },
  future8: {
    key: 'future8',
    shortName: 'Future 8+',
    productName: 'LEGO League',
    // Placeholder until brand assets/colors are finalized
    accent: '#F78B1F',
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
  return THEMES[program as ProgramKey] ?? THEMES.shared
}

export function listProgramKeys(includeShared = false): ProgramKey[] {
  const keys: ProgramKey[] = ['explore', 'challenge', 'future5', 'future8']
  return includeShared ? [...keys, 'shared'] : keys
}
