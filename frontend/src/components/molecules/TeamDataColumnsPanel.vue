<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'

export type TeamFieldDefinition = {
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

const fields = ref<TeamFieldDefinition[]>([])
const collectMeal = ref(true)
const usageMeal = ref(0)
const loading = ref(false)
const busyFieldId = ref<number | null>(null)
const adding = ref(false)
const deleting = ref(false)
const collectBusy = ref(false)
const error = ref('')
const deleteTarget = ref<TeamFieldDefinition | null>(null)
const disableMeal = ref(false)

const draft = ref({
  label: '',
  type: 'text' as TeamFieldDefinition['type'],
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

const disableMealMessage = computed(() => {
  const n = usageMeal.value
  return n > 0
    ? `Essen abschalten? ${n} Einträge werden geleert (Helfer:innen und Teams).`
    : 'Essens-Spalte wirklich abschalten? (gilt für Helfer:innen und Teams)'
})

function optionLinesFromField(field: TeamFieldDefinition) {
  return (field.options ?? []).map((option) => option.label).join('\n')
}

async function loadFields() {
  if (!props.eventId) return
  loading.value = true
  error.value = ''
  try {
    const [fieldsRes, collectRes] = await Promise.all([
      axios.get(`/events/${props.eventId}/team-fields`),
      axios.get(`/events/${props.eventId}/volunteer-collect`),
    ])
    fields.value = fieldsRes.data.fields ?? []
    collectMeal.value = collectRes.data.collect?.meal !== false
    usageMeal.value = Number(collectRes.data.usage?.meal ?? 0)
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
  if (deleteTarget.value || disableMeal.value || isBusy.value) return
  emit('close')
}

function onDialogKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    requestClose()
  }
}

function onMealClick(event: MouseEvent) {
  event.preventDefault()
  if (collectBusy.value) return
  if (collectMeal.value) {
    disableMeal.value = true
    return
  }
  void setCollectMeal(true)
}

async function setCollectMeal(enabled: boolean) {
  if (!props.eventId || collectBusy.value) return
  collectBusy.value = true
  error.value = ''
  try {
    const {data} = await axios.patch(`/events/${props.eventId}/volunteer-collect`, {meal: enabled})
    collectMeal.value = !!data.collect?.meal
    usageMeal.value = Number(data.usage?.meal ?? 0)
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Einstellung konnte nicht gespeichert werden.'
    await loadFields()
  } finally {
    collectBusy.value = false
    disableMeal.value = false
  }
}

async function confirmDisableMeal() {
  await setCollectMeal(false)
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
    await axios.post(`/events/${props.eventId}/team-fields`, payload)
    resetDraft()
    await loadFields()
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Spalte konnte nicht angelegt werden.'
  } finally {
    adding.value = false
  }
}

async function saveField(field: TeamFieldDefinition, patch: Record<string, unknown>) {
  if (!props.eventId || busyFieldId.value !== null) return
  busyFieldId.value = field.id
  error.value = ''
  try {
    await axios.patch(`/events/${props.eventId}/team-fields/${field.id}`, patch)
    await loadFields()
    emit('changed')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Spalte konnte nicht gespeichert werden.'
  } finally {
    busyFieldId.value = null
  }
}

async function moveField(field: TeamFieldDefinition, direction: 'up' | 'down') {
  await saveField(field, direction === 'up' ? {move_up: true} : {move_down: true})
}

async function updateFieldLabel(field: TeamFieldDefinition, label: string) {
  const trimmed = label.trim()
  if (!trimmed || trimmed === field.label) return
  await saveField(field, {label: trimmed})
}

async function updateFieldOptions(field: TeamFieldDefinition, optionsText: string) {
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
    await axios.delete(`/events/${props.eventId}/team-fields/${field.id}`)
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
      disableMeal.value = false
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
          aria-labelledby="team-columns-dialog-title"
          aria-modal="true"
          @click.stop
          @keydown="onDialogKeydown"
      >
        <header class="vol-columns-dialog__header">
          <h2 id="team-columns-dialog-title" class="vol-columns-dialog__title">Spalten verwalten</h2>
          <p class="vol-columns-dialog__hint">
            Teamname und Nummer kommen aus DRAHT / Sync und bleiben unverändert. Essen ist dieselbe Einstellung wie bei
            Helfer:innen (auch für Teamdaten). Eigene Spalten: Text = ein Textfeld; Zahl = eine Zahl; Ja/Nein und
            Auswahl = Anzahlen je Option (in der Tabelle Summe, Klick öffnet Aufschlüsselung).
          </p>
          <ul class="vol-columns-dialog__builtins" aria-label="Feste Spalten">
            <li class="vol-columns-dialog__builtin vol-columns-dialog__builtin--toggle">
              <label class="vol-columns-dialog__check">
                <input
                    type="checkbox"
                    :checked="collectMeal"
                    :disabled="collectBusy"
                    @click="onMealClick($event)"
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
                    <button type="button" class="vol-icon-btn" :disabled="isBusy" title="Nach oben" @click="moveField(field, 'up')">
                      <i class="bi bi-arrow-up" aria-hidden="true"/>
                    </button>
                    <button type="button" class="vol-icon-btn" :disabled="isBusy" title="Nach unten" @click="moveField(field, 'down')">
                      <i class="bi bi-arrow-down" aria-hidden="true"/>
                    </button>
                    <button type="button" class="vol-icon-btn" :disabled="isBusy" title="Löschen" @click="deleteTarget = field">
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
                <input v-model="draft.label" class="glass-input glass-input--sm" :disabled="adding">
              </label>
              <label class="vol-columns-add__field">
                <span class="vol-columns-add__label">Typ</span>
                <select v-model="draft.type" class="select-input" :disabled="adding">
                  <option v-for="type in FIELD_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
              </label>
              <label v-if="draft.type === 'select'" class="vol-columns-add__field">
                <span class="vol-columns-add__label">Optionen (eine pro Zeile)</span>
                <textarea v-model="draft.optionsText" class="glass-input glass-input--sm vol-columns-add__options" rows="4" :disabled="adding"/>
              </label>
              <div class="vol-columns-add__actions">
                <button type="button" class="glass-btn-accent" :disabled="!canAdd" @click="addField">Hinzufügen</button>
              </div>
            </div>
          </section>
        </div>

        <footer class="vol-columns-dialog__footer">
          <button type="button" class="glass-btn-secondary" @click="requestClose">Schließen</button>
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
        :show="disableMeal"
        scrim-class="z-[110]"
        type="warning"
        title="Essen abschalten?"
        :message="disableMealMessage"
        confirm-text="Abschalten"
        cancel-text="Abbrechen"
        :disable-confirm-button="collectBusy"
        @confirm="confirmDisableMeal"
        @cancel="disableMeal = false"
    />
  </Teleport>
</template>

<style scoped>
@import '@/assets/volunteers.css';
</style>
