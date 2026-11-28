<script setup lang="ts">
import {onMounted, ref} from 'vue'
import axios from 'axios'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'

defineOptions({name: 'EventAdministration'})

type EventRow = {
  id: number
  name: string | null
  date: string | null
  regional_partner: string | null
  closed: boolean
  released: boolean
  referee_sheets: number
  jury_sheets: number
  awards: number
}

type Action = 'release' | 'withdraw' | 'table' | 'scores'

const query = ref('')
const events = ref<EventRow[]>([])
const loading = ref(false)
const error = ref('')
const busyId = ref<number | null>(null)
const pending = ref<{event: EventRow, action: Action} | null>(null)

const copy: Record<Action, {title: string, message: string, confirm: string, type: 'info' | 'warning' | 'danger'}> = {
  release: {
    title: 'Ergebnisse freigeben',
    message: 'Die Ergebnisse erscheinen auf der Standortseite.',
    confirm: 'Freigeben',
    type: 'info',
  },
  withdraw: {
    title: 'Freigabe zurücknehmen',
    message: 'Die Ergebnisse verschwinden von der Standortseite. Die finale Bestätigung bleibt.',
    confirm: 'Zurücknehmen',
    type: 'warning',
  },
  table: {
    title: 'Tabelle zurücksetzen',
    message: 'Pokale, Nominierungen und die finale Bestätigung werden entfernt. Die Freigabe fällt weg. Bögen und Punkte bleiben.',
    confirm: 'Tabelle zurücksetzen',
    type: 'warning',
  },
  scores: {
    title: 'Bewertungen zurücksetzen',
    message: 'Alle Schiri- und Jury-Bögen, Pokale und Nominierungen dieses Events werden gelöscht. Finale Bestätigung und Freigabe fallen weg.',
    confirm: 'Bewertungen löschen',
    type: 'danger',
  },
}

let searchTimer: ReturnType<typeof setTimeout> | undefined

function statusLabel(row: EventRow) {
  if (row.released) return 'Freigegeben'
  if (row.closed) return 'Final bestätigt'
  return 'Offen'
}

function formatDate(value: string | null) {
  if (!value) return '–'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('de-DE')
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const {data} = await axios.get('/admin/event-admin', {params: {q: query.value.trim()}})
    events.value = data.events || []
  } catch (e: any) {
    events.value = []
    error.value = e?.response?.data?.error || 'Die Events konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

function onSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void load(), 220)
}

function ask(event: EventRow, action: Action) {
  pending.value = {event, action}
}

async function confirm() {
  const current = pending.value
  pending.value = null
  if (!current) return
  busyId.value = current.event.id
  error.value = ''
  const path = {
    release: 'release',
    withdraw: 'withdraw',
    table: 'reset-table',
    scores: 'reset-scores',
  }[current.action]
  try {
    await axios.post(`/admin/event-admin/${current.event.id}/${path}`)
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Die Aktion ist fehlgeschlagen.'
  } finally {
    busyId.value = null
  }
}

onMounted(() => void load())
</script>

<template>
  <div class="event-admin">
    <header class="event-admin__head">
      <div>
        <h1>Administration der Events</h1>
        <p>Ergebnisse freigeben, die Tabelle zurücksetzen oder sämtliche Bewertungen eines Events löschen.</p>
      </div>
      <input
          v-model="query"
          class="glass-input"
          type="search"
          placeholder="Event oder Regionalpartner"
          @input="onSearch"
      />
    </header>

    <p v-if="error" class="event-admin__error">{{ error }}</p>
    <p v-else-if="loading && !events.length" class="event-admin__muted">Wird geladen …</p>
    <p v-else-if="!events.length" class="event-admin__muted">Keine Events.</p>

    <ul v-else class="event-admin__list">
      <li v-for="event in events" :key="event.id" class="glass-card liquid-surface-inner event-admin__card">
        <div class="event-admin__identity">
          <h2>{{ event.name || 'Event' }}</h2>
          <p>
            {{ formatDate(event.date) }}
            <template v-if="event.regional_partner"> · {{ event.regional_partner }}</template>
          </p>
          <p class="event-admin__meta">
            <span class="event-admin__status" :class="{'is-live': event.released, 'is-final': event.closed && !event.released}">{{ statusLabel(event) }}</span>
            {{ event.referee_sheets }} Schiri-Bögen · {{ event.jury_sheets }} Jury-Bögen · {{ event.awards }} Einträge in der Tabelle
          </p>
        </div>
        <div class="event-admin__actions">
          <button
              v-if="!event.released"
              type="button"
              class="glass-btn-accent"
              :disabled="busyId === event.id || !event.closed"
              :title="event.closed ? '' : 'Zuerst bestätigt der Veranstalter die Ergebnisse als final.'"
              @click="ask(event, 'release')"
          >
            Ergebnisse freigeben
          </button>
          <button
              v-else
              type="button"
              class="glass-btn-secondary"
              :disabled="busyId === event.id"
              @click="ask(event, 'withdraw')"
          >
            Freigabe zurücknehmen
          </button>
          <button type="button" class="glass-btn-secondary" :disabled="busyId === event.id" @click="ask(event, 'table')">
            Tabelle zurücksetzen
          </button>
          <button type="button" class="glass-btn-danger" :disabled="busyId === event.id" @click="ask(event, 'scores')">
            Bewertungen zurücksetzen
          </button>
        </div>
      </li>
    </ul>

    <ConfirmationModal
        :show="pending !== null"
        :title="pending ? copy[pending.action].title : ''"
        :message="pending ? `${pending.event.name || 'Event'}\n\n${copy[pending.action].message}` : ''"
        :type="pending ? copy[pending.action].type : 'info'"
        :confirm-text="pending ? copy[pending.action].confirm : 'Bestätigen'"
        @confirm="confirm"
        @cancel="pending = null"
    />
  </div>
</template>

<style scoped>
.event-admin {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 64rem;
}

.event-admin__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
}

.event-admin__head h1 {
  margin: 0;
  font-size: 1.6rem;
  font-weight: 800;
}

.event-admin__head p {
  margin: 0.3rem 0 0;
  color: var(--color-text-muted);
}

.event-admin__head input {
  width: min(100%, 22rem);
}

.event-admin__error {
  margin: 0;
  color: #b91c1c;
}

.event-admin__muted {
  margin: 0;
  color: var(--color-text-muted);
}

.event-admin__list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.event-admin__card {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.event-admin__identity h2 {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 800;
}

.event-admin__identity p {
  margin: 0.2rem 0 0;
  color: var(--color-text-muted);
}

.event-admin__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.55rem;
}

.event-admin__status {
  display: inline-flex;
  align-items: center;
  padding: 0.1rem 0.5rem;
  border-radius: 999px;
  background: var(--color-border);
  color: var(--color-text);
  font-size: 0.85rem;
  font-weight: 800;
}

.event-admin__status.is-final {
  background: #fef3c7;
  color: #92400e;
}

.event-admin__status.is-live {
  background: #dcfce7;
  color: #166534;
}

.event-admin__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
</style>
