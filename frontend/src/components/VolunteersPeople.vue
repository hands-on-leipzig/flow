<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'

type RecentAssignment = { event_id: number; role: string; year: string | null }

type Person = {
  id: number
  first_name: string
  last_name: string
  nickname: string | null
  email: string
  mobile: string | null
  updated_at: string | null
  on_roster?: boolean
  recent_assignments?: RecentAssignment[]
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const people = ref<Person[]>([])
const search = ref('')
const loading = ref(false)
const error = ref('')
const toast = ref('')

const draft = ref({
  first_name: '',
  last_name: '',
  nickname: '',
  email: '',
  mobile: '',
})

const filtered = computed(() => people.value)

function displayName(p: Person) {
  if (p.nickname?.trim()) {
    return `${p.first_name} „${p.nickname}“ ${p.last_name}`
  }
  return `${p.first_name} ${p.last_name}`
}

function historyLabel(p: Person) {
  const items = p.recent_assignments ?? []
  if (!items.length) return ''
  return items.map((a) => `${a.role} · ${a.year ?? ''}`).join(', ')
}

async function load() {
  if (!eventId.value) return
  loading.value = true
  error.value = ''
  try {
    const {data} = await axios.get(`/events/${eventId.value}/volunteers`, {
      params: {q: search.value || undefined},
    })
    people.value = data.people ?? []
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Laden fehlgeschlagen'
  } finally {
    loading.value = false
  }
}

async function createPerson() {
  if (!eventId.value) return
  if (!draft.value.first_name.trim() || !draft.value.last_name.trim() || !draft.value.email.trim()) {
    error.value = 'Vorname, Nachname und E-Mail sind erforderlich.'
    return
  }
  error.value = ''
  try {
    await axios.post(`/events/${eventId.value}/volunteers`, {
      first_name: draft.value.first_name.trim(),
      last_name: draft.value.last_name.trim(),
      nickname: draft.value.nickname.trim() || null,
      email: draft.value.email.trim(),
      mobile: draft.value.mobile.trim() || null,
    })
    draft.value = {first_name: '', last_name: '', nickname: '', email: '', mobile: ''}
    await load()
    showToast('Person angelegt')
  } catch (e: any) {
    const msg = e?.response?.data?.message
      || e?.response?.data?.error
      || (e?.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : null)
      || 'Anlegen fehlgeschlagen'
    error.value = String(msg)
  }
}

async function savePerson(p: Person) {
  error.value = ''
  try {
    await axios.put(`/volunteers/${p.id}`, {
      first_name: p.first_name.trim(),
      last_name: p.last_name.trim(),
      nickname: p.nickname?.trim() || null,
      email: p.email.trim(),
      mobile: p.mobile?.trim() || null,
    })
    await load()
    showToast('Gespeichert')
  } catch (e: any) {
    error.value = e?.response?.data?.error || e?.response?.data?.message || 'Speichern fehlgeschlagen'
  }
}

async function removePerson(p: Person) {
  if (!confirm(`${displayName(p)} wirklich löschen?`)) return
  error.value = ''
  try {
    await axios.delete(`/volunteers/${p.id}`)
    await load()
    showToast('Gelöscht')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Löschen fehlgeschlagen'
  }
}

async function copyEmails() {
  const emails = filtered.value.map((p) => p.email).filter(Boolean)
  if (!emails.length) {
    showToast('Keine E-Mails')
    return
  }
  const text = emails.join('; ')
  try {
    await navigator.clipboard.writeText(text)
    showToast(`${emails.length} E-Mails kopiert`)
  } catch {
    error.value = 'Zwischenablage nicht verfügbar'
  }
}

function openMailto() {
  const emails = filtered.value.map((p) => p.email).filter(Boolean)
  if (!emails.length) return
  // BCC via mailto is limited; still offer for small lists
  window.location.href = `mailto:?bcc=${encodeURIComponent(emails.join(','))}`
}

async function downloadCsv() {
  if (!eventId.value) return
  try {
    const {data} = await axios.get(`/events/${eventId.value}/volunteers/export`, {
      params: {scope: 'pool'},
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(data)
    const link = document.createElement('a')
    link.href = url
    link.download = `helfer-pool-${eventId.value}.csv`
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

let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => load(), 250)
})

watch(eventId, () => load(), {immediate: true})
onMounted(() => load())
</script>

<template>
  <div class="vol-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Personen</h1>
        <p class="vol-page__sub">Adressbuch des Regionalpartners — möglichst wenig Daten.</p>
      </div>
    </header>

    <div v-if="error" class="glass-alert-danger vol-page__alert">{{ error }}</div>
    <div v-if="toast" class="glass-alert-success vol-page__alert">{{ toast }}</div>

    <div class="vol-page__grid">
      <section class="glass-card vol-page__main">
        <div class="vol-toolbar">
          <input
              v-model="search"
              type="search"
              class="glass-input"
              placeholder="Suche Name oder E-Mail…"
          />
          <span class="vol-toolbar__count">{{ filtered.length }}</span>
        </div>

        <div class="vol-composer glass-card liquid-surface-inner">
          <div class="vol-composer__row">
            <input v-model="draft.first_name" class="glass-input" placeholder="Vorname *" />
            <input v-model="draft.last_name" class="glass-input" placeholder="Nachname *" />
            <input v-model="draft.nickname" class="glass-input" placeholder="Spitzname" />
          </div>
          <div class="vol-composer__row">
            <input v-model="draft.email" class="glass-input" type="email" placeholder="E-Mail *" />
            <input v-model="draft.mobile" class="glass-input" placeholder="Mobil" />
            <button type="button" class="glass-btn-accent" @click="createPerson">Anlegen</button>
          </div>
        </div>

        <p v-if="loading" class="vol-muted">Laden…</p>
        <p v-else-if="!filtered.length" class="vol-muted">Noch keine Personen im Pool.</p>

        <ul v-else class="vol-list">
          <li v-for="p in filtered" :key="p.id" class="vol-row liquid-surface-inner">
            <div class="vol-row__fields">
              <input v-model="p.first_name" class="glass-input glass-input--sm" @blur="savePerson(p)" />
              <input v-model="p.last_name" class="glass-input glass-input--sm" @blur="savePerson(p)" />
              <input v-model="p.nickname" class="glass-input glass-input--sm" placeholder="Spitzname" @blur="savePerson(p)" />
              <input v-model="p.email" class="glass-input glass-input--sm" type="email" @blur="savePerson(p)" />
              <input v-model="p.mobile" class="glass-input glass-input--sm" placeholder="Mobil" @blur="savePerson(p)" />
            </div>
            <div class="vol-row__meta">
              <span v-if="p.on_roster" class="glass-chip">Auf Anmeldung</span>
              <span v-if="historyLabel(p)" class="vol-muted">{{ historyLabel(p) }}</span>
              <span v-if="p.updated_at" class="vol-muted">Bearbeitet {{ p.updated_at.slice(0, 10) }}</span>
              <button type="button" class="glass-btn-secondary" @click="removePerson(p)">Löschen</button>
            </div>
          </li>
        </ul>
      </section>

      <aside class="glass-card vol-page__aside">
        <h2 class="vol-aside__title">Werkzeuge</h2>
        <p class="vol-muted">FLOW versendet keine Inhaltsmails. Listen zum Einfügen in BCC kopieren.</p>
        <button type="button" class="glass-btn-accent vol-aside__btn" @click="copyEmails">E-Mails kopieren</button>
        <button type="button" class="glass-btn-secondary vol-aside__btn" @click="openMailto">Mailprogramm öffnen</button>
        <button type="button" class="glass-btn-secondary vol-aside__btn" @click="downloadCsv">Excel / CSV</button>
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
.vol-toolbar { display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.75rem; }
.vol-toolbar input { flex: 1; }
.vol-toolbar__count { opacity: 0.6; font-variant-numeric: tabular-nums; }
.vol-composer { padding: 0.75rem; margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
.vol-composer__row { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.vol-composer__row .glass-input { flex: 1; min-width: 8rem; }
.vol-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.vol-row { padding: 0.75rem; border-radius: 0.75rem; display: flex; flex-direction: column; gap: 0.5rem; }
.vol-row__fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr)); gap: 0.4rem; }
.vol-row__meta { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.vol-muted { opacity: 0.7; font-size: 0.9rem; margin: 0; }
.vol-aside__title { margin: 0 0 0.5rem; font-size: 1.05rem; }
.vol-aside__btn { width: 100%; margin-top: 0.5rem; }
</style>
