<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'
import {
  parseVolunteerPeopleImportText,
  type VolunteerImportRow,
} from '@/utils/volunteerPeopleImportParse'

type ImportResponse = {
  created: number
  skipped: number
  errors: Array<{row: number; email: string | null; message: string}>
}

const props = defineProps<{
  eventId: number | undefined
}>()

const emit = defineEmits<{
  imported: []
  cancel: []
}>()

const inputText = ref('')
const previewRows = ref<VolunteerImportRow[]>([])
const busy = ref(false)

const canAddToContactList = computed(() => previewRows.value.length > 0 && !busy.value)

function updatePreview() {
  previewRows.value = parseVolunteerPeopleImportText(inputText.value)
}

function resetInput() {
  inputText.value = ''
  previewRows.value = []
}

function onCancel() {
  resetInput()
  emit('cancel')
}

async function addToContactList() {
  if (!props.eventId || !previewRows.value.length || busy.value) return
  if (previewRows.value.length > 500) {
    showGlassToast('Maximal 500 Zeilen pro Import', 'info')
    return
  }
  busy.value = true
  try {
    const {data} = await axios.post<ImportResponse>(
      `/events/${props.eventId}/volunteers/import`,
      {
        dry_run: false,
        rows: previewRows.value,
      },
    )
    const created = data.created ?? 0
    const skipped = data.skipped ?? 0
    const errorCount = data.errors?.length ?? 0

    if (created > 0) {
      showGlassToast(`${created} Personen zur Kontaktliste hinzugefügt`, 'success')
      resetInput()
      emit('imported')
      return
    }
    if (skipped > 0 && errorCount === 0) {
      showGlassToast(`${skipped} übersprungen (E-Mail bereits vorhanden)`, 'info')
      resetInput()
      emit('imported')
      return
    }
    if (errorCount > 0) {
      const first = data.errors[0]?.message || 'Ungültige Zeilen'
      showGlassToast(`${errorCount} fehlerhaft: ${first}`, 'error')
      return
    }
    showGlassToast('Keine gültigen Zeilen', 'info')
  } catch (e: any) {
    const msg = e?.response?.data?.errors?.[0]?.message
      || e?.response?.data?.error
      || e?.response?.data?.message
      || 'Import fehlgeschlagen'
    showGlassToast(String(msg), 'error')
  } finally {
    busy.value = false
  }
}

watch(() => props.eventId, () => resetInput())
</script>

<template>
  <section class="glass-card liquid-surface-inner vol-tile vol-import">
    <h2 class="vol-import__title">Mehrere Personen einfügen</h2>
    <p class="vol-import__hint">
      Format: Vorname, Nachname, Spitzname, E-Mail, Mobil.
      Spalten durch Tab, Komma oder Semikolon. Spitzname und Mobil optional; E-Mail Pflicht.
    </p>
    <p class="vol-import__hint">
      Die E-Mail ist der Schlüssel — doppelte Adressen werden übersprungen.
    </p>

    <textarea
        v-model="inputText"
        class="vol-import__textarea"
        rows="6"
        placeholder="Max&#9;Mustermann&#9;&#9;max@example.de&#9;0170 1234567&#10;Anna&#9;Schmidt&#9;&#9;anna@example.de&#9;"
        @input="updatePreview"
    />

    <div v-if="previewRows.length" class="vol-import__section">
      <div class="vol-import__section-title">Vorschau ({{ previewRows.length }})</div>
      <div class="vol-import__table-wrap">
        <table class="vol-import__table">
          <thead>
            <tr>
              <th>Vorname</th>
              <th>Nachname</th>
              <th>Spitzname</th>
              <th>E-Mail</th>
              <th>Mobil</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, idx) in previewRows" :key="`preview-${idx}`">
              <td>{{ row.first_name }}</td>
              <td>{{ row.last_name }}</td>
              <td>{{ row.nickname || '—' }}</td>
              <td>{{ row.email }}</td>
              <td>{{ row.mobile || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="vol-import__actions">
      <button
          type="button"
          class="glass-btn-secondary vol-import__btn"
          :disabled="busy"
          @click="onCancel"
      >
        Abbrechen
      </button>
      <button
          type="button"
          class="vol-import__btn vol-import__btn--add"
          :disabled="!canAddToContactList"
          @click="addToContactList"
      >
        Zur Kontaktliste hinzufügen
      </button>
    </div>
  </section>
</template>

<style scoped>
.vol-import__title {
  margin: 0 0 0.35rem;
  font-size: 1rem;
  font-weight: 650;
}

.vol-import__hint {
  margin: 0 0 0.45rem;
  font-size: 0.8125rem;
  color: var(--color-text-muted);
  line-height: 1.45;
}

.vol-import__hint:last-of-type {
  margin-bottom: 0.85rem;
}

.vol-import__textarea {
  width: 100%;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  padding: 0.65rem 0.75rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.8125rem;
  line-height: 1.45;
  resize: vertical;
  background: color-mix(in srgb, #fff 80%, transparent);
}

.vol-import__section {
  margin-top: 0.85rem;
}

.vol-import__section-title {
  margin-bottom: 0.45rem;
  font-size: 0.8125rem;
  font-weight: 650;
  color: var(--color-text-muted);
}

.vol-import__table-wrap {
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  overflow: auto;
  max-height: 16rem;
}

.vol-import__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8125rem;
}

.vol-import__table th,
.vol-import__table td {
  padding: 0.45rem 0.55rem;
  text-align: left;
  border-bottom: 1px solid var(--color-border);
  vertical-align: top;
}

.vol-import__table th {
  position: sticky;
  top: 0;
  background: var(--color-bg-muted);
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.vol-import__table tbody tr:last-child td {
  border-bottom: none;
}

.vol-import__actions {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-top: 0.9rem;
}

.vol-import__btn {
  font-size: 0.875rem;
}

.vol-import__btn--add {
  padding: 0.45rem 0.85rem;
  border-radius: var(--radius);
  border: 1px solid color-mix(in srgb, #22c55e 35%, var(--color-border));
  background: color-mix(in srgb, #22c55e 16%, #fff);
  color: #166534;
  font-weight: 600;
  cursor: pointer;
}

.vol-import__btn--add:hover:not(:disabled) {
  background: color-mix(in srgb, #22c55e 24%, #fff);
}

.vol-import__btn--add:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
