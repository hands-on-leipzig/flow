<script setup>
import {ref, watch, computed} from 'vue'
import InfoPopover from '@/components/atoms/InfoPopover.vue'
import TimePicker from '@/components/atoms/TimePicker.vue'

const props = defineProps({
  param: {
    type: Object
  },
  withLabel: {
    type: Boolean,
    default: false,
  },
  horizontal: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  compact: {
    type: Boolean,
    default: false,
  }
})
const emit = defineEmits(['update'])
const validationError = ref('')

const normalizeBoolean = (val) => val === 1 || val === true || val === '1'

const localValue = ref(
    props.param.type === 'boolean'
        ? normalizeBoolean(props.param.value)
        : props.param.value
)

watch(() => props.param.value, val => {
  localValue.value = props.param.type === 'boolean'
      ? normalizeBoolean(val)
      : val
})

const showDefaultValue = (param) => {
  switch (param.type) {
    case 'boolean':
      return normalizeBoolean(param.default_value) ? 'an' : 'aus'
    case 'time':
      return normalizeTimeFormat(param.default_value)
    default:
      return param.default_value
  }
}

const isChangedFromDefault = (param) => {
  if (param.default_value === null || param.default_value === undefined) return false
  if (param.name && param.name.toLowerCase().includes('team')) return false

  switch (param.type) {
    case 'boolean':
      return localValue.value !== normalizeBoolean(param.default_value)
    case 'integer':
    case 'decimal':
      return Number(localValue.value) !== Number(param.default_value)
    case 'time': {
      const normalizedCurrent = normalizeTimeFormat(localValue.value)
      const normalizedDefault = normalizeTimeFormat(param.default_value)
      return normalizedCurrent !== normalizedDefault
    }
    default:
      return localValue.value !== param.default_value
  }
}

function validateValue(value, param) {
  validationError.value = ''

  if (param.type === 'time') {
    return validateTimeValue(value, param)
  }

  if (param.type !== 'integer' && param.type !== 'decimal') {
    return true
  }

  const numericValue = Number(value)

  if (isNaN(numericValue)) {
    validationError.value = 'Ungültige Zahl'
    return false
  }

  if (param.min !== null && param.min !== undefined && numericValue < param.min) {
    validationError.value = `Wert muss mindestens ${param.min} sein`
    return false
  }

  if (param.max !== null && param.max !== undefined && numericValue > param.max) {
    validationError.value = `Wert darf höchstens ${param.max} sein`
    return false
  }

  if (param.step !== null && param.step !== undefined && param.step > 0) {
    const min = param.min ?? 0
    const step = param.step
    if ((numericValue - min) % step !== 0) {
      validationError.value = `Nur ${step}er-Schritte erlaubt`
      return false
    }
  }

  return true
}

function timeToMinutes(timeString) {
  if (!timeString || typeof timeString !== 'string') return 0
  const [hours, minutes] = timeString.split(':').map(Number)
  return (hours || 0) * 60 + (minutes || 0)
}

function normalizeTimeFormat(timeString) {
  if (!timeString || typeof timeString !== 'string') return timeString
  const [hours, minutes] = timeString.split(':')
  if (!hours || !minutes) return timeString
  return `${hours.padStart(2, '0')}:${minutes.padStart(2, '0')}`
}

function validateTimeValue(timeValue, param) {
  if (!timeValue || timeValue === '' || timeValue.trim() === '') {
    validationError.value = ''
    return true
  }

  const timeRegex = /^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/
  if (!timeRegex.test(timeValue)) {
    validationError.value = 'Ungültiges Zeitformat (hh:mm)'
    return false
  }

  const valueMinutes = timeToMinutes(timeValue)

  if (param.min !== null && param.min !== undefined && param.min !== '') {
    const minMinutes = timeToMinutes(param.min)
    if (valueMinutes < minMinutes) {
      validationError.value = `Zeit darf minimal ${param.min} sein`
      return false
    }
  }

  if (param.max !== null && param.max !== undefined && param.max !== '') {
    const maxMinutes = timeToMinutes(param.max)
    if (valueMinutes > maxMinutes) {
      validationError.value = `Zeit darf maximal ${param.max} sein`
      return false
    }
  }

  if (param.step !== null && param.step !== undefined && param.step > 0) {
    if (valueMinutes % param.step !== 0) {
      validationError.value = `Nur ${param.step}-Minuten-Schritte erlaubt`
      return false
    }
  }

  return true
}

function emitChange() {
  if (validateValue(localValue.value, props.param)) {
    emit('update', {...props.param, value: localValue.value})
  }
}

function setBoolean(value) {
  if (props.disabled || localValue.value === value) return
  localValue.value = value
  emitChange()
}

const controlClass = computed(() => {
  const changed = isChangedFromDefault(props.param) && !props.disabled
  return [
    'glass-input glass-input--sm liquid-surface-control min-w-0',
    props.disabled ? 'opacity-50 cursor-not-allowed' : '',
    validationError.value ? 'param-field__control--invalid' : '',
    changed ? 'param-field__control--changed' : '',
  ]
})
</script>

<template>
  <div
      class="param-field min-w-0 w-full"
      :class="compact ? '' : 'flex flex-col gap-1.5'"
  >
    <div v-if="withLabel && !compact" class="flex items-center gap-1 min-w-0">
      <span class="glass-settings-label min-w-0 break-words">{{ param.ui_label }}</span>
      <InfoPopover :text="param.ui_description"/>
    </div>

    <div class="glass-settings-row">
      <div v-if="param.type === 'integer' || param.type === 'decimal'" class="flex items-center gap-2">
        <input
            type="number"
            :min="param.min"
            :max="param.max"
            :step="Number(param.step) || undefined"
            v-model="localValue"
            :disabled="disabled"
            class="param-field__number w-[5.5rem] px-2.5 text-sm"
            :class="controlClass"
            @change="emitChange"
            @input="validateValue(localValue, param)"
        />
        <span v-if="showDefaultValue(param) && !validationError" class="glass-settings-hint">
          {{ showDefaultValue(param) }}
        </span>
      </div>

      <div v-else-if="param.type === 'boolean'" class="flex gap-1.5">
        <button
            type="button"
            class="glass-choice whitespace-nowrap"
            :class="localValue ? 'glass-choice--active' : ''"
            :disabled="disabled"
            @click="setBoolean(true)"
        >
          ja
        </button>
        <button
            type="button"
            class="glass-choice whitespace-nowrap"
            :class="!localValue ? 'glass-choice--active' : ''"
            :disabled="disabled"
            @click="setBoolean(false)"
        >
          nein
        </button>
      </div>

      <div v-else-if="param.type === 'date'">
        <input
            type="date"
            v-model="localValue"
            :disabled="disabled"
            class="w-[9.25rem] text-sm"
            :class="controlClass"
            @change="emitChange"
        />
      </div>

      <div v-else-if="param.type === 'time'" class="flex items-center gap-2 flex-wrap">
        <TimePicker
            :model-value="localValue"
            :disabled="disabled"
            :min="param.min || undefined"
            :max="param.max || undefined"
            :step="Number(param.step) || 5"
            @update:model-value="localValue = $event; validateValue(localValue, param); emitChange()"
            @change="validateValue(localValue, param); emitChange()"
        />
        <span v-if="showDefaultValue(param) && !validationError" class="glass-settings-hint">
          {{ showDefaultValue(param) }}
        </span>
      </div>

      <div v-else class="relative w-full max-w-[12rem]">
        <input
            type="text"
            v-model="localValue"
            :disabled="disabled"
            class="w-full text-sm"
            :class="controlClass"
            @change="emitChange"
        />
      </div>

      <InfoPopover v-if="compact" :text="param.ui_description"/>
    </div>

    <p v-if="validationError" class="glass-settings-hint !not-italic text-red-600">
      {{ validationError }}
    </p>
  </div>
</template>

<style scoped>
.param-field__number {
  padding-right: 1.65rem;
}

.param-field__control--changed {
  border-color: color-mix(in srgb, #b45309 45%, var(--color-border));
  background: color-mix(in srgb, #b45309 10%, transparent);
}

.param-field__control--invalid {
  border-color: color-mix(in srgb, #dc2626 45%, var(--color-border));
  background: color-mix(in srgb, #dc2626 8%, transparent);
}
</style>
