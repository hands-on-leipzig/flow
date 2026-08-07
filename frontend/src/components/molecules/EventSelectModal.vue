<script setup lang="ts">
import {computed, nextTick, onMounted, ref, watch} from 'vue'
import {useRouter} from 'vue-router'
import axios from 'axios'
import dayjs from 'dayjs'
import {useEventStore} from '@/stores/event'
import {useAuth} from '@/composables/useAuth'
import {programLogoAlt, programLogoSrc, seasonLogoAlt, seasonLogoSrc} from '@/utils/images'
import {getAbbreviatedCompetitionType, cleanEventName} from '@/utils/eventTitle'

const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const eventStore = useEventStore()
const {isAdmin} = useAuth()
const router = useRouter()

type Season = {id: number; name: string; year: number}
type SelectableEvent = {
  id: number
  name: string
  date: string
  event_explore?: number | null
  event_challenge?: number | null
  level?: number | {id?: number; name?: string} | null
  regional_partner_id: number
  regional_partner_name: string
}

const seasons = ref<Season[]>([])
const selectedSeasonId = ref<number | null>(null)
const currentSeasonId = ref<number | null>(null)
const groups = ref<any[]>([])
const loading = ref(false)
const searchQuery = ref('')
const userRegionalPartners = ref<number[]>([])
const searchInput = ref<HTMLInputElement | null>(null)

const selectedSeason = computed(() =>
    seasons.value.find((s) => s.id === selectedSeasonId.value) ?? null
)

const flatEvents = computed<SelectableEvent[]>(() => {
  return groups.value.flatMap((rp: any) =>
      (rp.events || []).map((e: any) => ({
        ...e,
        level: typeof e.level === 'object' ? e.level?.id : e.level,
        regional_partner_id: rp.regional_partner?.id,
        regional_partner_name: rp.regional_partner?.name,
      }))
  )
})

const visibleEvents = computed(() => {
  let list = flatEvents.value
  if (isAdmin.value && userRegionalPartners.value.length > 0) {
    list = list.filter((e) => userRegionalPartners.value.includes(e.regional_partner_id))
  }
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return list
  return list.filter((ev) => {
    const name = (ev.name || '').toLowerCase()
    const rp = (ev.regional_partner_name || '').toLowerCase()
    const date = dayjs(ev.date).format('DD.MM.YY').toLowerCase()
    const type = getAbbreviatedCompetitionType(ev).toLowerCase()
    return name.includes(q) || rp.includes(q) || date.includes(q) || type.includes(q)
  })
})

async function loadSeasons() {
  const [{data: seasonList}, {data: current}] = await Promise.all([
    axios.get('/seasons'),
    axios.get('/current-season'),
  ])
  seasons.value = Array.isArray(seasonList) ? seasonList : []
  currentSeasonId.value = current?.id ?? seasons.value[0]?.id ?? null
  if (selectedSeasonId.value == null) {
    selectedSeasonId.value = currentSeasonId.value
  }
}

async function loadEvents() {
  if (!selectedSeasonId.value) return
  loading.value = true
  try {
    const {data} = await axios.get('/events/selectable', {
      params: {season: selectedSeasonId.value},
    })
    groups.value = data || []
    if (isAdmin.value && userRegionalPartners.value.length === 0) {
      try {
        const rpResponse = await axios.get('/user/regional-partners')
        if (rpResponse.data?.regional_partners) {
          userRegionalPartners.value = rpResponse.data.regional_partners.map((rp: any) => rp.id)
        }
      } catch {
        /* ignore */
      }
    }
  } catch (e) {
    console.error('Failed to load selectable events', e)
    groups.value = []
  } finally {
    loading.value = false
  }
}

async function selectEvent(ev: SelectableEvent) {
  try {
    await axios.post('/user/select-event', {
      event: ev.id,
      regional_partner: ev.regional_partner_id,
    })
    await eventStore.fetchSelectedEvent()
    emit('close')
    if (!router.currentRoute.value.path.includes('/overview')) {
      void router.push('/plan/overview')
    }
  } catch (e) {
    console.error('Failed to select event', e)
  }
}

function eventTypeLabel(ev: SelectableEvent) {
  return getAbbreviatedCompetitionType(ev) || '—'
}

function eventPlace(ev: SelectableEvent) {
  return cleanEventName(ev) || ev.name || '—'
}

function isSelected(ev: SelectableEvent) {
  return eventStore.selectedEvent?.id === ev.id
}

watch(
    () => props.open,
    async (open) => {
      if (!open) return
      searchQuery.value = ''
      if (!seasons.value.length) await loadSeasons()
      else if (selectedSeasonId.value == null) selectedSeasonId.value = currentSeasonId.value
      await loadEvents()
      await nextTick()
      searchInput.value?.focus()
    }
)

watch(selectedSeasonId, () => {
  if (props.open) void loadEvents()
})

onMounted(() => {
  if (props.open) {
    void loadSeasons().then(() => loadEvents())
  }
})
</script>

<template>
  <div
      v-if="open"
      class="event-modal-backdrop"
      @click.self="emit('close')"
      @keydown.esc.prevent="emit('close')"
  >
    <div
        class="event-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="event-select-title"
    >
      <header class="event-modal__header">
        <div class="event-modal__header-main">
          <img
              v-if="selectedSeason?.name"
              :src="seasonLogoSrc(selectedSeason.name)"
              :alt="seasonLogoAlt(selectedSeason.name)"
              class="event-modal__season-logo"
          />
          <div class="min-w-0">
            <p class="event-modal__eyebrow">FLOW</p>
            <h2 id="event-select-title" class="event-modal__title">Veranstaltung wählen</h2>
            <p class="event-modal__sub">
              Aktuelle und vergangene Saisons — tippen zum Wechseln.
            </p>
          </div>
        </div>
        <button
            type="button"
            class="event-modal__close"
            aria-label="Schließen"
            @click="emit('close')"
        >
          <i class="bi bi-x-lg" aria-hidden="true"/>
        </button>
      </header>

      <div class="event-modal__filters">
        <div class="event-modal__season-chips" role="tablist" aria-label="Saison">
          <button
              v-for="s in seasons"
              :key="s.id"
              type="button"
              role="tab"
              class="event-modal__chip"
              :class="{
                'is-active': selectedSeasonId === s.id,
                'is-current': s.id === currentSeasonId,
              }"
              :aria-selected="selectedSeasonId === s.id"
              @click="selectedSeasonId = s.id"
          >
            <img
                :src="seasonLogoSrc(s.name)"
                :alt=""
                class="event-modal__chip-logo"
            />
            <span class="event-modal__chip-label">{{ s.name }}</span>
            <span v-if="s.id === currentSeasonId" class="event-modal__chip-badge">aktuell</span>
          </button>
        </div>

        <label class="event-modal__search">
          <i class="bi bi-search" aria-hidden="true"/>
          <input
              ref="searchInput"
              v-model="searchQuery"
              type="search"
              placeholder="Ort, Art oder Datum suchen…"
              autocomplete="off"
          />
        </label>
      </div>

      <div class="event-modal__body">
        <div v-if="loading" class="event-modal__state">
          <div class="event-modal__spinner" aria-hidden="true"/>
          <span>Lade Veranstaltungen…</span>
        </div>

        <div v-else-if="visibleEvents.length === 0" class="event-modal__state">
          <i class="bi bi-calendar2-x text-2xl opacity-50" aria-hidden="true"/>
          <span>Keine Veranstaltungen gefunden.</span>
        </div>

        <ul v-else class="event-modal__list">
          <li v-for="ev in visibleEvents" :key="ev.id">
            <button
                type="button"
                class="event-modal__item"
                :class="{ 'is-selected': isSelected(ev) }"
                @click="selectEvent(ev)"
            >
              <div class="event-modal__item-main min-w-0">
                <div class="event-modal__item-title">
                  <span class="event-modal__type">{{ eventTypeLabel(ev) }}</span>
                  <span class="event-modal__dot" aria-hidden="true">·</span>
                  <span class="truncate">{{ eventPlace(ev) }}</span>
                </div>
                <div class="event-modal__item-meta">
                  <span class="inline-flex items-center gap-1">
                    <i class="bi bi-calendar3" aria-hidden="true"/>
                    {{ dayjs(ev.date).format('DD.MM.YYYY') }}
                  </span>
                  <span v-if="ev.regional_partner_name" class="inline-flex items-center gap-1 truncate">
                    <i class="bi bi-geo-alt" aria-hidden="true"/>
                    <span class="truncate">{{ ev.regional_partner_name }}</span>
                  </span>
                </div>
              </div>

              <div class="event-modal__item-aside">
                <div class="event-modal__programs">
                  <img
                      v-if="ev.event_explore"
                      :src="programLogoSrc('E')"
                      :alt="programLogoAlt('E')"
                      class="event-modal__program"
                      title="Explore"
                  />
                  <img
                      v-if="ev.event_challenge"
                      :src="programLogoSrc('C')"
                      :alt="programLogoAlt('C')"
                      class="event-modal__program"
                      title="Challenge"
                  />
                </div>
                <span v-if="isSelected(ev)" class="event-modal__check" title="Aktuell ausgewählt">
                  <i class="bi bi-check-lg" aria-hidden="true"/>
                </span>
                <i v-else class="bi bi-chevron-right event-modal__chevron" aria-hidden="true"/>
              </div>
            </button>
          </li>
        </ul>
      </div>

      <footer class="event-modal__footer">
        <span class="event-modal__count">
          {{ visibleEvents.length }}
          {{ visibleEvents.length === 1 ? 'Veranstaltung' : 'Veranstaltungen' }}
        </span>
        <button
            v-if="isAdmin"
            type="button"
            class="event-modal__admin-link"
            @click="emit('close'); router.push('/plan/events')"
        >
          Event-Verwaltung
          <i class="bi bi-arrow-right" aria-hidden="true"/>
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.event-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background:
    radial-gradient(120% 80% at 50% 0%, color-mix(in srgb, var(--color-accent) 18%, transparent), transparent 55%),
    rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(6px);
}

.event-modal {
  width: min(100%, 40rem);
  max-height: min(88vh, 42rem);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: 1.15rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 45%, transparent);
  background:
    radial-gradient(120% 70% at 0% 0%, color-mix(in srgb, var(--color-accent) 10%, transparent), transparent 50%),
    #fff;
  box-shadow:
    0 28px 64px rgba(15, 23, 42, 0.22),
    0 8px 20px rgba(15, 23, 42, 0.1);
}

.event-modal__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1.15rem 1.25rem 1rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.event-modal__header-main {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  min-width: 0;
}

.event-modal__season-logo {
  height: 2.75rem;
  width: auto;
  object-fit: contain;
  flex-shrink: 0;
}

.event-modal__eyebrow {
  margin: 0;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-accent);
}

.event-modal__title {
  margin: 0.1rem 0 0;
  font-size: 1.2rem;
  font-weight: 800;
  letter-spacing: -0.025em;
  line-height: 1.15;
}

.event-modal__sub {
  margin: 0.25rem 0 0;
  font-size: 0.82rem;
  color: var(--color-text-muted);
}

.event-modal__close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.1rem;
  height: 2.1rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 55%, #fff);
  color: var(--color-text-muted);
  transition: background 0.12s ease, color 0.12s ease;
}

.event-modal__close:hover {
  background: #fff;
  color: var(--color-text);
}

.event-modal__filters {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.9rem 1.25rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 18%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 35%, #fff);
}

.event-modal__season-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.event-modal__chip {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.65rem 0.35rem 0.4rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  background: #fff;
  color: var(--color-text-muted);
  font-size: 0.78rem;
  font-weight: 650;
  cursor: pointer;
  transition: border-color 0.12s ease, background 0.12s ease, color 0.12s ease, box-shadow 0.12s ease;
}

.event-modal__chip:hover {
  color: var(--color-text);
  border-color: color-mix(in srgb, var(--color-border-strong) 60%, transparent);
}

.event-modal__chip.is-active {
  color: #9a3412;
  background: color-mix(in srgb, var(--color-accent) 14%, #fff);
  border-color: color-mix(in srgb, var(--color-accent) 45%, transparent);
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-accent) 20%, transparent);
}

.event-modal__chip-logo {
  width: 1.25rem;
  height: 1.25rem;
  object-fit: contain;
}

.event-modal__chip-badge {
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  padding: 0.1rem 0.35rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-accent) 18%, transparent);
  color: #9a3412;
}

.event-modal__search {
  position: relative;
  display: block;
}

.event-modal__search .bi-search {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-text-subtle);
  font-size: 0.9rem;
  pointer-events: none;
}

.event-modal__search input {
  width: 100%;
  padding: 0.6rem 0.85rem 0.6rem 2.2rem;
  border-radius: 0.75rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  background: #fff;
  font-size: 0.9rem;
  color: var(--color-text);
}

.event-modal__search input:focus {
  outline: none;
  border-color: color-mix(in srgb, var(--color-accent) 55%, transparent);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 16%, transparent);
}

.event-modal__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 0.65rem 0.85rem 0.85rem;
}

.event-modal__state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.65rem;
  min-height: 12rem;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.event-modal__spinner {
  width: 1.5rem;
  height: 1.5rem;
  border-radius: 999px;
  border: 2px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  border-top-color: var(--color-accent);
  animation: event-modal-spin 0.7s linear infinite;
}

@keyframes event-modal-spin {
  to { transform: rotate(360deg); }
}

.event-modal__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.event-modal__item {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.85rem;
  padding: 0.85rem 0.95rem;
  text-align: left;
  border-radius: 0.9rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 28%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 22%, #fff);
  transition: background 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease, transform 0.12s ease;
  cursor: pointer;
}

.event-modal__item:hover {
  background: #fff;
  border-color: color-mix(in srgb, var(--color-border-strong) 48%, transparent);
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
  transform: translateY(-1px);
}

.event-modal__item.is-selected {
  background: color-mix(in srgb, var(--color-accent) 12%, #fff);
  border-color: color-mix(in srgb, var(--color-accent) 45%, transparent);
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-accent) 22%, transparent);
}

.event-modal__item-title {
  display: flex;
  align-items: baseline;
  gap: 0.35rem;
  min-width: 0;
  font-size: 0.98rem;
  font-weight: 750;
  letter-spacing: -0.02em;
  color: var(--color-text);
}

.event-modal__type {
  color: var(--color-accent);
  flex-shrink: 0;
}

.event-modal__dot {
  color: var(--color-text-subtle);
  flex-shrink: 0;
}

.event-modal__item-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.85rem;
  margin-top: 0.3rem;
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

.event-modal__item-meta .bi {
  font-size: 0.85em;
  opacity: 0.85;
}

.event-modal__item-aside {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  flex-shrink: 0;
}

.event-modal__programs {
  display: flex;
  align-items: center;
  gap: 0.3rem;
}

.event-modal__program {
  width: 1.4rem;
  height: 1.4rem;
  object-fit: contain;
}

.event-modal__check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.45rem;
  height: 1.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-accent) 18%, #fff);
  color: #9a3412;
  font-size: 0.9rem;
}

.event-modal__chevron {
  color: var(--color-text-subtle);
  font-size: 0.95rem;
}

.event-modal__item:hover .event-modal__chevron {
  color: var(--color-text-muted);
}

.event-modal__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 1.25rem;
  border-top: 1px solid color-mix(in srgb, var(--color-border-strong) 20%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 30%, #fff);
}

.event-modal__count {
  font-size: 0.78rem;
  font-weight: 650;
  color: var(--color-text-muted);
}

.event-modal__admin-link {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.82rem;
  font-weight: 650;
  color: var(--color-accent);
}

.event-modal__admin-link:hover {
  text-decoration: underline;
}

@media (max-width: 520px) {
  .event-modal__header-main {
    align-items: flex-start;
  }

  .event-modal__item {
    padding: 0.75rem 0.8rem;
  }
}
</style>
