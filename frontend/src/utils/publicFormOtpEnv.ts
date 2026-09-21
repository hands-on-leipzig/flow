/** Public-form OTP is redirected to the logged-in FLOW user off production. */

const NON_PRODUCTION_HOSTS = new Set([
  'localhost',
  '127.0.0.1',
  'dev.flow.hands-on-technology.org',
  'test.flow.hands-on-technology.org',
])

export function isNonProductionPublicHost(hostname?: string): boolean {
  const host =
    hostname ?? (typeof window !== 'undefined' ? window.location.hostname : '')

  return NON_PRODUCTION_HOSTS.has(host)
}
