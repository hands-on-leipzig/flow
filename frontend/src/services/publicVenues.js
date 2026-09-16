import axios from 'axios'

function venuesApiUrl() {
  const explicit = import.meta.env.VITE_VENUES_API_URL?.trim()
  if (explicit) return explicit
  const raw = (import.meta.env.VITE_DRAHT_API_URL || '').replace(/\/$/, '')
  if (raw) return `${raw}/handson/node/public/venues`
  return 'https://dev.draht.hands-on-technology.org/custom/handson/api_proxy.php/handson/node/public/venues'
}

const venuesClient = axios.create({
  headers: { Accept: 'application/json' },
})

/**
 * @returns {Promise<{ data: object[], meta?: Record<string, unknown> }>}
 */
export async function fetchPublicVenues() {
  const res = await venuesClient.get(venuesApiUrl())
  const body = res.data ?? {}
  const list = Array.isArray(body.data) ? body.data : []
  return { data: list, meta: body.meta ?? {} }
}

/**
 * FLOW public one-links addressed by DRAHT event id.
 * @returns {Promise<Map<number, string>>}
 */
export async function fetchPublicEventLinks() {
  const res = await axios.get('/public/event-links')
  const rows = Array.isArray(res.data?.data) ? res.data.data : []
  /** @type {Map<number, string>} */
  const byDrahtId = new Map()
  for (const row of rows) {
    const url = typeof row?.url === 'string' ? row.url.trim() : ''
    if (!url || !Array.isArray(row?.draht_ids)) continue
    for (const id of row.draht_ids) {
      const key = Number(id)
      if (Number.isInteger(key)) byDrahtId.set(key, url)
    }
  }
  return byDrahtId
}
