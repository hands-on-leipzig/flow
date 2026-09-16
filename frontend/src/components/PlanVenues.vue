<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { VenuesCatalog, publicEventPathFromUrl } from '@hands-on/glass/venues'
import { fetchPublicEventLinks, fetchPublicVenues } from '@/services/publicVenues'

const router = useRouter()
const loading = ref(true)
const error = ref(null)
const venues = ref([])
const venuesMeta = ref({})
const selectedVenue = ref(null)
const planLinks = ref(new Map())

function venuePublicUrl(venue) {
  const fromLinks = planLinks.value.get(Number(venue?.id))
  if (fromLinks) return fromLinks
  const keys = ['publicPlanUrl', 'public_plan_url', 'planlink', 'plan_link', 'frontendUrl', 'slug']
  for (const key of keys) {
    const raw = typeof venue?.[key] === 'string' ? venue[key].trim() : ''
    if (!raw || /\s/.test(raw)) continue
    if (/^https?:\/\//i.test(raw)) return raw
    if (/^[a-z][a-z0-9+.-]*:/i.test(raw)) continue
    return `${window.location.origin}/${raw.replace(/^\/+/, '')}`
  }
  return ''
}

function toLocalPublicSrc(url) {
  const path = publicEventPathFromUrl(url)
  if (!path) return ''
  return `${window.location.origin}/${path}`
}

async function openVenueDetail(venue) {
  if (!venue?.id) return
  const url = venuePublicUrl(venue)
  const publicPath = publicEventPathFromUrl(url)
  if (publicPath) {
    router.push({
      name: 'plan-venues-event',
      params: { publicPath },
      query: { src: toLocalPublicSrc(url), title: venue.name || '' },
    })
    return
  }
  selectedVenue.value = venue
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const [venuesRes, links] = await Promise.all([
      fetchPublicVenues(),
      fetchPublicEventLinks().catch(() => new Map()),
    ])
    venues.value = venuesRes.data
    venuesMeta.value = venuesRes.meta || {}
    planLinks.value = links
  } catch (e) {
    error.value = e?.message || 'Austragungsorte konnten nicht geladen werden.'
    venues.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="venues-page liquid-surface-scope">
    <section class="venues-hero liquid-surface liquid-surface--accent">
      <h1>Austragungsorte</h1>
      <p>Karte und Liste der Saison. Ein Klick öffnet die öffentliche Seite im FLOW-Rahmen.</p>
    </section>

    <div v-if="loading" class="venues-status">Austragungsorte werden geladen…</div>
    <div v-else-if="error" class="venues-status">
      {{ error }}
      <button type="button" class="btn btn-secondary" @click="load">Erneut versuchen</button>
    </div>
    <p v-else-if="venuesMeta.noActiveSeason" class="venues-hint">Keine aktive Saison im Kalender.</p>
    <p v-else-if="!venues.length" class="venues-hint">Keine Veranstaltungen sichtbar.</p>
    <VenuesCatalog
      v-else
      :venues="venues"
      :selected-venue="selectedVenue"
      @select="openVenueDetail"
      @close="selectedVenue = null"
    />
  </div>
</template>

<style scoped>
.venues-hero {
  margin-bottom: 1.5rem;
  padding: 1.35rem 1.25rem;
}
.venues-hero h1 {
  margin: 0 0 0.5rem;
  font-size: var(--text-3xl);
  font-weight: 700;
}
.venues-hero p {
  margin: 0;
  color: var(--color-text-muted);
}
.venues-status,
.venues-hint {
  padding: 1rem;
  color: var(--color-text-muted);
}
</style>
