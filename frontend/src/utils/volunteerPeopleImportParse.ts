export type VolunteerImportRow = {
  first_name: string
  last_name: string
  email: string
  mobile: string | null
  organization: string | null
}

const HEADER_HINTS = new Set([
  'first_name',
  'last_name',
  'email',
  'e-mail',
  'mobile',
  'organization',
  'updated_at',
  'vorname',
  'nachname',
  'mobil',
  'organisation',
  'letzte änderung',
  'zuordnung 1 programm',
  'zuordnung 1 rolle',
  't-shirt schnitt',
  't-shirt größe',
  'essen',
  'vorabendtreffen',
  'bemerkungen',
  // legacy export headers (ignored if present as header row)
  'nickname',
  'spitzname',
])

function splitLine(line: string): string[] {
  if (line.includes('\t')) {
    return line.split('\t').map((part) => part.trim())
  }
  if (line.includes(';')) {
    return line.split(';').map((part) => part.trim())
  }
  return line.split(',').map((part) => part.trim())
}

function looksLikeHeader(parts: string[]): boolean {
  return parts.some((part) => HEADER_HINTS.has(part.toLowerCase()))
}

function isEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

function normalizeImportMobile(value: string | undefined): string | null {
  const trimmed = (value ?? '').trim()
  if (!trimmed) return null
  if (/^[-–—.…]+$/u.test(trimmed) || ['---', '...', '…', '-', '--', 'n/a', 'na'].includes(trimmed.toLowerCase())) {
    return null
  }
  return trimmed
}

function normalizeImportOrganization(value: string | undefined): string | null {
  const trimmed = (value ?? '').trim()
  return trimmed === '' ? null : trimmed
}

function parseParts(parts: string[]): VolunteerImportRow | null {
  const cleaned = parts.map((part) => part.trim())
  if (cleaned.filter(Boolean).length < 3) return null

  if (cleaned.length === 3) {
    const [first_name, last_name, email] = cleaned
    if (!first_name || !last_name || !isEmail(email)) return null
    return {first_name, last_name, email, mobile: null, organization: null}
  }

  if (cleaned.length === 4) {
    const [first_name, last_name, email, mobile] = cleaned
    if (!first_name || !last_name || !isEmail(email)) return null
    return {
      first_name,
      last_name,
      email,
      mobile: normalizeImportMobile(mobile),
      organization: null,
    }
  }

  const first_name = cleaned[0] ?? ''
  const last_name = cleaned[1] ?? ''
  const email = cleaned[2] ?? ''
  const mobile = cleaned[3] ?? null
  const organization = cleaned.slice(4).join(' ').trim() || null

  if (!first_name || !last_name || !isEmail(email)) return null

  return {
    first_name,
    last_name,
    email,
    mobile: normalizeImportMobile(mobile ?? undefined),
    organization: normalizeImportOrganization(organization ?? undefined),
  }
}

export function parseVolunteerPeopleImportText(text: string): VolunteerImportRow[] {
  const lines = text.trim().split(/\r?\n/).filter((line) => line.trim())
  if (!lines.length) return []

  const rows: VolunteerImportRow[] = []
  let startIndex = 0
  const firstParts = splitLine(lines[0])
  if (looksLikeHeader(firstParts)) {
    startIndex = 1
  }

  for (let i = startIndex; i < lines.length; i++) {
    const parts = splitLine(lines[i])
    if (!parts.some((part) => part.trim())) continue
    const row = parseParts(parts)
    if (row) rows.push(row)
  }

  return rows
}
