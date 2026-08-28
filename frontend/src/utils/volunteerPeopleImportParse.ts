export type VolunteerImportRow = {
  first_name: string
  last_name: string
  nickname: string | null
  email: string
  mobile: string | null
}

const HEADER_HINTS = new Set([
  'first_name',
  'last_name',
  'nickname',
  'email',
  'e-mail',
  'mobile',
  'vorname',
  'nachname',
  'spitzname',
  'mobil',
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

function parseParts(parts: string[]): VolunteerImportRow | null {
  const cleaned = parts.map((part) => part.trim()).filter((part, index, all) => part.length > 0 || all.length <= 5)
  if (cleaned.length < 3) return null

  if (cleaned.length === 3) {
    const [first_name, last_name, email] = cleaned
    if (!first_name || !last_name || !isEmail(email)) return null
    return {first_name, last_name, nickname: null, email, mobile: null}
  }

  if (cleaned.length === 4) {
    const [first_name, last_name, third, fourth] = cleaned
    if (!first_name || !last_name) return null
    if (isEmail(third)) {
      return {first_name, last_name, nickname: null, email: third, mobile: fourth || null}
    }
    if (isEmail(fourth)) {
      return {first_name, last_name, nickname: third || null, email: fourth, mobile: null}
    }
    return null
  }

  const [first_name, last_name, nickname, email, mobile] = cleaned.slice(0, 5)
  if (!first_name || !last_name || !isEmail(email)) return null

  return {
    first_name,
    last_name,
    nickname: nickname || null,
    email,
    mobile: mobile || null,
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
    const parts = splitLine(lines[i]).filter((part) => part.length > 0)
    if (!parts.length) continue
    const row = parseParts(parts)
    if (row) rows.push(row)
  }

  return rows
}
