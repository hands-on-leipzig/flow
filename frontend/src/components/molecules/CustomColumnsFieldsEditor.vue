<script setup lang="ts">
import {
  CUSTOM_FIELD_TYPES,
  customFieldTypeLabel,
  optionLinesFromCustomField,
  type CustomFieldDefinition,
  type CustomFieldDraft,
} from '@/utils/customFieldColumns'

defineProps<{
  fields: CustomFieldDefinition[]
  draft: CustomFieldDraft
  busyFieldId: number | null
  isBusy: boolean
  adding: boolean
  canAdd: boolean
  newFieldPlaceholder?: string
}>()

const emit = defineEmits<{
  'update:draft': [draft: CustomFieldDraft]
  'update-label': [field: CustomFieldDefinition, label: string]
  'update-options': [field: CustomFieldDefinition, optionsText: string]
  'move-up': [field: CustomFieldDefinition]
  'move-down': [field: CustomFieldDefinition]
  delete: [field: CustomFieldDefinition]
  add: []
}>()
</script>

<template>
  <section v-if="fields.length" class="vol-columns-dialog__section">
    <h3 class="vol-columns-dialog__section-title">Eigene Spalten</h3>
    <div class="vol-columns-list">
      <div v-for="field in fields" :key="field.id" class="vol-columns-item">
        <div class="vol-columns-item__head">
          <input
              class="glass-input glass-input--sm"
              :value="field.label"
              :disabled="busyFieldId === field.id"
              @change="emit('update-label', field, ($event.target as HTMLInputElement).value)"
          >
          <span class="vol-columns-item__type">{{ customFieldTypeLabel(field.type) }}</span>
          <div class="vol-columns-item__actions">
            <button
                type="button"
                class="vol-icon-btn"
                :disabled="isBusy"
                title="Nach oben"
                @click="emit('move-up', field)"
            >
              <i class="bi bi-arrow-up" aria-hidden="true"/>
            </button>
            <button
                type="button"
                class="vol-icon-btn"
                :disabled="isBusy"
                title="Nach unten"
                @click="emit('move-down', field)"
            >
              <i class="bi bi-arrow-down" aria-hidden="true"/>
            </button>
            <button
                type="button"
                class="vol-icon-btn"
                :disabled="isBusy"
                title="Löschen"
                @click="emit('delete', field)"
            >
              <i class="bi bi-trash" aria-hidden="true"/>
            </button>
          </div>
        </div>
        <textarea
            v-if="field.type === 'select'"
            class="vol-columns-item__options glass-input glass-input--sm"
            :value="optionLinesFromCustomField(field)"
            rows="3"
            placeholder="Eine Option pro Zeile"
            :disabled="busyFieldId === field.id"
            @change="emit('update-options', field, ($event.target as HTMLTextAreaElement).value)"
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
            :value="draft.label"
            class="glass-input glass-input--sm"
            :placeholder="newFieldPlaceholder ?? 'z. B. Teilnahme Treffen am Vorabend'"
            :disabled="adding"
            @input="emit('update:draft', {...draft, label: ($event.target as HTMLInputElement).value})"
        >
      </label>
      <label class="vol-columns-add__field">
        <span class="vol-columns-add__label">Typ</span>
        <select
            :value="draft.type"
            class="select-input"
            :disabled="adding"
            @change="emit('update:draft', {...draft, type: ($event.target as HTMLSelectElement).value as CustomFieldDraft['type']})"
        >
          <option v-for="type in CUSTOM_FIELD_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
        </select>
      </label>
      <label v-if="draft.type === 'select'" class="vol-columns-add__field">
        <span class="vol-columns-add__label">Optionen (eine pro Zeile)</span>
        <textarea
            :value="draft.optionsText"
            class="glass-input glass-input--sm vol-columns-add__options"
            rows="4"
            placeholder="Option A&#10;Option B"
            :disabled="adding"
            @input="emit('update:draft', {...draft, optionsText: ($event.target as HTMLTextAreaElement).value})"
        />
      </label>
      <div class="vol-columns-add__actions">
        <button type="button" class="glass-btn-accent" :disabled="!canAdd" @click="emit('add')">
          Hinzufügen
        </button>
      </div>
    </div>
  </section>
</template>

<style>
@import '@/assets/volunteers.css';
</style>
