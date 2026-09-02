<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'

export type VolunteerFieldDefinition = {
  id: number
  field_key: string
  label: string
  type: 'text' | 'number' | 'boolean' | 'select'
  options: Array<{value: string; label: string}>
  sequence: number
  usage_count?: number
}

const FIELD_TYPES = [
  {value: 'text', label: 'Text'},
  {value: 'number', label: 'Zahl'},
  {value: 'boolean', label: 'Ja / Nein'},
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
const collect = ref({t_shirt: true, meal: true})
const usage = ref({t_shirt: 0, meal: 0})
const loading = ref(false)
const busyFieldId = ref<number | null>(null)
const adding = ref(false)
const deleting = ref(false)
const collectBusy = ref(false)
const error = ref('')
const deleteTarget = ref<VolunteerFieldDefinition | null>(null)
const disableTarget = ref<'t_shirt' | 'meal' | null>(null)

const draft = ref({
  label: '',
  type: 'text' as VolunteerFieldDefinition['type'],
  optionsText: '',
})

const isBusy = computed(() => adding.value || deleting.value || busyFieldId.value !== null || collectBusy.value)

const draftOptions = computed(() => parseOptions(draft.value.optionsText))

const canAdd = computed(() => {
  if (adding.value || !draft.value.label.trim()) return false
  if (draft.value.type === 'select' && draftOptions.value.length === 0) return false
  return true
})

const deleteConfirmMessage = computed(() => {
  const field = deleteTarget.value
  if (!field) return ''
  const n = Number(field.usage_count ?? 0)
  if (n > 0) {
    return `„${field.label}“ löschen? ${n} Einträge werden gelöscht.`
  }
  return `„${field.label}“ und alle Werte werden entfernt.`
})

const disableConfirmMessage = computed(() => {
  if (disableTarget.value === 't_shirt') {
    const n = usage.value.t_shirt
    return n > 0
      ? `T-Shirt abschalten? ${n} Einträge werden geleert.`
      : 'T-Shirt-Spalte wirklich abschalten?'
  }
  if (disableTarget.value === 'meal') {
    const n = usage.value.meal
    return n > 0
      ? `Essen abschalten? ${n} Einträge werden geleert.`
      : 'Essens-Spalte wirklich abschalten?'
  }
  return ''
})

function optionLinesFromField(field: VolunteerFieldDefinition) {
  return (field.options ?? []).map((option) => option.label).join('\n')
}

async function loadFields() {
  if (!props.eventId) return
  loading.value = true
  error.value = ''
  try {
    const [fieldsRes, collectRes] = await Promise.all([
      axios.get(`/events/${props.eventId}/volunteer-fields`),
      axios.get(`/events/${props.eventId}/volunteer-collect`),
    ])
    fields.value = fieldsRes.data.fields ?? []
    collect.value = {
      t_shirt: !!collectRes.data.collect?.t_shirt,
      meal: !!collectRes.data.collect?.meal,
    }
    usage.value = {
      t_shirt: Number(collectRes.data.usage?.t_shirt ?? 0),
      meal: Number(collectRes.data.usage?.meal ?? 0),
    }
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

function requestClose() {
  if (deleteTarget.value || disableTarget.value || isBusy.value) return
  emit('close')
}

function onDialogKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    requestClose()
  }
}

function onCollectClick(key: 't_shirt' | 'meal', event: MouseEvent) {
  event.preventDefault()
  if (collectBusy.value) return
  if (collect.value[key]) {
    disableTarget.value = key
    return
  }
  void setCollect(key, true)
}

async function setCollect(key: 't_shirt' | 'meal', enabled: boolean) {
  if (!props.eventId || collectBusy.value) return
  collectBusy.value = true
  error.value = ''
  try {
    const {data} = await axios.patch(`/events/${props.eventId}/volunteer-collect`, {[key]: enabled})
    collect.value = {
      t_shirt: !!data.collect?.t_shirt,
      meal: !!data.collect?.meal,
    }
    usage.value = {
      t_shirt: Number(data.usage?.t_shirt ?? 0),
      meal: Number(data.usage?.meal ?? 0),
    }
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Einstellung konnte nicht gespeichert werden.'
    // reload to sync checkbox state
    await loadFields()
  } finally {
    collectBusy.value = false
    disableTarget.value = null
  }
}

async function confirmDisableCollect() {
  const key = disableTarget.value
  if (!key) return
  await setCollect(key, false)
}

async function addField() {
  if (!props.eventId || !canAdd.value) return
  adding.value = true
  error.value = ''
  try {
    const payload: Record<string, unknown> = {
      label: draft.value.label.trim(),
      type: draft.value.type,
    }
    if (draft.value.type === 'select') {
      payload.options = draftOptions.value
    }
    await axios.post(`/events/${props.eventId}/volunteer-fields`, payload)
    resetDraft()
    await loadFields()
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Spalte konnte nicht angelegt werden.'
  } finally {
    adding.value = false
  }
}

async function saveField(field: VolunteerFieldDefinition, patch: Record<string, unknown>) {
  if (!props.eventId || busyFieldId.value !== null) return
  busyFieldId.value = field.id
  error.value = ''
  try {
    await axios.patch(`/events/${props.eventId}/volunteer-fields/${field.id}`, patch)
    await loadFields()
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Spalte konnte nicht gespeichert werden.'
  } finally {
    busyFieldId.value = null
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
  const options = parseOptions(optionsText)
  if (field.type === 'select' && options.length === 0) {
    error.value = 'Auswahl-Felder benötigen mindestens eine Option.'
    return
  }
  await saveField(field, {options})
}

async function confirmDeleteField() {
  const field = deleteTarget.value
  if (!props.eventId || !field) return
  deleting.value = true
  error.value = ''
  try {
    await axios.delete(`/events/${props.eventId}/volunteer-fields/${field.id}`)
    deleteTarget.value = null
    await loadFields()
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Spalte konnte nicht gelöscht werden.'
  } finally {
    deleting.value = false
  }
}

watch(
  () => [props.open, props.eventId] as const,
  ([open]) => {
    if (open) {
      resetDraft()
      deleteTarget.value = null
      disableTarget.value = null
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
        @click="requestClose"
    >
      <div
          class="glass-modal vol-columns-dialog"
          role="dialog"
          aria-labelledby="vol-columns-dialog-title"
          aria-modal="true"
          @click.stop
          @keydown="onDialogKeydown"
      >
        <header class="vol-columns-dialog__header">
          <h2 id="vol-columns-dialog-title" class="vol-columns-dialog__title">Spalten verwalten</h2>
          <p class="vol-columns-dialog__hint">
            Name, Rolle und Foto bleiben. T-Shirt und Essen könnt ihr abwählen (dabei werden vorhandene Angaben geleert).
            Eigene Spalten gelten nur für diese Veranstaltung.
          </p>
          <ul class="vol-columns-dialog__builtins" aria-label="Feste Spalten">
            <li class="vol-columns-dialog__builtin">Foto Erlaubnis</li>
            <li class="vol-columns-dialog__builtin vol-columns-dialog__builtin--toggle">
              <label class="vol-columns-dialog__check">
                <input
                    type="checkbox"
                    :checked="collect.t_shirt"
                    :disabled="collectBusy"
                    @click="onCollectClick('t_shirt', $event)"
                >
                <span>T-Shirt Größe</span>
              </label>
            </li>
            <li class="vol-columns-dialog__builtin vol-columns-dialog__builtin--toggle">
              <label class="vol-columns-dialog__check">
                <input
                    type="checkbox"
                    :checked="collect.meal"
                    :disabled="collectBusy"
                    @click="onCollectClick('meal', $event)"
                >
                <span>Essen</span>
              </label>
            </li>
          </ul>
        </header>

        <div class="vol-columns-dialog__body">
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
                      :disabled="busyFieldId === field.id"
                      @change="updateFieldLabel(field, ($event.target as HTMLInputElement).value)"
                  >
                  <span class="vol-columns-item__type">{{ FIELD_TYPES.find((t) => t.value === field.type)?.label }}</span>
                  <div class="vol-columns-item__actions">
                    <button
                        type="button"
                        class="vol-icon-btn"
                        :disabled="isBusy"
                        title="Nach oben"
                        @click="moveField(field, 'up')"
                    >
                      <i class="bi bi-arrow-up" aria-hidden="true"/>
                    </button>
                    <button
                        type="button"
                        class="vol-icon-btn"
                        :disabled="isBusy"
                        title="Nach unten"
                        @click="moveField(field, 'down')"
                    >
                      <i class="bi bi-arrow-down" aria-hidden="true"/>
                    </button>
                    <button
                        type="button"
                        class="vol-icon-btn"
                        :disabled="isBusy"
                        title="Löschen"
                        @click="deleteTarget = field"
                    >
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
                    :disabled="busyFieldId === field.id"
                    @change="updateFieldOptions(field, ($event.target as HTMLTextAreaElement).value)"
                />
              </div>
            </div>
          </section>

          <section class="vol-columns-dialog__section">
            <h3 class="vol-columns-dialog__section-title">Neue Spalte</h3>
            <div class="vol-columns-add">
              <label class="vol-columns-add__field">
                <span class="vol-columns-add__label">Bezeichnung</span>
                <input
                    v-model="draft.label"
                    class="glass-input glass-input--sm"
                    placeholder="z. B. Teilnahme Treffen am Vorabend"
                    :disabled="adding"
                >
              </label>
              <label class="vol-columns-add__field">
                <span class="vol-columns-add__label">Typ</span>
                <select v-model="draft.type" class="select-input" :disabled="adding">
                  <option v-for="type in FIELD_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
              </label>
              <label v-if="draft.type === 'select'" class="vol-columns-add__field">
                <span class="vol-columns-add__label">Optionen (eine pro Zeile)</span>
                <textarea
                    v-model="draft.optionsText"
                    class="glass-input glass-input--sm vol-columns-add__options"
                    rows="4"
                    placeholder="Option A&#10;Option B"
                    :disabled="adding"
                />
              </label>
            </div>
          </section>
        </div>

        <footer class="vol-columns-dialog__footer">
          <button type="button" class="glass-btn-secondary" @click="requestClose">
            Schließen
          </button>
          <button type="button" class="glass-btn-accent" :disabled="!canAdd" @click="addField">
            Hinzufügen
          </button>
        </footer>
      </div>
    </div>
  </Teleport>

  <Teleport to="body">
    <ConfirmationModal
        :show="!!deleteTarget"
        scrim-class="z-[110]"
        type="warning"
        title="Spalte löschen?"
        :message="deleteConfirmMessage"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        :disable-confirm-button="deleting"
        @confirm="confirmDeleteField"
        @cancel="deleteTarget = null"
    />
  </Teleport>

  <Teleport to="body">
    <ConfirmationModal
        :show="!!disableTarget"
        scrim-class="z-[110]"
        type="warning"
        title="Spalte abschalten?"
        :message="disableConfirmMessage"
        confirm-text="Abschalten"
        cancel-text="Abbrechen"
        :disable-confirm-button="collectBusy"
        @confirm="confirmDisableCollect"
        @cancel="disableTarget = null"
    />
  </Teleport>
</template>

<style scoped>
.vol-columns-dialog {
  display: flex;
  flex-direction: column;
  width: min(100%, 34rem);
  max-height: min(90vh, 44rem);
  overflow: hidden;
  padding: 0;
}

.vol-columns-dialog__header {
  flex-shrink: 0;
  padding: 1.25rem 1.25rem 0.75rem;
  border-bottom: 1px solid var(--liquid-border-soft);
}

.vol-columns-dialog__title {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  font-weight: 650;
}

.vol-columns-dialog__hint {
  margin: 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.vol-columns-dialog__builtins {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin: 0.75rem 0 0;
  padding: 0;
  list-style: none;
}

.vol-columns-dialog__builtin {
  padding: 0.2rem 0.55rem;
  border: 1px solid var(--liquid-border-soft);
  border-radius: 999px;
  background: var(--liquid-tile-bg-inner);
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.vol-columns-dialog__builtin--toggle {
  padding: 0.15rem 0.45rem;
}

.vol-columns-dialog__check {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  cursor: pointer;
}

.vol-columns-dialog__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 1rem 1.25rem;
}

.vol-columns-dialog__section {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.vol-columns-dialog__section + .vol-columns-dialog__section {
  margin-top: 1.25rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--liquid-border-soft);
}

.vol-columns-dialog__section-title {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--color-text);
}

.vol-columns-dialog__alert {
  margin-bottom: 0.75rem;
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

.vol-columns-item__options {
  margin-top: 0.5rem;
  width: 100%;
  resize: vertical;
}

.vol-columns-add {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.vol-columns-add__field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.vol-columns-add__label {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.vol-columns-add select.select-input {
  width: 100%;
  box-sizing: border-box;
  min-height: var(--field-min-height-sm);
  height: var(--field-min-height-sm);
  padding: var(--field-padding-y-sm) 2.25rem var(--field-padding-y-sm) var(--field-padding-x-sm);
  font-size: var(--field-font-size-sm);
  border-radius: var(--field-radius-sm);
  line-height: 1.4;
}

.vol-columns-add__options {
  width: 100%;
  resize: vertical;
}

.vol-columns-dialog__footer {
  flex-shrink: 0;
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.85rem 1.25rem 1.25rem;
  border-top: 1px solid var(--liquid-border-soft);
  background: var(--liquid-tile-bg-inner);
}

.vol-muted {
  opacity: 0.7;
  font-size: 0.9rem;
  margin: 0;
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
