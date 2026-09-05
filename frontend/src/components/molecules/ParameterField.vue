<script setup>
import {ref, watch, computed} from 'vue'
import InfoPopover from '@/components/atoms/InfoPopover.vue'
import {isParameterChangedFromDefault} from '@/utils/parameterDefault'

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
  },
  showInfo: {
    type: Boolean,
    default: true,
  },
  offDisabled: {
    type: Boolean,
    default: false,
  },
  onDisabled: {
    type: Boolean,
    default: false,
  },
})
const emit = defineEmits(['update'])
const validationError = ref('')

const normalizeBoolean = (val) => val === 1 || val === true || val === '1'

const localValue = ref(
    props.param.type === 'boolean'
        ? normalizeBoolean(props.param.value)
        : props.param.type === 'time'
            ? (normalizeTimeFormat(props.param.value) || props.param.value)
            : props.param.value
)

watch(() => props.param.value, val => {
  if (props.param.type === 'boolean') {
    localValue.value = normalizeBoolean(val)
    return
  }
  if (props.param.type === 'time') {
    localValue.value = normalizeTimeFormat(val) || val
    return
  }
  localValue.value = val
})

const hasDefaultValue = (param) =>
    param.default_value !== null && param.default_value !== undefined && param.default_value !== ''

const showDefaultValue = (param) => {
  switch (param.type) {
    case 'boolean':
      return normalizeBoolean(param.default_value) ? 'ja' : 'nein'
    case 'time':
      return normalizeTimeFormat(param.default_value)
    default:
      return param.default_value
  }
}

const isChangedFromDefault = (param) =>
  isParameterChangedFromDefault({...param, value: localValue.value})

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

function minutesToTime(totalMinutes) {
  const hours = Math.floor(totalMinutes / 60)
  const minutes = totalMinutes % 60
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
}

function normalizeTimeFormat(timeString) {
  if (!timeString || typeof timeString !== 'string') return timeString
  const [hours, minutes] = timeString.split(':')
  if (!hours || !minutes) return timeString
  return `${hours.padStart(2, '0')}:${minutes.padStart(2, '0')}`
}

/** Round up to the next step multiple (generator grid). Already aligned values stay put. */
function roundTimeUpToStep(timeString, stepMinutes) {
  const normalized = normalizeTimeFormat(timeString)
  if (!normalized || typeof normalized !== 'string') return timeString
  if (!Number.isFinite(stepMinutes) || stepMinutes <= 0) return normalized

  const mins = timeToMinutes(normalized)
  const rem = mins % stepMinutes
  if (rem === 0) return normalized

  const rounded = mins + (stepMinutes - rem)
  if (rounded >= 24 * 60) {
    return minutesToTime(Math.floor((24 * 60 - 1) / stepMinutes) * stepMinutes)
  }
  return minutesToTime(rounded)
}

function timeStepMinutes(param) {
  const step = Number(param?.step)
  return Number.isFinite(step) && step > 0 ? step : 5
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

  return true
}

function emitChange() {
  if (props.param.type === 'time' && localValue.value) {
    const rounded = roundTimeUpToStep(localValue.value, timeStepMinutes(props.param))
    if (rounded && rounded !== localValue.value) {
      localValue.value = rounded
    }
  }
  if (validateValue(localValue.value, props.param)) {
    emit('update', {...props.param, value: localValue.value})
  }
}

function defaultLocalValue(param) {
  switch (param.type) {
    case 'boolean':
      return normalizeBoolean(param.default_value)
    case 'time':
      return normalizeTimeFormat(param.default_value) || param.default_value
    default:
      return param.default_value
  }
}

function resetToDefault() {
  if (props.disabled || !hasDefaultValue(props.param)) return
  if (!isChangedFromDefault(props.param)) return

  const next = defaultLocalValue(props.param)
  if (props.param.type === 'boolean') {
    if (next && props.onDisabled) return
    if (!next && props.offDisabled) return
  }

  localValue.value = next
  emitChange()
}

function onDefaultKeydown(event) {
  if (event.key !== 'Enter' && event.key !== ' ') return
  event.preventDefault()
  resetToDefault()
}

const canResetToDefault = computed(() =>
  !props.disabled
  && hasDefaultValue(props.param)
  && isChangedFromDefault(props.param)
)

function setBoolean(value) {
  if (props.disabled || localValue.value === value) return
  if (value && props.onDisabled) return
  if (!value && props.offDisabled) return
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

const timeStepSeconds = computed(() => {
  const minutes = Number(props.param?.step)
  return (Number.isFinite(minutes) && minutes > 0 ? minutes : 5) * 60
})
</script>

<template>
  <div
      class="param-field min-w-0"
      :class="compact ? '' : 'flex flex-col gap-1.5 w-full'"
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
        <span
            v-if="hasDefaultValue(param) && !validationError"
            class="glass-settings-hint"
            :class="{
              'param-field__default--changed': (param.type === 'integer' || param.type === 'decimal') && isChangedFromDefault(param),
              'param-field__default--resettable': canResetToDefault,
            }"
            :role="canResetToDefault ? 'button' : undefined"
            :tabindex="canResetToDefault ? 0 : undefined"
            :title="canResetToDefault ? 'Auf Standardwert zurücksetzen' : undefined"
            @click="resetToDefault"
            @keydown="onDefaultKeydown"
        >
          {{ showDefaultValue(param) }}
        </span>
      </div>

      <div v-else-if="param.type === 'boolean'" class="flex items-center gap-2">
        <div class="flex gap-1.5">
          <button
              type="button"
              class="glass-choice whitespace-nowrap"
              :class="localValue ? 'glass-choice--active' : ''"
              :disabled="disabled || onDisabled"
              @click="setBoolean(true)"
          >
            ja
          </button>
          <button
              type="button"
              class="glass-choice whitespace-nowrap"
              :class="!localValue ? 'glass-choice--active' : ''"
              :disabled="disabled || offDisabled"
              @click="setBoolean(false)"
          >
            nein
          </button>
        </div>
        <span
            v-if="hasDefaultValue(param)"
            class="glass-settings-hint"
            :class="{
              'param-field__default--changed': isChangedFromDefault(param),
              'param-field__default--resettable': canResetToDefault,
            }"
            :role="canResetToDefault ? 'button' : undefined"
            :tabindex="canResetToDefault ? 0 : undefined"
            :title="canResetToDefault ? 'Auf Standardwert zurücksetzen' : undefined"
            @click="resetToDefault"
            @keydown="onDefaultKeydown"
        >
          {{ showDefaultValue(param) }}
        </span>
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

      <div v-else-if="param.type === 'time'" class="flex items-center gap-2" :class="compact ? '' : 'flex-wrap'">
        <input
            v-model="localValue"
            :disabled="disabled"
            :max="param.max || '23:55'"
            :min="param.min || '00:05'"
            :step="timeStepSeconds"
            class="param-field__time"
            :class="controlClass"
            type="time"
            @change="emitChange"
            @input="validateValue(localValue, param)"
        />
        <span
            v-if="showDefaultValue(param) && !validationError"
            class="glass-settings-hint"
            :class="{
              'param-field__default--changed': isChangedFromDefault(param),
              'param-field__default--resettable': canResetToDefault,
            }"
            :role="canResetToDefault ? 'button' : undefined"
            :tabindex="canResetToDefault ? 0 : undefined"
            :title="canResetToDefault ? 'Auf Standardwert zurücksetzen' : undefined"
            @click="resetToDefault"
            @keydown="onDefaultKeydown"
        >
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

      <InfoPopover v-if="compact && showInfo" :text="param.ui_description"/>
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

.param-field__time {
  width: 7.25rem;
}

.param-field__control--changed {
  border-color: color-mix(in srgb, #b45309 45%, var(--color-border));
  background: color-mix(in srgb, #b45309 10%, transparent);
}

.param-field__default--changed {
  padding: 0.1rem 0.4rem;
  border-radius: var(--radius);
  border: 1px solid color-mix(in srgb, #b45309 35%, var(--color-border));
  background: color-mix(in srgb, #b45309 12%, var(--color-bg-muted));
  color: var(--color-text);
  font-style: normal;
}

.param-field__default--resettable {
  cursor: pointer;
}

.param-field__default--resettable:hover {
  border-color: color-mix(in srgb, #b45309 55%, var(--color-border));
  background: color-mix(in srgb, #b45309 18%, var(--color-bg-muted));
}

.param-field__default--resettable:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
  outline-offset: 2px;
}

.param-field__control--invalid {
  border-color: color-mix(in srgb, #dc2626 45%, var(--color-border));
  background: color-mix(in srgb, #dc2626 8%, transparent);
}
</style>
