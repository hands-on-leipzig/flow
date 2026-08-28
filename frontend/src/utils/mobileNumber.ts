export type MobileNumberResult =
  | {ok: true; normalized: string | null}
  | {ok: false; error: string}

const ALLOWED_CHARS = /^[0-9+\s\-/().]+$/
const DE_COUNTRY_CODE = '49'

function isMobilePlaceholder(value: string): boolean {
  const normalized = value.trim().toLowerCase()
  if (!normalized) return true
  if (['-', '--', '---', '...', '…', 'n/a', 'na', 'k.a.', 'k. a.', 'none', 'null'].includes(normalized)) {
    return true
  }
  return /^[-–—.…]+$/.test(normalized)
}

/** Soft validation + normalization for German mobile numbers (DE only for now). */
export function validateAndNormalizeMobile(raw: string | null | undefined): MobileNumberResult {
  const trimmed = (raw ?? '').trim()
  if (!trimmed || isMobilePlaceholder(trimmed)) return {ok: true, normalized: null}

  if (!ALLOWED_CHARS.test(trimmed)) {
    return {ok: false, error: 'Ungültige Mobilnummer'}
  }

  const digits = trimmed.replace(/\D/g, '')
  let national: string

  if (trimmed.startsWith('+')) {
    if (!trimmed.startsWith('+49')) {
      return {ok: false, error: 'Nur deutsche Nummern (+49)'}
    }
    national = digits.slice(2)
  } else if (digits.startsWith('0049')) {
    national = digits.slice(4)
  } else if (digits.startsWith('49') && digits.length >= 12) {
    national = digits.slice(2)
  } else if (digits.startsWith('0')) {
    national = digits.slice(1)
  } else {
    return {ok: false, error: 'Ungültige Mobilnummer'}
  }

  if (!national || national.length < 10 || national.length > 12) {
    return {ok: false, error: 'Ungültige Mobilnummer'}
  }

  return {ok: true, normalized: formatGermanInternational(national)}
}

function formatGermanInternational(national: string): string {
  if (national.length >= 10) {
    return `+49 ${national.slice(0, 3)} ${national.slice(3)}`
  }
  return `+49 ${national}`
}
