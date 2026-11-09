export type MobileNumberResult =
  | {ok: true; normalized: string | null}
  | {ok: false; error: string}

const ALLOWED_CHARS = /^[0-9+\s\-/().]+$/
const DE_COUNTRY_CODE = '49'

/**
 * Assigned E.164 country calling codes. Not area codes: 1 is NANP, 7 is Russia and Kazakhstan.
 * Keep this list identical to backend/app/Support/GermanMobileNumber.php.
 */
const CALLING_CODES = [
  '1',
  '20', '211', '212', '213', '216', '218',
  '220', '221', '222', '223', '224', '225', '226', '227', '228', '229',
  '230', '231', '232', '233', '234', '235', '236', '237', '238', '239',
  '240', '241', '242', '243', '244', '245', '246', '247', '248', '249',
  '250', '251', '252', '253', '254', '255', '256', '257', '258',
  '260', '261', '262', '263', '264', '265', '266', '267', '268', '269',
  '27', '290', '291', '297', '298', '299',
  '30', '31', '32', '33', '34',
  '350', '351', '352', '353', '354', '355', '356', '357', '358', '359',
  '36',
  '370', '371', '372', '373', '374', '375', '376', '377', '378', '379',
  '380', '381', '382', '383', '385', '386', '387', '389',
  '39', '40', '41', '420', '421', '423',
  '43', '44', '45', '46', '47', '48', '49',
  '500', '501', '502', '503', '504', '505', '506', '507', '508', '509',
  '51', '52', '53', '54', '55', '56', '57', '58',
  '590', '591', '592', '593', '594', '595', '596', '597', '598', '599',
  '60', '61', '62', '63', '64', '65', '66',
  '670', '672', '673', '674', '675', '676', '677', '678', '679',
  '680', '681', '682', '683', '685', '686', '687', '688', '689',
  '690', '691', '692',
  '7',
  '81', '82', '84', '850', '852', '853', '855', '856', '86', '880', '886',
  '90', '91', '92', '93', '94', '95',
  '960', '961', '962', '963', '964', '965', '966', '967', '968',
  '970', '971', '972', '973', '974', '975', '976', '977',
  '98', '992', '993', '994', '995', '996', '998',
] as const

const CALLING_CODE_SET = new Set<string>(CALLING_CODES)

function isMobilePlaceholder(value: string): boolean {
  const normalized = value.trim().toLowerCase()
  if (!normalized) return true
  if (['-', '--', '---', '...', '…', 'n/a', 'na', 'k.a.', 'k. a.', 'none', 'null'].includes(normalized)) {
    return true
  }
  return /^[-–—.…]+$/.test(normalized)
}

/** German national numbers keep the German format. Other countries need + or 00 and an assigned calling code. */
export function validateAndNormalizeMobile(raw: string | null | undefined): MobileNumberResult {
  const trimmed = (raw ?? '').trim()
  if (!trimmed || isMobilePlaceholder(trimmed)) return {ok: true, normalized: null}

  if (!ALLOWED_CHARS.test(trimmed)) {
    return {ok: false, error: 'Ungültige Mobilnummer'}
  }

  const digits = trimmed.replace(/\D/g, '')

  if (trimmed.startsWith('+') || digits.startsWith('00')) {
    const international = trimmed.startsWith('+') ? digits : digits.slice(2)
    return internationalFromDigits(international)
  }

  if (digits.startsWith(DE_COUNTRY_CODE) && digits.length >= 12) {
    return germanFromNational(digits.slice(2))
  }

  if (digits.startsWith('0')) {
    return germanFromNational(digits.slice(1))
  }

  return {ok: false, error: 'Ungültige Mobilnummer'}
}

function germanFromNational(national: string): MobileNumberResult {
  if (!national || national.length < 10 || national.length > 12) {
    return {ok: false, error: 'Ungültige Mobilnummer'}
  }

  return {ok: true, normalized: formatGermanInternational(national)}
}

function internationalFromDigits(digits: string): MobileNumberResult {
  const code = matchCallingCode(digits)
  if (!code) return {ok: false, error: 'Ungültige Mobilnummer'}

  let national = digits.slice(code.length)
  if (code === DE_COUNTRY_CODE) return germanFromNational(national)

  if (national.startsWith('0')) national = national.slice(1)
  if (!national) return {ok: false, error: 'Ungültige Mobilnummer'}

  const total = code.length + national.length
  if (total < 8 || total > 15) return {ok: false, error: 'Ungültige Mobilnummer'}

  return {ok: true, normalized: `+${code} ${national}`}
}

function matchCallingCode(digits: string): string | null {
  for (const length of [3, 2, 1]) {
    if (digits.length < length) continue
    const prefix = digits.slice(0, length)
    if (CALLING_CODE_SET.has(prefix)) return prefix
  }
  return null
}

function formatGermanInternational(national: string): string {
  if (national.length >= 10) {
    return `+49 ${national.slice(0, 3)} ${national.slice(3)}`
  }
  return `+49 ${national}`
}
