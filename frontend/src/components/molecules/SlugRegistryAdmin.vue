<script setup>
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import {formatDateOnly, formatDateTime} from '@/utils/dateTimeFormat'
import {showGlassToast} from '@/composables/useGlassToast'
import {useGoToEventSchedule} from '@/composables/useGoToEventSchedule'

defineOptions({name: 'SlugRegistryAdmin'})

const {goToEventSchedule} = useGoToEventSchedule()

const LEVEL_LABELS = {1: 'Regio', 2: 'Quali', 3: 'Finale'}

const loading = ref(true)
const seasons = ref([])
const selectedSeason = ref(null)
const season = ref(null)
const base = ref('')
const reserved = ref([])
const events = ref([])
const summary = ref(null)

const search = ref('')
const filter = ref('all')

const editingId = ref(null)
const editValue = ref('')
const saving = ref(false)
const busyId = ref(null)
const expanded = ref([])

const FILTERS = [
  {key: 'all', label: 'Alle'},
  {key: 'missing', label: 'Ohne Slug'},
  {key: 'manual', label: 'Von Hand'},
  {key: 'off-suggestion', label: 'Weicht vom Vorschlag ab'},
  {key: 'stale-link', label: 'Link veraltet'},
  {key: 'history', label: 'Mit Historie'},
]

const filteredEvents = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return events.value.filter((row) => {
    if (filter.value === 'missing' && row.slug) return false
    if (filter.value === 'manual' && !row.manual) return false
    if (filter.value === 'off-suggestion' && (row.manual || row.follows_suggestion)) return false
    if (filter.value === 'stale-link' && (!row.slug || row.link_current)) return false
    if (filter.value === 'history' && row.history.length === 0) return false
    if (!needle) return true

    const haystack = [
      row.name,
      row.slug,
      row.suggestion,
      row.regional_partner,
      ...row.programs,
      ...row.draht_ids.map(String),
      ...row.history.map((entry) => entry.slug),
    ]
    return haystack.some((value) => String(value || '').toLowerCase().includes(needle))
  })
})

const seasonLabel = computed(() => {
  if (!season.value) return ''
  const name = season.value.name || `Saison ${season.value.year}`
  return season.value.current ? `${name} · aktuelle Saison` : name
})

function levelLabel(level) {
  return LEVEL_LABELS[level] || `Level ${level}`
}

function isExpanded(eventId) {
  return expanded.value.includes(eventId)
}

function toggleHistory(eventId) {
  expanded.value = isExpanded(eventId)
    ? expanded.value.filter((id) => id !== eventId)
    : [...expanded.value, eventId]
}

async function loadSeasons() {
  try {
    const {data} = await axios.get('/seasons')
    seasons.value = Array.isArray(data) ? data : []
  } catch {
    seasons.value = []
  }
}

async function load(seasonId = null) {
  loading.value = true
  try {
    const {data} = await axios.get('/admin/slugs', {
      params: seasonId ? {season: seasonId} : {},
    })
    season.value = data.season
    selectedSeason.value = data.season?.id ?? null
    base.value = data.base || ''
    reserved.value = Array.isArray(data.reserved) ? data.reserved : []
    events.value = Array.isArray(data.events) ? data.events : []
    summary.value = data.summary || null
    expanded.value = []
    editingId.value = null
  } catch (error) {
    showGlassToast(
      'Slug-Übersicht konnte nicht geladen werden: ' +
        (error.response?.data?.error || error.message),
      'error',
    )
    events.value = []
    summary.value = null
  } finally {
    loading.value = false
  }
}

function startEdit(row) {
  editingId.value = row.event_id
  editValue.value = row.slug || row.suggestion || ''
}

function cancelEdit() {
  editingId.value = null
  editValue.value = ''
}

async function saveEdit(row) {
  const slug = editValue.value.trim()
  if (!slug || slug === row.slug) {
    cancelEdit()
    return
  }

  saving.value = true
  try {
    await axios.put(`/publish/slug/${row.event_id}`, {slug})
    showGlassToast(`Slug gesetzt: ${slug}`, 'success')
    cancelEdit()
    await load(selectedSeason.value)
  } catch (error) {
    showGlassToast(error.response?.data?.error || 'Slug konnte nicht gesetzt werden.', 'error')
  } finally {
    saving.value = false
  }
}

async function regenerate(row) {
  busyId.value = row.event_id
  try {
    await axios.post(`/publish/regenerate/${row.event_id}`)
    showGlassToast('Link und QR-Code neu gebaut.', 'success')
    await load(selectedSeason.value)
  } catch (error) {
    showGlassToast(error.response?.data?.error || 'Neu bauen fehlgeschlagen.', 'error')
  } finally {
    busyId.value = null
  }
}

async function copyUrl(url) {
  if (!url) return
  try {
    await navigator.clipboard.writeText(url)
    showGlassToast('URL kopiert', 'success')
  } catch {
    showGlassToast('URL konnte nicht kopiert werden', 'error')
  }
}

onMounted(() => {
  void loadSeasons()
  void load()
})
</script>

<template>
  <div class="space-y-4">
    <div>
      <h2 class="text-xl font-bold mb-1">Öffentliche Links</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Ein Slug pro Event und Saison — die Adresse, unter der DRAHT, JOIN und die QR-Codes den Plan
        finden. Die aktuelle Saison antwortet ohne Jahr, ältere Saisons unter ihrem Jahr.
        <span v-if="base"> Basis: <span class="font-mono">{{ base }}</span></span>
      </p>
    </div>

    <div class="glass-surface-lg border border-[var(--color-border)] space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <label class="block min-w-[14rem]">
          <span class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Saison</span>
          <select
              v-model="selectedSeason"
              class="w-full px-4 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="loading || seasons.length === 0"
              @change="load(selectedSeason)"
          >
            <option
                v-for="item in seasons"
                :key="item.id"
                :value="item.id"
            >
              {{ item.name }} ({{ item.year }})
            </option>
          </select>
        </label>

        <label class="block min-w-[16rem] flex-1">
          <span class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Suche</span>
          <input
              v-model="search"
              type="search"
              placeholder="Event, Slug, Regionalpartner, DRAHT-ID …"
              class="w-full px-4 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </label>

        <button
            type="button"
            class="px-4 py-2 rounded border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] transition-colors disabled:opacity-50"
            :disabled="loading"
            @click="load(selectedSeason)"
        >
          <i class="bi bi-arrow-clockwise mr-1" aria-hidden="true"/>
          Neu laden
        </button>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
            v-for="item in FILTERS"
            :key="item.key"
            type="button"
            class="glass-chip !px-3 !py-1.5 !text-sm"
            :class="filter === item.key ? 'ring-2 ring-blue-500' : ''"
            @click="filter = item.key"
        >
          {{ item.label }}
        </button>
      </div>

      <p v-if="summary" class="text-sm text-[var(--color-text-muted)]">
        {{ seasonLabel }} · {{ summary.total }} Events ·
        {{ summary.without_slug }} ohne Slug ·
        {{ summary.manual }} von Hand ·
        {{ summary.off_suggestion }} weichen vom Vorschlag ab ·
        {{ summary.stale_link }} mit veraltetem Link ·
        {{ summary.history }} alte Slugs
      </p>
    </div>

    <p v-if="loading" class="text-sm text-[var(--color-text-subtle)]">Lade Slugs …</p>

    <p v-else-if="filteredEvents.length === 0" class="text-sm text-[var(--color-text-subtle)]">
      Keine Events zu dieser Auswahl.
    </p>

    <div v-else class="glass-card liquid-surface-inner overflow-auto">
      <table class="table-auto w-full text-sm border-collapse glass-list">
        <thead>
          <tr class="text-xs text-[var(--color-text-muted)] uppercase tracking-wider">
            <th class="text-left font-semibold py-1.5 pr-3">Event</th>
            <th class="text-left font-semibold py-1.5 px-2">Slug</th>
            <th class="text-left font-semibold py-1.5 px-2">Öffentliche URL</th>
            <th class="text-left font-semibold py-1.5 px-2">Status</th>
            <th class="text-right font-semibold py-1.5 pl-2">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in filteredEvents" :key="row.event_id">
            <tr class="align-top hover:bg-[var(--color-bg-hover)]">
              <td class="py-2 pr-3">
                <button
                    type="button"
                    class="text-left font-medium hover:underline"
                    title="Event auswählen und Ablauf öffnen"
                    @click="goToEventSchedule(row.event_id, row.regional_partner_id)"
                >
                  {{ row.name }}
                </button>
                <div class="text-xs text-[var(--color-text-subtle)]">
                  {{ formatDateOnly(row.date) }} · {{ levelLabel(row.level) }}
                  <span v-if="row.regional_partner"> · {{ row.regional_partner }}</span>
                </div>
                <div v-if="row.programs.length" class="text-xs text-[var(--color-text-subtle)]">
                  {{ row.programs.join(', ') }}
                  <span v-if="row.draht_ids.length"> · DRAHT {{ row.draht_ids.join(', ') }}</span>
                </div>
              </td>

              <td class="py-2 px-2">
                <div v-if="editingId === row.event_id" class="flex flex-wrap items-center gap-2">
                  <input
                      v-model="editValue"
                      type="text"
                      class="min-w-[10rem] px-2 py-1 border border-[var(--color-border)] rounded-md font-mono text-sm"
                      :disabled="saving"
                      @keyup.enter="saveEdit(row)"
                      @keyup.esc="cancelEdit"
                  />
                  <button
                      type="button"
                      class="px-2 py-1 rounded bg-blue-500 text-white hover:bg-blue-600 transition-colors disabled:opacity-50"
                      :disabled="saving"
                      @click="saveEdit(row)"
                  >
                    Speichern
                  </button>
                  <button
                      type="button"
                      class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] transition-colors"
                      :disabled="saving"
                      @click="cancelEdit"
                  >
                    Abbrechen
                  </button>
                </div>
                <template v-else>
                  <span v-if="row.slug" class="font-mono">{{ row.slug }}</span>
                  <span v-else class="text-[var(--color-text-subtle)]">—</span>
                  <div
                      v-if="!row.manual && !row.follows_suggestion"
                      class="text-xs text-[var(--color-text-subtle)]"
                  >
                    Vorschlag: <span class="font-mono">{{ row.suggestion }}</span>
                  </div>
                </template>
              </td>

              <td class="py-2 px-2">
                <a
                    v-if="row.url"
                    :href="row.url"
                    target="_blank"
                    rel="noopener"
                    class="font-mono break-all text-[var(--color-accent)] hover:underline"
                >{{ row.url }}</a>
                <span v-else class="text-[var(--color-text-subtle)]">—</span>
                <div v-if="row.stored_link && !row.link_current" class="text-xs text-[var(--color-text-subtle)] break-all">
                  Gespeichert: <span class="font-mono">{{ row.stored_link }}</span>
                </div>
              </td>

              <td class="py-2 px-2">
                <div class="flex flex-wrap gap-1">
                  <span v-if="row.manual" class="glass-chip !px-2 !py-0.5 !text-xs" title="Von Hand gesetzt, Regenerieren lässt den Slug stehen">
                    von Hand
                  </span>
                  <span v-if="!row.slug" class="glass-chip !px-2 !py-0.5 !text-xs text-red-700" title="Noch kein Slug vergeben">
                    kein Slug
                  </span>
                  <span
                      v-if="row.slug && !row.link_current"
                      class="glass-chip !px-2 !py-0.5 !text-xs text-amber-700"
                      title="Der gespeicherte Link passt nicht zum aktuellen Slug — QR-Code neu bauen"
                  >
                    Link veraltet
                  </span>
                  <span v-if="!row.has_qrcode" class="glass-chip !px-2 !py-0.5 !text-xs" title="Kein QR-Code gespeichert">
                    kein QR
                  </span>
                  <button
                      v-if="row.history.length"
                      type="button"
                      class="glass-chip !px-2 !py-0.5 !text-xs"
                      :title="`${row.history.length} alte Slugs, werden auf den aktuellen umgeleitet`"
                      @click="toggleHistory(row.event_id)"
                  >
                    <i
                        class="bi mr-1"
                        :class="isExpanded(row.event_id) ? 'bi-chevron-up' : 'bi-chevron-down'"
                        aria-hidden="true"
                    />
                    Historie ({{ row.history.length }})
                  </button>
                </div>
              </td>

              <td class="py-2 pl-2 text-right whitespace-nowrap">
                <button
                    type="button"
                    class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] transition-colors disabled:opacity-50"
                    :disabled="!row.url"
                    title="Öffentliche URL kopieren"
                    @click="copyUrl(row.url)"
                >
                  <i class="bi bi-clipboard" aria-hidden="true"/>
                </button>
                <button
                    type="button"
                    class="ml-1 px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] transition-colors disabled:opacity-50"
                    :disabled="editingId === row.event_id"
                    title="Slug von Hand setzen"
                    @click="startEdit(row)"
                >
                  <i class="bi bi-pencil" aria-hidden="true"/>
                </button>
                <button
                    type="button"
                    class="ml-1 px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] transition-colors disabled:opacity-50"
                    :disabled="busyId === row.event_id"
                    title="Link und QR-Code neu bauen (ein von Hand gesetzter Slug bleibt)"
                    @click="regenerate(row)"
                >
                  <i class="bi bi-arrow-repeat" aria-hidden="true"/>
                </button>
              </td>
            </tr>

            <tr v-if="isExpanded(row.event_id)" class="bg-[var(--color-bg-muted)]">
              <td colspan="5" class="py-2 px-3">
                <ul class="space-y-1 text-xs">
                  <li v-for="entry in row.history" :key="entry.slug" class="break-all">
                    <span class="font-mono">{{ entry.url }}</span>
                    <span class="text-[var(--color-text-subtle)]">
                      · ersetzt {{ entry.replaced_at ? formatDateTime(entry.replaced_at) : 'unbekannt' }}
                      · leitet auf <span class="font-mono">{{ row.slug || '—' }}</span> um
                    </span>
                  </li>
                </ul>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <p class="text-xs text-[var(--color-text-subtle)]">
      Ein von Hand gesetzter Slug wird bei jeder Regeneration behalten. Reservierte Slugs (App-Routen)
      und rein numerische Slugs sind gesperrt: <span class="font-mono">{{ reserved.join(', ') }}</span>
    </p>
  </div>
</template>
