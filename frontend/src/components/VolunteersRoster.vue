<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import VolunteerEmailOutreach from '@/components/molecules/VolunteerEmailOutreach.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'

type Person = {
  id: number
  first_name: string
  last_name: string
  nickname: string | null
  email: string
  mobile: string | null
  updated_at: string | null
  on_roster?: boolean
}

type RosterEntry = {
  id: number
  has_assignment: boolean
  created_at: string | null
  person: Person
}

/** Questionnaire fields — UI placeholder until DB/form work lands. */
type RosterFormFields = {
  t_shirt_size: string | null
  meal: string | null
  eve_meeting: string | null
  notes: string | null
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roster = ref<RosterEntry[]>([])
const pool = ref<Person[]>([])
const personSearch = ref('')
const loading = ref(false)
const togglingId = ref<number | null>(null)
const addingId = ref<number | null>(null)
const removeTarget = ref<RosterEntry | null>(null)
const error = ref('')
const toast = ref('')
const sortDir = ref<'asc' | 'desc'>('asc')

const rosterPersonIds = computed(() => new Set(roster.value.map((r) => r.person.id)))

const personSearchMatches = computed(() => {
  const q = personSearch.value.trim().toLowerCase()
  if (!q) return []

  return pool.value
    .filter((p) => searchHaystack(p).includes(q))
    .sort((a, b) => {
      const av = displayName(a).toLocaleLowerCase('de')
      const bv = displayName(b).toLocaleLowerCase('de')
      if (av < bv) return -1
      if (av > bv) return 1
      return a.id - b.id
    })
})

const sortedRoster = computed(() => {
  const dir = sortDir.value === 'asc' ? 1 : -1
  return [...roster.value].sort((a, b) => {
    const av = displayName(a.person).toLocaleLowerCase('de')
    const bv = displayName(b.person).toLocaleLowerCase('de')
    if (av < bv) return -1 * dir
    if (av > bv) return 1 * dir
    return a.person.id - b.person.id
  })
})

const visibleRosterPeople = computed(() => sortedRoster.value.map((entry) => entry.person))

const removeMessage = computed(() => {
  const entry = removeTarget.value
  if (!entry) return ''
  const base = `${displayName(entry.person)} wird von der Helferliste dieser Veranstaltung entfernt.`
  if (entry.has_assignment) {
    return `${base} Bestehende Zuordnungen werden ebenfalls entfernt.`
  }
  return base
})

function displayName(p: Person) {
  if (p.nickname?.trim()) return `${p.first_name} „${p.nickname}“ ${p.last_name}`
  return `${p.first_name} ${p.last_name}`
}

function searchHaystack(p: Person) {
  return [
    p.first_name,
    p.last_name,
    p.nickname,
    p.email,
    p.mobile,
    p.updated_at?.slice(0, 10),
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
}

function isOnRoster(person: Person) {
  return rosterPersonIds.value.has(person.id)
}

function onSearchChipClick(person: Person) {
  if (isOnRoster(person) || addingId.value) return
  addToRoster(person)
}

function placeholderFields(_entry: RosterEntry): RosterFormFields {
  return {
    t_shirt_size: null,
    meal: null,
    eve_meeting: null,
    notes: null,
  }
}

function rosterIconTooltip(entry: RosterEntry) {
  if (entry.has_assignment) {
    return 'Von Helferliste entfernen — Zuordnungen werden ebenfalls entfernt'
  }
  return 'Von Helferliste entfernen'
}

function toggleSort() {
  sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
}

function sortIcon() {
  return sortDir.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down'
}

async function load() {
  if (!eventId.value) return
  loading.value = true
  error.value = ''
  try {
    const [rosterRes, poolRes] = await Promise.all([
      axios.get(`/events/${eventId.value}/volunteer-roster`),
      axios.get(`/events/${eventId.value}/volunteers`),
    ])
    roster.value = rosterRes.data.roster ?? []
    pool.value = poolRes.data.people ?? []
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Laden fehlgeschlagen'
  } finally {
    loading.value = false
  }
}

async function addToRoster(person: Person) {
  if (!eventId.value || addingId.value) return
  addingId.value = person.id
  error.value = ''
  try {
    await axios.post(`/events/${eventId.value}/volunteer-roster`, {
      volunteer_person: person.id,
    })
    await load()
    showToast(`${displayName(person)} zur Helferliste hinzugefügt`)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Hinzufügen fehlgeschlagen'
  } finally {
    addingId.value = null
  }
}

function requestRemove(entry: RosterEntry) {
  if (togglingId.value === entry.person.id) return
  removeTarget.value = entry
}

async function confirmRemove() {
  const entry = removeTarget.value
  if (!eventId.value || !entry || togglingId.value === entry.person.id) return
  togglingId.value = entry.person.id
  error.value = ''
  try {
    await axios.delete(`/events/${eventId.value}/volunteer-roster/${entry.person.id}`)
    removeTarget.value = null
    await load()
    showToast('Von Helferliste entfernt')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Entfernen fehlgeschlagen'
  } finally {
    togglingId.value = null
  }
}

function showToast(msg: string) {
  toast.value = msg
  setTimeout(() => {
    if (toast.value === msg) toast.value = ''
  }, 2200)
}

watch(eventId, () => load(), {immediate: true})
onMounted(() => load())
</script>

<template>
  <div class="vol-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Helferliste</h1>
        <p class="vol-page__sub">Alle Helfer für diese Veranstaltung</p>
      </div>
      <VolunteerEmailOutreach scope="roster" :people="visibleRosterPeople"/>
    </header>

    <div v-if="error" class="glass-alert-warning vol-page__alert">{{ error }}</div>
    <div v-if="toast" class="vol-page__toast">{{ toast }}</div>

    <section class="glass-card liquid-surface-inner vol-tile vol-search-tile">
      <input
          v-model="personSearch"
          type="search"
          class="glass-input glass-input--sm vol-search-tile__input"
          placeholder="Personen suchen…"
          autocomplete="off"
      />
      <div v-if="personSearch.trim()" class="vol-search-results">
        <p v-if="!personSearchMatches.length" class="vol-muted">
          Keine Treffer in der Personenliste.
        </p>
        <div v-else class="vol-search-chips">
          <button
              v-for="person in personSearchMatches"
              :key="person.id"
              type="button"
              class="glass-row-item vol-search-chip"
              :class="isOnRoster(person) ? 'vol-search-chip--on' : 'glass-row-item--interactive'"
              :disabled="addingId === person.id"
              @click="onSearchChipClick(person)"
          >
            <i
                class="bi vol-search-chip__icon"
                :class="isOnRoster(person) ? 'bi-clipboard-check-fill vol-search-chip__icon--roster' : 'bi-person-fill'"
                aria-hidden="true"
            />
            <span class="vol-search-chip__label">{{ displayName(person) }}</span>
          </button>
        </div>
      </div>
    </section>

    <section class="glass-card liquid-surface-inner vol-tile">
      <p v-if="loading" class="vol-muted">Laden…</p>
      <p v-else-if="!roster.length" class="vol-muted">Noch niemand angemeldet.</p>

      <div v-else class="vol-table-frame vol-table-frame--scroll">
        <table class="vol-table">
          <colgroup>
            <col class="vol-col--roster"/>
            <col class="vol-col--name"/>
            <col class="vol-col--tshirt"/>
            <col class="vol-col--meal"/>
            <col class="vol-col--eve"/>
            <col class="vol-col--notes"/>
          </colgroup>
          <thead>
            <tr>
              <th class="vol-table__roster" scope="col"><span class="sr-only">Helferliste</span></th>
              <th scope="col">
                <button type="button" class="vol-sort vol-sort--active" @click="toggleSort">
                  Name
                  <i class="bi" :class="sortIcon()" aria-hidden="true"/>
                </button>
              </th>
              <th scope="col">T-Shirt Größe</th>
              <th scope="col">Essen</th>
              <th scope="col">Vorabendtreffen</th>
              <th scope="col">Bemerkungen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in sortedRoster" :key="entry.id" class="glass-table-row--hover">
              <td class="vol-table__roster">
                <button
                    type="button"
                    class="vol-roster-icon vol-roster-icon--on"
                    :disabled="togglingId === entry.person.id"
                    :aria-label="rosterIconTooltip(entry)"
                    @click="requestRemove(entry)"
                >
                  <i class="bi bi-clipboard-check-fill vol-roster-icon__glyph" aria-hidden="true"/>
                  <span class="vol-roster-icon__tip glass-dropdown" role="tooltip">
                    {{ rosterIconTooltip(entry) }}
                  </span>
                </button>
              </td>
              <td class="vol-table__name">{{ displayName(entry.person) }}</td>
              <td class="vol-table__placeholder">{{ placeholderFields(entry).t_shirt_size ?? '—' }}</td>
              <td class="vol-table__placeholder">{{ placeholderFields(entry).meal ?? '—' }}</td>
              <td class="vol-table__placeholder">{{ placeholderFields(entry).eve_meeting ?? '—' }}</td>
              <td class="vol-table__placeholder">{{ placeholderFields(entry).notes ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <ConfirmationModal
        :show="!!removeTarget"
        type="warning"
        title="Von Helferliste entfernen?"
        :message="removeMessage"
        confirm-text="Entfernen"
        cancel-text="Abbrechen"
        @confirm="confirmRemove"
        @cancel="removeTarget = null"
    />
  </div>
</template>

<style scoped>
.vol-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 0.5rem 0 2rem;
}
.vol-page__header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; }
.vol-page__title { font-size: 1.5rem; font-weight: 650; margin: 0; }
.vol-page__sub { margin: 0.25rem 0 0; opacity: 0.75; }
.vol-page__alert { padding: 0.75rem 1rem; border-radius: 0.75rem; }
.vol-page__toast {
  padding: 0.65rem 1rem;
  border-radius: 0.75rem;
  background: color-mix(in srgb, #15803d 12%, var(--color-bg-muted));
  border: 1px solid color-mix(in srgb, #15803d 30%, var(--color-border));
  font-size: 0.9rem;
}
.vol-tile { padding: 1rem; }
.vol-search-tile {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.vol-search-tile__input {
  width: 100%;
}
.vol-search-results {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.vol-search-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.vol-search-chip {
  font-size: 0.75rem;
  padding: 0.35rem 0.5rem;
  gap: 0.4rem;
}
.vol-search-chip--on {
  cursor: default;
}
.vol-search-chip__icon {
  color: var(--color-text-subtle);
}
.vol-search-chip__icon--roster {
  color: var(--color-accent);
}
.vol-search-chip__label {
  padding: 0;
}
.vol-search-chip:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.vol-muted { opacity: 0.7; font-size: 0.9rem; margin: 0; }

.vol-table-frame {
  width: 100%;
  scrollbar-gutter: stable;
}
.vol-table-frame--scroll {
  max-height: min(62vh, 36rem);
  overflow: auto;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 70%, transparent);
}
.vol-table {
  width: 100%;
  table-layout: fixed;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.875rem;
}
.vol-col--roster { width: 2.75rem; }
.vol-col--name { width: 24%; }
.vol-col--tshirt { width: 12%; }
.vol-col--meal { width: 12%; }
.vol-col--eve { width: 14%; }
.vol-col--notes { width: auto; }

.vol-table th,
.vol-table td {
  padding: 0.4rem 0.45rem;
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid var(--color-border);
}
.vol-table th {
  position: sticky;
  top: 0;
  z-index: 1;
  font-size: 0.75rem;
  font-weight: 650;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--color-text-muted);
  background: color-mix(in srgb, var(--color-bg-muted) 88%, #fff);
  backdrop-filter: blur(8px);
}
.vol-table tbody tr:last-child td { border-bottom: none; }
.vol-table__roster { text-align: center; }
.vol-table th.vol-table__roster,
.vol-table td.vol-table__roster { text-align: center; }
.vol-table__name {
  font-weight: 600;
}
.vol-table__placeholder {
  color: var(--color-text-muted);
}

.vol-roster-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  position: relative;
  width: 2rem;
  height: 2rem;
  margin: 0 auto;
  padding: 0;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  cursor: pointer;
  font-size: 1.1rem;
  line-height: 1;
}
.vol-roster-icon__tip {
  position: absolute;
  top: 50%;
  left: calc(100% + 0.45rem);
  z-index: 30;
  width: max-content;
  max-width: 12rem;
  padding: 0.5rem 0.65rem;
  font-size: 0.8125rem;
  font-weight: 400;
  line-height: 1.4;
  color: var(--color-text-muted);
  text-align: left;
  white-space: normal;
  pointer-events: none;
  opacity: 0;
  transform: translateY(-50%) translateX(-2px);
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.vol-roster-icon:hover .vol-roster-icon__tip,
.vol-roster-icon:focus-visible .vol-roster-icon__tip {
  opacity: 1;
  transform: translateY(-50%) translateX(0);
}
.vol-roster-icon--on {
  color: var(--color-accent);
}
.vol-roster-icon--on:hover:not(:disabled) {
  background: var(--color-accent-muted);
}
.vol-roster-icon:disabled {
  cursor: not-allowed;
}
.vol-roster-icon:disabled .vol-roster-icon__glyph {
  opacity: 0.35;
}

.vol-sort {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  border: none;
  background: transparent;
  color: inherit;
  font: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
  cursor: pointer;
}
.vol-sort .bi {
  font-size: 0.9rem;
  opacity: 0.45;
}
.vol-sort:hover .bi,
.vol-sort--active .bi {
  opacity: 1;
  color: var(--color-accent);
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
