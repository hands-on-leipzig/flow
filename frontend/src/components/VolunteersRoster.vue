<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import VolunteerEmailOutreach from '@/components/molecules/VolunteerEmailOutreach.vue'

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

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roster = ref<RosterEntry[]>([])
const pool = ref<Person[]>([])
const pickId = ref<number | ''>('')
const loading = ref(false)
const error = ref('')
const toast = ref('')

const availablePool = computed(() =>
  pool.value.filter((p) => !roster.value.some((r) => r.person.id === p.id)),
)

function displayName(p: Person) {
  if (p.nickname?.trim()) return `${p.first_name} „${p.nickname}“ ${p.last_name}`
  return `${p.first_name} ${p.last_name}`
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

async function addToRoster() {
  if (!eventId.value || !pickId.value) return
  error.value = ''
  try {
    await axios.post(`/events/${eventId.value}/volunteer-roster`, {
      volunteer_person: pickId.value,
    })
    pickId.value = ''
    await load()
    showToast('Zur Helferliste hinzugefügt')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Hinzufügen fehlgeschlagen'
  }
}

async function removeFromRoster(entry: RosterEntry) {
  if (!eventId.value) return
  const msg = entry.has_assignment
    ? `${displayName(entry.person)} von der Helferliste entfernen? Bestehende Zuordnungen werden ebenfalls entfernt.`
    : `${displayName(entry.person)} von der Helferliste entfernen?`
  if (!confirm(msg)) return
  error.value = ''
  try {
    await axios.delete(`/events/${eventId.value}/volunteer-roster/${entry.person.id}`)
    await load()
    showToast('Von Helferliste entfernt')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Entfernen fehlgeschlagen'
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
        <p class="vol-page__sub">Wer ist für diese Veranstaltung dabei? (Formular folgt später.)</p>
      </div>
      <VolunteerEmailOutreach scope="roster"/>
    </header>

    <div v-if="error" class="glass-alert-danger vol-page__alert">{{ error }}</div>
    <div v-if="toast" class="glass-alert-success vol-page__alert">{{ toast }}</div>

    <section class="glass-card liquid-surface-inner vol-page__main">
      <div class="vol-composer">
        <label class="vol-muted">Aus dem Pool wählen</label>
        <div class="vol-composer__row">
          <select v-model="pickId" class="glass-input">
            <option value="">— Person —</option>
            <option v-for="p in availablePool" :key="p.id" :value="p.id">
              {{ displayName(p) }} · {{ p.email }}
            </option>
          </select>
          <button type="button" class="glass-btn-accent" :disabled="!pickId" @click="addToRoster">
            Hinzufügen
          </button>
        </div>
        <p v-if="!availablePool.length" class="vol-muted">
          Alle Pool-Personen sind bereits angemeldet — oder der Pool ist leer (unter Personen anlegen).
        </p>
      </div>

      <p v-if="loading" class="vol-muted">Laden…</p>
      <p v-else-if="!roster.length" class="vol-muted">Noch niemand angemeldet.</p>

      <ul v-else class="vol-list">
        <li v-for="entry in roster" :key="entry.id" class="vol-row liquid-surface-inner">
          <div>
            <strong>{{ displayName(entry.person) }}</strong>
            <div class="vol-muted">{{ entry.person.email }}</div>
            <div v-if="entry.person.mobile" class="vol-muted">{{ entry.person.mobile }}</div>
          </div>
          <div class="vol-row__meta">
            <span v-if="entry.has_assignment" class="glass-chip">Besetzt</span>
              <button
                  type="button"
                  class="glass-btn-secondary"
                  @click="removeFromRoster(entry)"
              >
              Entfernen
            </button>
          </div>
        </li>
      </ul>
    </section>
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
.vol-page__main { padding: 1rem; }
.vol-composer { padding: 0; margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
.vol-composer__row { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.vol-composer__row .glass-input, .vol-composer__row select { flex: 1; min-width: 10rem; }
.vol-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.vol-row {
  padding: 0.75rem;
  border-radius: 0.75rem;
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
}
.vol-row__meta { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.vol-muted { opacity: 0.7; font-size: 0.9rem; margin: 0; }
</style>
