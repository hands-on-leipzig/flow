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
type SortKey = 'first_name' | 'last_name'
const sortKey = ref<SortKey>('last_name')
const sortDir = ref<'asc' | 'desc'>('asc')
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

function searchHaystack(p: Person) {
  return [
    p.first_name,
    p.last_name,
    p.nickname,
    p.email,
    p.mobile,
    historyLabel(p),
    p.updated_at?.slice(0, 10),
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
}

function toggleSort(key: SortKey) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = 'asc'
  }
}

function sortIcon(key: SortKey) {
  if (sortKey.value !== key) return 'bi-arrow-down-up'
  return sortDir.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down'
}

const filtered = computed(() => {
  let list = people.value
  const q = search.value.trim().toLowerCase()
  if (q) {
    list = list.filter((p) => searchHaystack(p).includes(q))
  }
  const key = sortKey.value
  const dir = sortDir.value === 'asc' ? 1 : -1
  return [...list].sort((a, b) => {
    const av = (a[key] ?? '').toLocaleLowerCase('de')
    const bv = (b[key] ?? '').toLocaleLowerCase('de')
    if (av < bv) return -1 * dir
    if (av > bv) return 1 * dir
    const aLast = (a.last_name ?? '').toLocaleLowerCase('de')
    const bLast = (b.last_name ?? '').toLocaleLowerCase('de')
    if (aLast !== bLast) return aLast < bLast ? -1 : 1
    const aFirst = (a.first_name ?? '').toLocaleLowerCase('de')
    const bFirst = (b.first_name ?? '').toLocaleLowerCase('de')
    if (aFirst !== bFirst) return aFirst < bFirst ? -1 : 1
    return a.id - b.id
  })
})

async function load() {
  if (!eventId.value) return
  loading.value = true
  error.value = ''
  try {
    const {data} = await axios.get(`/events/${eventId.value}/volunteers`)
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
        <h1 class="vol-page__title">Personen</h1>
        <p class="vol-page__sub">Saison-übergreifende Kontaktliste</p>
      </div>
    </header>

    <div v-if="error" class="glass-alert-warning vol-page__alert">{{ error }}</div>
    <div v-if="toast" class="vol-page__toast">{{ toast }}</div>

    <section class="glass-card liquid-surface-inner vol-tile">
      <div class="vol-table-frame">
        <table class="vol-table">
          <colgroup>
            <col class="vol-col--first"/>
            <col class="vol-col--last"/>
            <col class="vol-col--nick"/>
            <col class="vol-col--email"/>
            <col class="vol-col--mobile"/>
            <col class="vol-col--meta"/>
            <col class="vol-col--actions"/>
          </colgroup>
          <tbody>
            <tr>
              <td>
                <input
                    v-model="draft.first_name"
                    class="glass-input glass-input--sm"
                    placeholder="Vorname *"
                />
              </td>
              <td>
                <input
                    v-model="draft.last_name"
                    class="glass-input glass-input--sm"
                    placeholder="Nachname *"
                />
              </td>
              <td>
                <input
                    v-model="draft.nickname"
                    class="glass-input glass-input--sm"
                    placeholder="Spitzname"
                />
              </td>
              <td>
                <input
                    v-model="draft.email"
                    class="glass-input glass-input--sm"
                    type="email"
                    placeholder="E-Mail *"
                />
              </td>
              <td>
                <input
                    v-model="draft.mobile"
                    class="glass-input glass-input--sm"
                    placeholder="Mobil"
                />
              </td>
              <td class="vol-table__meta"/>
              <td class="vol-table__actions">
                <button type="button" class="glass-btn-accent" @click="createPerson">Anlegen</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="glass-card liquid-surface-inner vol-tile">
      <div class="vol-toolbar">
        <input
            v-model="search"
            type="search"
            class="glass-input glass-input--sm"
            placeholder="Tippen zum Filtern (alle Felder)…"
            autocomplete="off"
        />
        <span class="vol-toolbar__count">{{ filtered.length }} / {{ people.length }}</span>
      </div>

      <p v-if="loading" class="vol-muted">Laden…</p>
      <p v-else-if="!people.length" class="vol-muted">Noch keine Personen im Pool.</p>
      <p v-else-if="!filtered.length" class="vol-muted">Keine Treffer für diesen Filter.</p>

      <div v-else class="vol-table-frame vol-table-frame--scroll">
        <table class="vol-table">
          <colgroup>
            <col class="vol-col--first"/>
            <col class="vol-col--last"/>
            <col class="vol-col--nick"/>
            <col class="vol-col--email"/>
            <col class="vol-col--mobile"/>
            <col class="vol-col--meta"/>
            <col class="vol-col--actions"/>
          </colgroup>
          <thead>
            <tr>
              <th scope="col">
                <button
                    type="button"
                    class="vol-sort"
                    :class="{'vol-sort--active': sortKey === 'first_name'}"
                    @click="toggleSort('first_name')"
                >
                  Vorname
                  <i class="bi" :class="sortIcon('first_name')" aria-hidden="true"/>
                </button>
              </th>
              <th scope="col">
                <button
                    type="button"
                    class="vol-sort"
                    :class="{'vol-sort--active': sortKey === 'last_name'}"
                    @click="toggleSort('last_name')"
                >
                  Nachname
                  <i class="bi" :class="sortIcon('last_name')" aria-hidden="true"/>
                </button>
              </th>
              <th scope="col">Spitzname</th>
              <th scope="col">E-Mail</th>
              <th scope="col">Mobil</th>
              <th scope="col">Einsätze</th>
              <th scope="col" class="vol-table__actions"><span class="sr-only">Aktionen</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in filtered" :key="p.id" class="glass-table-row--hover">
              <td>
                <input
                    v-model="p.first_name"
                    class="glass-input glass-input--sm"
                    @blur="savePerson(p)"
                />
              </td>
              <td>
                <input
                    v-model="p.last_name"
                    class="glass-input glass-input--sm"
                    @blur="savePerson(p)"
                />
              </td>
              <td>
                <input
                    v-model="p.nickname"
                    class="glass-input glass-input--sm"
                    placeholder="—"
                    @blur="savePerson(p)"
                />
              </td>
              <td>
                <input
                    v-model="p.email"
                    class="glass-input glass-input--sm"
                    type="email"
                    @blur="savePerson(p)"
                />
              </td>
              <td>
                <input
                    v-model="p.mobile"
                    class="glass-input glass-input--sm"
                    placeholder="—"
                    @blur="savePerson(p)"
                />
              </td>
              <td class="vol-table__meta">
                <span v-if="historyLabel(p)" class="vol-muted">{{ historyLabel(p) }}</span>
              </td>
              <td class="vol-table__actions">
                <button type="button" class="glass-btn-secondary" @click="removePerson(p)">
                  Löschen
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
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
.vol-page__header { display: flex; justify-content: space-between; gap: 1rem; }
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
.vol-tile {
  padding: 1rem;
}
.vol-toolbar {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  margin-bottom: 0.75rem;
}
.vol-toolbar input { flex: 1; }
.vol-toolbar__count {
  opacity: 0.6;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
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
.vol-col--first { width: 13%; }
.vol-col--last { width: 13%; }
.vol-col--nick { width: 12%; }
.vol-col--email { width: 20%; }
.vol-col--mobile { width: 13%; }
.vol-col--meta { width: 18%; }
.vol-col--actions { width: 6.5rem; }

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
.vol-table__actions {
  white-space: nowrap;
}
.vol-table__meta {
  font-size: 0.8rem;
  overflow: hidden;
  text-overflow: ellipsis;
}
.vol-table .glass-input {
  width: 100%;
  min-width: 0;
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
