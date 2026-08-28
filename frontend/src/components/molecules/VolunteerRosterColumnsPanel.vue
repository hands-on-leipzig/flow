<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import {ROSTER_BUILTIN_LABELS} from '@/volunteers/columns/rosterColumns'

export type VolunteerFieldDefinition = {
  id: number
  field_key: string
  label: string
  type: 'text' | 'number' | 'boolean' | 'select'
  options: Array<{value: string; label: string}>
  sequence: number
}

const FIELD_TYPES = [
  {value: 'text', label: 'Text'},
  {value: 'number', label: 'Zahl'},
  {value: 'boolean', label: 'Ja / Nein / ?'},
  {value: 'select', label: 'Auswahl'},
] as const

const props = defineProps<{
  open: boolean
  eventId: number | undefined
}>()

const emit = defineEmits<{
  close: []
  changed: []
}>()

const fields = ref<VolunteerFieldDefinition[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const deleteTarget = ref<VolunteerFieldDefinition | null>(null)

const draft = ref({
  label: '',
  type: 'text' as VolunteerFieldDefinition['type'],
  optionsText: '',
})

const canAdd = computed(() => draft.value.label.trim().length > 0 && !saving.value)

function optionLinesFromField(field: VolunteerFieldDefinition) {
  return (field.options ?? []).map((option) => option.label).join('\n')
}

async function loadFields() {
  if (!props.eventId) return
  loading.value = true
  error.value = ''
  try {
    const {data} = await axios.get(`/events/${props.eventId}/volunteer-fields`)
    fields.value = data.fields ?? []
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Spalten konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

function resetDraft() {
  draft.value = {label: '', type: 'text', optionsText: ''}
}

function parseOptions(text: string) {
  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((label) => ({value: label, label}))
}

async function addField() {
  if (!props.eventId || !canAdd.value) return
  saving.value = true
  error.value = ''
  try {
    const payload: Record<string, unknown> = {
      label: draft.value.label.trim(),
      type: draft.value.type,
    }
    if (draft.value.type === 'select') {
      payload.options = parseOptions(draft.value.optionsText)
    }
    await axios.post(`/events/${props.eventId}/volunteer-fields`, payload)
    resetDraft()
    await loadFields()
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Spalte konnte nicht angelegt werden.'
  } finally {
    saving.value = false
  }
}

async function saveField(field: VolunteerFieldDefinition, patch: Record<string, unknown>) {
  if (!props.eventId || saving.value) return
  saving.value = true
  error.value = ''
  try {
    await axios.patch(`/events/${props.eventId}/volunteer-fields/${field.id}`, patch)
    await loadFields()
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Spalte konnte nicht gespeichert werden.'
  } finally {
    saving.value = false
  }
}

async function moveField(field: VolunteerFieldDefinition, direction: 'up' | 'down') {
  await saveField(field, direction === 'up' ? {move_up: true} : {move_down: true})
}

async function updateFieldLabel(field: VolunteerFieldDefinition, label: string) {
  const trimmed = label.trim()
  if (!trimmed || trimmed === field.label) return
  await saveField(field, {label: trimmed})
}

async function updateFieldOptions(field: VolunteerFieldDefinition, optionsText: string) {
  await saveField(field, {options: parseOptions(optionsText)})
}

async function confirmDeleteField() {
  const field = deleteTarget.value
  if (!props.eventId || !field) return
  saving.value = true
  error.value = ''
  try {
    await axios.delete(`/events/${props.eventId}/volunteer-fields/${field.id}`)
    deleteTarget.value = null
    await loadFields()
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Spalte konnte nicht gelöscht werden.'
  } finally {
    saving.value = false
  }
}

watch(
  () => [props.open, props.eventId] as const,
  ([open]) => {
    if (open) {
      resetDraft()
      void loadFields()
    }
  },
  {immediate: true},
)
</script>

<template>
  <Teleport to="body">
    <div
        v-if="open"
        class="glass-scrim fixed inset-0 z-[100] flex items-center justify-center p-4"
        @click="emit('close')"
    >
      <div
          class="glass-modal vol-columns-dialog"
          role="dialog"
          aria-labelledby="vol-columns-dialog-title"
          @click.stop
      >
        <h2 id="vol-columns-dialog-title" class="vol-columns-dialog__title">Spalten verwalten</h2>
        <p class="vol-columns-dialog__hint">
          Feste Spalten: {{ ROSTER_BUILTIN_LABELS.join(', ') }}. Zusätzliche Spalten gelten nur für diese Veranstaltung.
        </p>

        <div v-if="error" class="glass-alert-warning vol-columns-dialog__alert">{{ error }}</div>
        <p v-if="loading" class="vol-muted">Laden…</p>

        <section v-if="fields.length" class="vol-columns-dialog__section">
          <h3 class="vol-columns-dialog__section-title">Eigene Spalten</h3>
          <div class="vol-columns-list">
            <div v-for="field in fields" :key="field.id" class="vol-columns-item">
              <div class="vol-columns-item__head">
                <input
                    class="glass-input glass-input--sm"
                    :value="field.label"
                    :disabled="saving"
                    @change="updateFieldLabel(field, ($event.target as HTMLInputElement).value)"
                >
                <span class="vol-columns-item__type">{{ FIELD_TYPES.find((t) => t.value === field.type)?.label }}</span>
                <div class="vol-columns-item__actions">
                  <button type="button" class="vol-icon-btn" :disabled="saving" title="Nach oben" @click="moveField(field, 'up')">
                    <i class="bi bi-arrow-up" aria-hidden="true"/>
                  </button>
                  <button type="button" class="vol-icon-btn" :disabled="saving" title="Nach unten" @click="moveField(field, 'down')">
                    <i class="bi bi-arrow-down" aria-hidden="true"/>
                  </button>
                  <button type="button" class="vol-icon-btn" :disabled="saving" title="Löschen" @click="deleteTarget = field">
                    <i class="bi bi-trash" aria-hidden="true"/>
                  </button>
                </div>
              </div>
              <textarea
                  v-if="field.type === 'select'"
                  class="vol-columns-item__options glass-input glass-input--sm"
                  :value="optionLinesFromField(field)"
                  rows="3"
                  placeholder="Eine Option pro Zeile"
                  :disabled="saving"
                  @change="updateFieldOptions(field, ($event.target as HTMLTextAreaElement).value)"
              />
            </div>
          </div>
        </section>

        <section class="vol-columns-dialog__section">
          <h3 class="vol-columns-dialog__section-title">Neue Spalte</h3>
          <div class="vol-columns-add">
            <input
                v-model="draft.label"
                class="glass-input glass-input--sm"
                placeholder="Bezeichnung"
                :disabled="saving"
            >
            <select v-model="draft.type" class="glass-input glass-input--sm" :disabled="saving">
              <option v-for="type in FIELD_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
            </select>
            <textarea
                v-if="draft.type === 'select'"
                v-model="draft.optionsText"
                class="glass-input glass-input--sm vol-columns-add__options"
                rows="3"
                placeholder="Optionen, eine pro Zeile"
                :disabled="saving"
            />
            <button type="button" class="glass-btn-accent" :disabled="!canAdd" @click="addField">
              Hinzufügen
            </button>
          </div>
        </section>

        <div class="vol-columns-dialog__footer">
          <button type="button" class="glass-btn-secondary" @click="emit('close')">Schließen</button>
        </div>
      </div>
    </div>
  </Teleport>

  <ConfirmationModal
      :show="!!deleteTarget"
      type="warning"
      title="Spalte löschen?"
      :message="deleteTarget ? `„${deleteTarget.label}“ und alle Werte werden entfernt.` : ''"
      confirm-text="Löschen"
      cancel-text="Abbrechen"
      @confirm="confirmDeleteField"
      @cancel="deleteTarget = null"
  />
</template>

<style scoped>
.vol-columns-dialog {
  width: min(100%, 34rem);
  max-height: min(90vh, 44rem);
  overflow: auto;
  padding: 1.25rem;
}

.vol-columns-dialog__title {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  font-weight: 650;
}

.vol-columns-dialog__hint,
.vol-columns-dialog__section-title {
  margin: 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.vol-columns-dialog__section {
  margin-top: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.vol-columns-dialog__section-title {
  font-weight: 600;
  color: var(--color-text);
}

.vol-columns-dialog__alert {
  margin-top: 0.75rem;
  padding: 0.65rem 0.85rem;
  border-radius: 0.75rem;
}

.vol-columns-list {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.vol-columns-item {
  padding: 0.65rem;
  border: 1px solid var(--liquid-border-soft);
  border-radius: var(--radius);
  background: var(--liquid-tile-bg-inner);
}

.vol-columns-item__head {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 0.5rem;
  align-items: center;
}

.vol-columns-item__type {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  white-space: nowrap;
}

.vol-columns-item__actions {
  display: inline-flex;
  gap: 0.15rem;
}

.vol-columns-item__options,
.vol-columns-add__options {
  margin-top: 0.5rem;
  width: 100%;
  resize: vertical;
}

.vol-columns-add {
  display: grid;
  gap: 0.5rem;
}

.vol-columns-dialog__footer {
  margin-top: 1rem;
  display: flex;
  justify-content: flex-end;
}

.vol-muted {
  opacity: 0.7;
  font-size: 0.9rem;
  margin: 0.75rem 0 0;
}

.vol-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.85rem;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
}

.vol-icon-btn:hover:not(:disabled) {
  background: var(--color-bg-hover);
  color: var(--color-text);
}

.vol-icon-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
