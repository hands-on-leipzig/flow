<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import CustomColumnsDialogShell from '@/components/molecules/CustomColumnsDialogShell.vue'
import CustomColumnsFieldsEditor from '@/components/molecules/CustomColumnsFieldsEditor.vue'
import {
  canAddCustomField,
  deleteCustomFieldConfirmMessage,
  emptyCustomFieldDraft,
  parseCustomFieldOptions,
  type CustomFieldDefinition,
} from '@/utils/customFieldColumns'

export type TeamFieldDefinition = CustomFieldDefinition

const props = defineProps<{
  open: boolean
  eventId: number | undefined
}>()

const emit = defineEmits<{
  close: []
  changed: []
}>()

const fields = ref<CustomFieldDefinition[]>([])
const collectMeal = ref(true)
const usageMeal = ref(0)
const loading = ref(false)
const busyFieldId = ref<number | null>(null)
const adding = ref(false)
const deleting = ref(false)
const collectBusy = ref(false)
const error = ref('')
const deleteTarget = ref<CustomFieldDefinition | null>(null)
const disableMeal = ref(false)
const draft = ref(emptyCustomFieldDraft())

const isBusy = computed(() => adding.value || deleting.value || busyFieldId.value !== null || collectBusy.value)
const canAdd = computed(() => canAddCustomField(draft.value, adding.value))
const deleteConfirmMessage = computed(() => (deleteTarget.value ? deleteCustomFieldConfirmMessage(deleteTarget.value) : ''))

const disableMealMessage = computed(() => {
  const n = usageMeal.value
  return n > 0
    ? `Essen abschalten? ${n} Einträge werden geleert (Helfer:innen und Teams).`
    : 'Essens-Spalte wirklich abschalten? (gilt für Helfer:innen und Teams)'
})

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
  draft.value = emptyCustomFieldDraft()
}

function requestClose() {
  if (deleteTarget.value || disableMeal.value || isBusy.value) return
  emit('close')
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
      payload.options = parseCustomFieldOptions(draft.value.optionsText)
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

async function saveField(field: CustomFieldDefinition, patch: Record<string, unknown>) {
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

async function updateFieldLabel(field: CustomFieldDefinition, label: string) {
  const trimmed = label.trim()
  if (!trimmed || trimmed === field.label) return
  await saveField(field, {label: trimmed})
}

async function updateFieldOptions(field: CustomFieldDefinition, optionsText: string) {
  const options = parseCustomFieldOptions(optionsText)
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
  <CustomColumnsDialogShell
      :open="open"
      title-id="team-columns-dialog-title"
      hint="Die ersten Spalten kommen aus der Anmeldung. Essen könnt ihr abwählen. Die Einstellung ist dieselbe wie für Helfer:innen. Eigene Spalten gelten nur für diese Veranstaltung."
      :error="error"
      :loading="loading"
      @close="requestClose"
  >
    <template #builtins>
      <li class="vol-columns-dialog__builtin">Teamname</li>
      <li class="vol-columns-dialog__builtin">Nr</li>
      <li class="vol-columns-dialog__builtin">Programm</li>
      <li class="vol-columns-dialog__builtin">Organisation</li>
      <li class="vol-columns-dialog__builtin">Personen</li>
      <li class="vol-columns-dialog__builtin">Foto Erlaubnis</li>
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
    </template>

    <CustomColumnsFieldsEditor
        :fields="fields"
        :draft="draft"
        :busy-field-id="busyFieldId"
        :is-busy="isBusy"
        :adding="adding"
        :can-add="canAdd"
        new-field-placeholder="z. B. Ankunft am Vorabend"
        @update:draft="draft = $event"
        @update-label="updateFieldLabel"
        @update-options="updateFieldOptions"
        @move-up="saveField($event, {move_up: true})"
        @move-down="saveField($event, {move_down: true})"
        @delete="deleteTarget = $event"
        @add="addField"
    />
  </CustomColumnsDialogShell>

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
</template>

<style scoped>
@import '@/assets/volunteers.css';
</style>
