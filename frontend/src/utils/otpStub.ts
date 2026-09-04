/** Public-form OTP is still a stub. Accept 007008 only on Local / Dev / Test. */

const OTP_STUB_HOSTS = new Set([
  'localhost',
  '127.0.0.1',
  'dev.flow.hands-on-technology.org',
  'test.flow.hands-on-technology.org',
])

export const OTP_STUB_CODE = '007008'

export function isOtpStubAllowed(hostname?: string): boolean {
  const host =
    hostname ?? (typeof window !== 'undefined' ? window.location.hostname : '')
  return OTP_STUB_HOSTS.has(host)
}

export function isOtpStubAccepted(code: string, hostname?: string): boolean {
  return isOtpStubAllowed(hostname) && code.trim() === OTP_STUB_CODE
}
