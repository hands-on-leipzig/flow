import {programCompact, programDisplayName, programId, type EventProgramRef} from '@/utils/eventPrograms'

const FALLBACK_STEM = 'first+fll'

export type ProgramLogoRef = string | number | EventProgramRef | null | undefined
export type ProgramLogoOrientation = 'v' | 'h' | 'hs'

let catalogCache: EventProgramRef[] = []
let eventProgramCache: EventProgramRef[] = []

export function setProgramLogoCatalog(rows: EventProgramRef[]) {
  catalogCache = Array.isArray(rows) ? rows : []
}

export function setEventProgramLogos(rows: EventProgramRef[]) {
  eventProgramCache = Array.isArray(rows) ? rows : []
}

function logoRows(): EventProgramRef[] {
  return [...eventProgramCache, ...catalogCache]
}

function isStem(value: string): boolean {
  return value.startsWith('fll_') || value.startsWith('first+')
}

function rowMatches(row: EventProgramRef, raw: string, compactKey: string): boolean {
  if (row.logo && (row.logo === raw || programCompact(row.logo) === compactKey)) return true
  if (row.name && (row.name === raw || programCompact(row.name) === compactKey)) return true
  const id = programId(row)
  return id > 0 && String(id) === raw
}

function resolveLogoStem(program: ProgramLogoRef): string {
  if (program && typeof program === 'object') {
    if (program.logo) return program.logo
    const fromName = resolveLogoStem(program.name ?? null)
    if (fromName !== FALLBACK_STEM) return fromName
    const id = programId(program)
    return id ? resolveLogoStem(id) : FALLBACK_STEM
  }

  const raw = String(program ?? '').trim()
  if (!raw) return FALLBACK_STEM
  if (isStem(raw)) return raw

  const compactKey = programCompact(raw)
  const match = logoRows().find((row) => rowMatches(row, raw, compactKey))
  return match?.logo || FALLBACK_STEM
}

function catalogNameFor(program: ProgramLogoRef): string | null {
  if (program && typeof program === 'object') {
    return program.name || catalogNameFor(programId(program) || program.logo || null)
  }
  const raw = String(program ?? '').trim()
  if (!raw || isStem(raw)) {
    const match = logoRows().find((row) => row.logo === raw)
    return match?.name ?? null
  }
  const compactKey = programCompact(raw)
  const match = logoRows().find((row) => rowMatches(row, raw, compactKey))
  return match?.name ?? raw
}

// Bilder aus dem Backend laden
export function imageUrl(path: string) {
  const cleanPath = path.startsWith('/') ? path.slice(1) : path
  const parts = cleanPath.split('/')
  const encodedParts = parts.map(p => encodeURIComponent(p))
  return '/' + encodedParts.join('/');
}

export function programLogoSrc(program: ProgramLogoRef, orientation: ProgramLogoOrientation = 'v') {
  const stem = resolveLogoStem(program)
  return imageUrl(`/flow/${stem}_${orientation}.png`)
}

export function programLogoAlt(program: ProgramLogoRef) {
  const name = catalogNameFor(program)
  if (!name) return 'FIRST LEGO League Logo'
  const display = programDisplayName(name)
  if (!display) return 'FIRST LEGO League Logo'
  return `FIRST LEGO League ${display} Logo`
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
