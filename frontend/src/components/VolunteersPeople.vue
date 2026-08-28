<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import VolunteerEmailOutreach from '@/components/molecules/VolunteerEmailOutreach.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import VolunteerPeopleImport from '@/components/molecules/VolunteerPeopleImport.vue'
import {validateAndNormalizeMobile} from '@/utils/mobileNumber'
import {formatDateTime} from '@/utils/dateTimeFormat'
import {PERSON_TABLE_COLUMNS} from '@/volunteers/columns/personColumns'
import type {VolunteerTableColumn} from '@/volunteers/columns/types'

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

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const people = ref<Person[]>([])
const tableColumns = ref<VolunteerTableColumn[]>([...PERSON_TABLE_COLUMNS])
const assignedIds = ref<Set<number>>(new Set())
const search = ref('')
type SortKey = 'first_name' | 'last_name'
const sortKey = ref<SortKey>('last_name')
const sortDir = ref<'asc' | 'desc'>('asc')
const loading = ref(false)
const togglingId = ref<number | null>(null)
const removeFromRosterTarget = ref<Person | null>(null)
const error = ref('')
const toast = ref('')
const importOpen = ref(false)

const draft = ref({
  first_name: '',
  last_name: '',
  nickname: '',
  email: '',
  mobile: '',
})
const editingId = ref<number | null>(null)
const draftMobileError = ref('')

const isEditing = computed(() => editingId.value !== null)

function formatUpdatedAt(value: string | null | undefined) {
  if (!value) return '—'
  return formatDateTime(value, true)
}

function columnColClass(key: string) {
  const classes: Record<string, string> = {
    first_name: 'vol-col--first',
    last_name: 'vol-col--last',
    nickname: 'vol-col--nick',
    email: 'vol-col--email',
    mobile: 'vol-col--mobile',
    updated_at: 'vol-col--updated',
  }
  return classes[key] ?? ''
}

function isSortableColumn(key: string): key is SortKey {
  return key === 'first_name' || key === 'last_name'
}

function displayName(p: Person) {
  if (p.nickname?.trim()) {
    return `${p.first_name} „${p.nickname}“ ${p.last_name}`
  }
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

const removeFromRosterMessage = computed(() => {
  const p = removeFromRosterTarget.value
  if (!p) return ''
  const base = `${displayName(p)} wird von der Helferliste dieser Veranstaltung entfernt.`
  if (assignedIds.value.has(p.id)) {
    return `${base} Bestehende Zuordnungen werden ebenfalls entfernt.`
  }
  return base
})

async function load() {
  if (!eventId.value) return
  loading.value = true
  error.value = ''
  try {
    const [peopleRes, rosterRes] = await Promise.all([
      axios.get(`/events/${eventId.value}/volunteers`),
      axios.get(`/events/${eventId.value}/volunteer-roster`),
    ])
    people.value = peopleRes.data.people ?? []
    tableColumns.value = peopleRes.data.columns ?? [...PERSON_TABLE_COLUMNS]
    assignedIds.value = new Set(
      (rosterRes.data.roster ?? [])
        .filter((row: {has_assignment?: boolean}) => row.has_assignment)
        .map((row: {person: {id: number}}) => row.person.id),
    )
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Laden fehlgeschlagen'
  } finally {
    loading.value = false
  }
}

function mobileInputClass(error?: string) {
  return error ? 'glass-input glass-input--sm vol-input--invalid' : 'glass-input glass-input--sm'
}

function clearDraftMobileError() {
  draftMobileError.value = ''
}

function resetDraft() {
  draft.value = {first_name: '', last_name: '', nickname: '', email: '', mobile: ''}
  draftMobileError.value = ''
  editingId.value = null
}

function resolveMobile(raw: string | null | undefined) {
  const result = validateAndNormalizeMobile(raw)
  if (!result.ok) return {ok: false as const, error: result.error}
  return {ok: true as const, normalized: result.normalized}
}

function onDraftMobileBlur() {
  const result = resolveMobile(draft.value.mobile)
  if (!result.ok) {
    draftMobileError.value = result.error
    return
  }
  draftMobileError.value = ''
  if (result.normalized !== null) {
    draft.value.mobile = result.normalized
  }
}

function startEdit(p: Person) {
  editingId.value = p.id
  draft.value = {
    first_name: p.first_name,
    last_name: p.last_name,
    nickname: p.nickname ?? '',
    email: p.email,
    mobile: p.mobile ?? '',
  }
  draftMobileError.value = ''
  error.value = ''
  document.querySelector('.vol-composer')?.scrollIntoView({behavior: 'smooth', block: 'nearest'})
}

async function submitPerson() {
  if (!eventId.value) return
  if (!draft.value.first_name.trim() || !draft.value.last_name.trim() || !draft.value.email.trim()) {
    error.value = 'Vorname, Nachname und E-Mail sind erforderlich.'
    return
  }
  const mobileResult = resolveMobile(draft.value.mobile)
  if (!mobileResult.ok) {
    draftMobileError.value = mobileResult.error
    return
  }
  error.value = ''
  draftMobileError.value = ''
  const payload = {
    first_name: draft.value.first_name.trim(),
    last_name: draft.value.last_name.trim(),
    nickname: draft.value.nickname.trim() || null,
    email: draft.value.email.trim(),
    mobile: mobileResult.normalized,
  }
  try {
    if (editingId.value) {
      await axios.put(`/volunteers/${editingId.value}`, payload)
      resetDraft()
      await load()
      showToast('Gespeichert')
      return
    }
    await axios.post(`/events/${eventId.value}/volunteers`, payload)
    resetDraft()
    await load()
    showToast('Person angelegt')
  } catch (e: any) {
    const msg = e?.response?.data?.message
      || e?.response?.data?.error
      || (e?.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : null)
      || (editingId.value ? 'Speichern fehlgeschlagen' : 'Anlegen fehlgeschlagen')
    error.value = String(msg)
  }
}

function deletePersonLabel(p: Person) {
  if (p.on_roster) return 'Löschen nicht möglich — Person ist auf der Helferliste'
  return 'Person löschen'
}

async function removePerson(p: Person) {
  if (p.on_roster) return
  if (!confirm(`${displayName(p)} wirklich löschen?`)) return
  error.value = ''
  try {
    await axios.delete(`/volunteers/${p.id}`)
    if (editingId.value === p.id) resetDraft()
    await load()
    showToast('Gelöscht')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Löschen fehlgeschlagen'
  }
}

function rosterIconTooltip(p: Person) {
  if (!p.on_roster) return 'Zur Helferliste hinzufügen'
  if (assignedIds.value.has(p.id)) {
    return 'Von Helferliste entfernen — Zuordnungen werden ebenfalls entfernt'
  }
  return 'Von Helferliste entfernen'
}

function onRosterIconClick(p: Person) {
  if (togglingId.value === p.id) return
  if (p.on_roster) {
    removeFromRosterTarget.value = p
    return
  }
  void addToRoster(p)
}

async function addToRoster(p: Person) {
  if (!eventId.value || togglingId.value === p.id) return
  togglingId.value = p.id
  error.value = ''
  try {
    await axios.post(`/events/${eventId.value}/volunteer-roster`, {
      volunteer_person: p.id,
    })
    p.on_roster = true
    showToast('Zur Helferliste hinzugefügt')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Hinzufügen fehlgeschlagen'
  } finally {
    togglingId.value = null
  }
}

async function confirmRemoveFromRoster() {
  const p = removeFromRosterTarget.value
  if (!eventId.value || !p || togglingId.value === p.id) return
  togglingId.value = p.id
  error.value = ''
  try {
    await axios.delete(`/events/${eventId.value}/volunteer-roster/${p.id}`)
    p.on_roster = false
    assignedIds.value.delete(p.id)
    removeFromRosterTarget.value = null
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

function onPeopleImported() {
  importOpen.value = false
  void load()
  showToast('Import abgeschlossen')
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
      <div class="vol-page__actions">
        <button
            type="button"
            class="glass-btn-secondary vol-upload-trigger"
            :class="{'vol-upload-trigger--active': importOpen}"
            @click="importOpen = !importOpen"
        >
          <i class="bi bi-upload" aria-hidden="true"/>
          Upload
        </button>
        <VolunteerEmailOutreach scope="pool" :people="filtered"/>
      </div>
    </header>

    <div v-if="error" class="glass-alert-warning vol-page__alert">{{ error }}</div>
    <div v-if="toast" class="vol-page__toast">{{ toast }}</div>

    <section class="glass-card liquid-surface-inner vol-tile vol-composer" :class="{'vol-composer--edit': isEditing}">
      <div class="vol-table-frame">
        <table class="vol-table">
          <colgroup>
            <col class="vol-col--roster"/>
            <col
                v-for="column in tableColumns"
                :key="`composer-col-${column.key}`"
                :class="columnColClass(column.key)"
            />
            <col class="vol-col--actions"/>
          </colgroup>
          <tbody>
            <tr>
              <td class="vol-table__roster"/>
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
                    :class="mobileInputClass(draftMobileError)"
                    type="tel"
                    inputmode="tel"
                    autocomplete="tel"
                    placeholder="0170 1234567 oder +49 170 1234567"
                    :aria-invalid="draftMobileError ? true : undefined"
                    :title="draftMobileError || undefined"
                    @input="clearDraftMobileError"
                    @blur="onDraftMobileBlur"
                />
              </td>
              <td class="vol-table__updated"/>
              <td class="vol-table__actions">
                <button
                    v-if="isEditing"
                    type="button"
                    class="glass-btn-secondary vol-composer__cancel"
                    @click="resetDraft"
                >
                  Abbrechen
                </button>
                <button
                    type="button"
                    :class="isEditing ? 'glass-btn-secondary vol-composer__save' : 'glass-btn-accent'"
                    @click="submitPerson"
                >
                  {{ isEditing ? 'Sichern' : 'Anlegen' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <VolunteerPeopleImport
        v-if="importOpen"
        :event-id="eventId"
        @imported="onPeopleImported"
        @cancel="importOpen = false"
    />

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
            <col class="vol-col--roster"/>
            <col
                v-for="column in tableColumns"
                :key="`composer-col-${column.key}`"
                :class="columnColClass(column.key)"
            />
            <col class="vol-col--actions"/>
          </colgroup>
          <thead>
            <tr>
              <th class="vol-table__roster" scope="col"><span class="sr-only">Helferliste</span></th>
              <th
                  v-for="column in tableColumns"
                  :key="column.key"
                  scope="col"
              >
                <button
                    v-if="column.sortable && isSortableColumn(column.key)"
                    type="button"
                    class="vol-sort"
                    :class="{'vol-sort--active': sortKey === column.key}"
                    @click="toggleSort(column.key)"
                >
                  {{ column.label }}
                  <i class="bi" :class="sortIcon(column.key)" aria-hidden="true"/>
                </button>
                <span v-else>{{ column.label }}</span>
              </th>
              <th scope="col" class="vol-table__actions"><span class="sr-only">Aktionen</span></th>
            </tr>
          </thead>
          <tbody>
            <tr
                v-for="p in filtered"
                :key="p.id"
                class="glass-table-row--hover"
                :class="{'vol-table__row--editing': editingId === p.id}"
            >
              <td class="vol-table__roster">
                <button
                    type="button"
                    class="vol-roster-icon"
                    :class="p.on_roster ? 'vol-roster-icon--on' : 'vol-roster-icon--off'"
                    :disabled="togglingId === p.id"
                    :aria-label="rosterIconTooltip(p)"
                    @click="onRosterIconClick(p)"
                >
                  <i
                      class="bi vol-roster-icon__glyph"
                      :class="p.on_roster ? 'bi-clipboard-check-fill' : 'bi-clipboard-check'"
                      aria-hidden="true"
                  />
                  <span class="vol-roster-icon__tip glass-dropdown" role="tooltip">{{ rosterIconTooltip(p) }}</span>
                </button>
              </td>
              <td>{{ p.first_name }}</td>
              <td>{{ p.last_name }}</td>
              <td>{{ p.nickname?.trim() || '—' }}</td>
              <td>{{ p.email }}</td>
              <td>{{ p.mobile?.trim() || '—' }}</td>
              <td class="vol-table__updated">{{ formatUpdatedAt(p.updated_at) }}</td>
              <td class="vol-table__actions">
                <button
                    type="button"
                    class="vol-icon-btn"
                    aria-label="Bearbeiten"
                    title="Bearbeiten"
                    @click="startEdit(p)"
                >
                  <i class="bi bi-pencil" aria-hidden="true"/>
                </button>
                <IconDangerButton
                    :label="deletePersonLabel(p)"
                    :disabled="p.on_roster"
                    @click="removePerson(p)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <ConfirmationModal
        :show="!!removeFromRosterTarget"
        type="warning"
        title="Von Helferliste entfernen?"
        :message="removeFromRosterMessage"
        confirm-text="Entfernen"
        cancel-text="Abbrechen"
        @confirm="confirmRemoveFromRoster"
        @cancel="removeFromRosterTarget = null"
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
.vol-page__actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-shrink: 0;
}
.vol-upload-trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  flex-shrink: 0;
}
.vol-upload-trigger--active {
  box-shadow:
    inset 0 0 0 1px color-mix(in srgb, var(--color-accent) 45%, transparent),
    0 8px 18px rgba(15, 23, 42, 0.08);
}
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

.vol-input--invalid {
  border-color: color-mix(in srgb, var(--color-danger, #dc2626) 72%, var(--color-border-strong));
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-danger, #dc2626) 35%, transparent);
}

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
.vol-col--first { width: 13%; }
.vol-col--last { width: 13%; }
.vol-col--nick { width: 11%; }
.vol-col--email { width: 20%; }
.vol-col--mobile { width: 13%; }
.vol-col--updated { width: 11%; }
.vol-col--actions { width: 10rem; }

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
.vol-table__updated {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  font-size: 0.8125rem;
  color: var(--color-text-muted);
}
.vol-table__roster {
  text-align: center;
}
.vol-table th.vol-table__roster,
.vol-table td.vol-table__roster {
  text-align: center;
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
.vol-roster-icon--off {
  color: var(--color-text-muted);
}
.vol-roster-icon--off .vol-roster-icon__glyph {
  opacity: 0.45;
}
.vol-roster-icon--off:hover:not(:disabled) {
  color: var(--color-text);
  background: var(--color-bg-hover);
}
.vol-roster-icon--off:hover:not(:disabled) .vol-roster-icon__glyph {
  opacity: 0.85;
}
.vol-roster-icon:disabled {
  cursor: not-allowed;
}
.vol-roster-icon:disabled .vol-roster-icon__glyph {
  opacity: 0.35;
}
.vol-table__actions {
  display: flex;
  gap: 0.2rem;
  align-items: center;
  justify-content: flex-end;
  white-space: nowrap;
}
.vol-composer--edit {
  box-shadow:
    inset 0 0 0 1px color-mix(in srgb, var(--color-accent) 35%, transparent),
    0 10px 24px rgba(15, 23, 42, 0.06);
}
.vol-composer__save {
  min-width: 5.25rem;
}
.vol-composer__cancel {
  padding-inline: 0.65rem;
  font-size: 0.8125rem;
}
.vol-table__row--editing td {
  background: color-mix(in srgb, var(--color-accent) 7%, transparent);
}
.vol-icon-btn {
  width: 2.25rem;
  height: 2.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
  transition: color 0.15s ease, background 0.15s ease;
}
.vol-icon-btn .bi {
  font-size: 1.05rem;
  line-height: 1;
}
.vol-icon-btn:hover:not(:disabled) {
  color: var(--color-accent);
  background: var(--color-accent-muted);
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
