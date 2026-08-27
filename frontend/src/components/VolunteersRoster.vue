<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'

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
    showToast('Zur Anmeldung hinzugefügt')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Hinzufügen fehlgeschlagen'
  }
}

async function removeFromRoster(entry: RosterEntry) {
  if (!eventId.value) return
  if (entry.has_assignment) {
    error.value = 'Person ist noch besetzt — zuerst Einsatz entfernen.'
    return
  }
  error.value = ''
  try {
    await axios.delete(`/events/${eventId.value}/volunteer-roster/${entry.person.id}`)
    await load()
    showToast('Von Anmeldung entfernt')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Entfernen fehlgeschlagen'
  }
}

async function copyEmails() {
  const emails = roster.value.map((r) => r.person.email).filter(Boolean)
  if (!emails.length) {
    showToast('Keine E-Mails')
    return
  }
  try {
    await navigator.clipboard.writeText(emails.join('; '))
    showToast(`${emails.length} E-Mails kopiert`)
  } catch {
    error.value = 'Zwischenablage nicht verfügbar'
  }
}

function openMailto() {
  const emails = roster.value.map((r) => r.person.email).filter(Boolean)
  if (!emails.length) return
  window.location.href = `mailto:?bcc=${encodeURIComponent(emails.join(','))}`
}

async function downloadCsv() {
  if (!eventId.value) return
  try {
    const {data} = await axios.get(`/events/${eventId.value}/volunteers/export`, {
      params: {scope: 'roster'},
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = `helfer-anmeldung-${eventId.value}.csv`
    link.click()
    window.URL.revokeObjectURL(url)
  } catch {
    error.value = 'Export fehlgeschlagen'
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
        <h1 class="vol-page__title">Anmeldung</h1>
        <p class="vol-page__sub">Wer ist für diese Veranstaltung dabei? (Formular folgt später.)</p>
      </div>
    </header>

    <div v-if="error" class="glass-alert-danger vol-page__alert">{{ error }}</div>
    <div v-if="toast" class="glass-alert-success vol-page__alert">{{ toast }}</div>

    <div class="vol-page__grid">
      <section class="glass-card vol-page__main">
        <div class="vol-composer glass-card liquid-surface-inner">
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
                  :disabled="entry.has_assignment"
                  @click="removeFromRoster(entry)"
              >
                Entfernen
              </button>
            </div>
          </li>
        </ul>
      </section>

      <aside class="glass-card vol-page__aside">
        <h2 class="vol-aside__title">Outreach</h2>
        <p class="vol-muted">E-Mail-Liste der Anmeldung für BCC / Excel.</p>
        <button type="button" class="glass-btn-accent vol-aside__btn" @click="copyEmails">E-Mails kopieren</button>
        <button type="button" class="glass-btn-secondary vol-aside__btn" @click="openMailto">Mailprogramm öffnen</button>
        <button type="button" class="glass-btn-secondary vol-aside__btn" @click="downloadCsv">Excel / CSV</button>
        <p class="vol-muted" style="margin-top: 1rem">{{ roster.length }} angemeldet</p>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.vol-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 0.5rem 0 2rem;
}
.vol-page__header { display: flex; justify-content: space-between; gap: 1rem; }
.vol-page__title { font-size: 1.5rem; font-weight: 650; margin: 0; }
.vol-page__sub { margin: 0.25rem 0 0; opacity: 0.75; }
.vol-page__alert { padding: 0.75rem 1rem; border-radius: 0.75rem; }
.vol-page__grid {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(14rem, 1fr);
  gap: 1rem;
  align-items: start;
}
@media (max-width: 900px) {
  .vol-page__grid { grid-template-columns: 1fr; }
}
.vol-page__main, .vol-page__aside { padding: 1rem; }
.vol-composer { padding: 0.75rem; margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
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
.vol-aside__title { margin: 0 0 0.5rem; font-size: 1.05rem; }
.vol-aside__btn { width: 100%; margin-top: 0.5rem; }
</style>
