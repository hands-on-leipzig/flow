<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'

type Kind = 'team' | 'volunteer'
type FormField = {field_key: string; label: string; public_form: boolean}

const props = defineProps<{
  open: boolean
  eventId: number | null | undefined
  kind: Kind
}>()

const emit = defineEmits<{
  close: []
}>()

/** Always present on Dateneingabe für Helfer:innen (labels match VolunteerPublicFormFlow). */
const volunteerFixedPersonFields = ['Vorname', 'Name', 'Mobil', 'Organisation'] as const

const fields = ref<FormField[]>([])
const busy = ref(false)
const loading = ref(false)
const collectTShirt = ref(true)
const collectMeal = ref(true)

const titleId = computed(() =>
  props.kind === 'team' ? 'public-form-fields-team-title' : 'public-form-fields-volunteer-title',
)

const hint = computed(() =>
  props.kind === 'team'
    ? 'Der Status der Fotoerlaubnis ist fest im Formular. Alle anderen Spalten der Tabelle können im Formular ein- oder ausgeblendet werden.'
    : 'Angaben zur Person selbst und der Status der Fotoerlaubnis sind fest im Formular. Alle anderen Spalten der Tabelle können im Formular ein- oder ausgeblendet werden.',
)

function mapFields(data: {fields?: Array<{field_key: string; label: string; public_form?: boolean}>}) {
  return (data.fields ?? []).map((field) => ({
    field_key: field.field_key,
    label: field.label,
    public_form: !!field.public_form,
  }))
}

function applyCollect(collect: {t_shirt?: boolean; meal?: boolean} | null | undefined) {
  collectTShirt.value = collect?.t_shirt !== false
  collectMeal.value = collect?.meal !== false
}

async function load() {
  if (!props.eventId) {
    fields.value = []
    collectTShirt.value = true
    collectMeal.value = true
    return
  }
  loading.value = true
  try {
    const path = props.kind === 'team'
      ? `/events/${props.eventId}/team-fields`
      : `/events/${props.eventId}/volunteer-fields`
    const {data} = await axios.get(path)
    fields.value = mapFields(data)
    if (props.kind === 'team') {
      collectMeal.value = data.collect?.meal !== false
      collectTShirt.value = true
    } else {
      applyCollect(data.collect)
    }
  } catch {
    fields.value = []
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!props.eventId || busy.value) return
  busy.value = true
  try {
    const path = props.kind === 'team'
      ? `/events/${props.eventId}/team-fields/public-form`
      : `/events/${props.eventId}/volunteer-fields/public-form`
    const {data} = await axios.put(path, {
      field_keys: fields.value.filter((f) => f.public_form).map((f) => f.field_key),
    })
    fields.value = mapFields(data)
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Formular-Felder konnten nicht gespeichert werden.'), 'error')
    await load()
  } finally {
    busy.value = false
  }
}

function toggleField(fieldKey: string, next: boolean) {
  const row = fields.value.find((f) => f.field_key === fieldKey)
  if (!row || row.public_form === next) return
  row.public_form = next
  void save()
}

function onDialogKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('close')
  }
}

watch(
  () => [props.open, props.eventId, props.kind] as const,
  ([open]) => {
    if (open) void load()
  },
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
          :aria-labelledby="titleId"
          aria-modal="true"
          @click.stop
          @keydown="onDialogKeydown"
      >
        <header class="vol-columns-dialog__header">
          <h2 :id="titleId" class="vol-columns-dialog__title">Felder im Formular</h2>
          <p class="vol-columns-dialog__hint">{{ hint }}</p>
        </header>

        <div class="vol-columns-dialog__body">
          <p v-if="loading" class="vol-muted">Laden…</p>
          <div v-else class="pub-form-fields">
            <template v-if="kind === 'team'">
              <div
                  class="pub-form-fields__check pub-form-fields__check--fixed"
                  title="Immer im Formular (Status, nicht editierbar)"
              >
                <input type="checkbox" :checked="true" disabled>
                <span>Fotoerlaubnis (nur Anzeige des Status)</span>
              </div>
              <div
                  v-if="collectMeal"
                  class="pub-form-fields__check pub-form-fields__check--fixed"
                  title="Immer im Formular, solange Essen in Teamdaten aktiv ist"
              >
                <input type="checkbox" :checked="true" disabled>
                <span>Essen</span>
              </div>
            </template>
            <template v-else>
              <div class="pub-form-fields__row" title="Immer im Formular">
                <div
                    v-for="label in volunteerFixedPersonFields"
                    :key="label"
                    class="pub-form-fields__check pub-form-fields__check--fixed"
                >
                  <input type="checkbox" :checked="true" disabled>
                  <span>{{ label }}</span>
                </div>
              </div>
              <div
                  class="pub-form-fields__check pub-form-fields__check--fixed"
                  title="Immer im Formular (Status, nicht editierbar)"
              >
                <input type="checkbox" :checked="true" disabled>
                <span>Fotoerlaubnis (nur Anzeige des Status)</span>
              </div>
              <div
                  v-if="collectTShirt"
                  class="pub-form-fields__row"
                  title="Immer im Formular, solange T-Shirt in der Helferliste aktiv ist"
              >
                <div class="pub-form-fields__check pub-form-fields__check--fixed">
                  <input type="checkbox" :checked="true" disabled>
                  <span>T-Shirt Schnitt</span>
                </div>
                <div class="pub-form-fields__check pub-form-fields__check--fixed">
                  <input type="checkbox" :checked="true" disabled>
                  <span>T-Shirt Größe</span>
                </div>
              </div>
              <div
                  v-if="collectMeal"
                  class="pub-form-fields__check pub-form-fields__check--fixed"
                  title="Immer im Formular, solange Essen in der Helferliste aktiv ist"
              >
                <input type="checkbox" :checked="true" disabled>
                <span>Essen</span>
              </div>
            </template>
            <label
                v-for="field in fields"
                :key="field.field_key"
                class="pub-form-fields__check"
            >
              <input
                  type="checkbox"
                  :checked="field.public_form"
                  :disabled="busy"
                  @change="toggleField(field.field_key, ($event.target as HTMLInputElement).checked)"
              >
              <span>{{ field.label }}</span>
            </label>
          </div>
        </div>

        <footer class="vol-columns-dialog__footer">
          <button type="button" class="glass-btn-secondary" @click="emit('close')">
            Schließen
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style>
@import '@/assets/columns-dialog.css';

.pub-form-fields {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.pub-form-fields__check {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  cursor: pointer;
}

.pub-form-fields__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem 0.85rem;
}

.pub-form-fields__check--fixed {
  cursor: default;
  color: var(--color-text-muted);
  opacity: 0.85;
}

.pub-form-fields__check--fixed input {
  cursor: not-allowed;
}
</style>
