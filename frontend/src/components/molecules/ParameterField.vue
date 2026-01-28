<script setup>
import {ref, watch, computed} from 'vue'
import InfoPopover from "@/components/atoms/InfoPopover.vue";
import TimePicker from "@/components/atoms/TimePicker.vue";

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
const showInfo = ref(false)
const validationError = ref('')

const normalizeBoolean = (val) => val === 1 || val === true || val === '1'

// Initialisierung
const localValue = ref(
    props.param.type === 'boolean'
        ? normalizeBoolean(props.param.value)
        : props.param.value
)

// Synchronisierung bei Änderungen von außen
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
      // Normalize time format to show leading zero (9:00 -> 09:00)
      return normalizeTimeFormat(param.default_value)
    default:
      return param.default_value
  }
}

const isChangedFromDefault = (param) => {
  if (param.default_value === null || param.default_value === undefined) return false

  // Don't highlight team-related parameters as they're configuration, not parameter changes
  if (param.name && param.name.toLowerCase().includes('team')) return false

  switch (param.type) {
    case 'boolean':
      return localValue.value !== normalizeBoolean(param.default_value)
    case 'integer':
    case 'decimal':
      return Number(localValue.value) !== Number(param.default_value)
    case 'time':
      // Normalize both values to HH:MM format before comparing
      // Handles cases where one is "9:00" and the other is "09:00"
      const normalizedCurrent = normalizeTimeFormat(localValue.value)
      const normalizedDefault = normalizeTimeFormat(param.default_value)
      return normalizedCurrent !== normalizedDefault
    default:
      return localValue.value !== param.default_value
  }
}

function validateValue(value, param) {
  // Clear previous error
  validationError.value = ''

  // Special validation for time inputs (hh:mm format)
  if (param.type === 'time') {
    return validateTimeValue(value, param)
  }

  // Skip validation for non-numeric types
  if (param.type !== 'integer' && param.type !== 'decimal') {
    return true
  }

  const numericValue = Number(value)

  // Check if value is a valid number
  if (isNaN(numericValue)) {
    validationError.value = 'Ungültige Zahl'
    return false
  }

  // Validate minimum
  if (param.min !== null && param.min !== undefined && numericValue < param.min) {
    validationError.value = `Wert muss mindestens ${param.min} sein`
    return false
  }

  // Validate maximum
  if (param.max !== null && param.max !== undefined && numericValue > param.max) {
    validationError.value = `Wert darf höchstens ${param.max} sein`
    return false
  }

  // Validate step formula: value must be min + n * step
  if (param.step !== null && param.step !== undefined && param.step > 0) {
    const min = param.min ?? 0
    const step = param.step
    // For integers: check if (value - min) is divisible by step
    if ((numericValue - min) % step !== 0) {
      validationError.value = `Nur ${step}er-Schritte erlaubt`
      return false
    }
  }

  return true
}

/**
 * Converts time string (HH:MM) to minutes since midnight.
 */
function timeToMinutes(timeString) {
  if (!timeString || typeof timeString !== 'string') return 0
  const [hours, minutes] = timeString.split(':').map(Number)
  return (hours || 0) * 60 + (minutes || 0)
}

/**
 * Normalizes time string to HH:MM format (ensures leading zero for hours < 10).
 * Handles both "9:00" and "09:00" formats.
 */
function normalizeTimeFormat(timeString) {
  if (!timeString || typeof timeString !== 'string') return timeString
  const [hours, minutes] = timeString.split(':')
  if (!hours || !minutes) return timeString
  // Ensure hours have leading zero if needed, minutes should already have it
  const normalizedHours = hours.padStart(2, '0')
  const normalizedMinutes = minutes.padStart(2, '0')
  return `${normalizedHours}:${normalizedMinutes}`
}

function validateTimeValue(timeValue, param) {
  // Allow empty values during typing
  if (!timeValue || timeValue === '' || timeValue.trim() === '') {
    validationError.value = ''
    return true // Don't show error for empty input during typing
  }

  // Check if time format is valid (hh:mm)
  const timeRegex = /^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/
  if (!timeRegex.test(timeValue)) {
    validationError.value = 'Ungültiges Zeitformat (hh:mm)'
    return false
  }

  // Convert to minutes for comparison (matching backend logic)
  const valueMinutes = timeToMinutes(timeValue)

  // Validate minimum (if set)
  if (param.min !== null && param.min !== undefined && param.min !== '') {
    const minMinutes = timeToMinutes(param.min)
    if (valueMinutes < minMinutes) {
      validationError.value = `Zeit darf minimal ${param.min} sein`
      return false
    }
  }

  // Validate maximum (if set)
  if (param.max !== null && param.max !== undefined && param.max !== '') {
    const maxMinutes = timeToMinutes(param.max)
    if (valueMinutes > maxMinutes) {
      validationError.value = `Zeit darf maximal ${param.max} sein`
      return false
    }
  }

  // Validate step formula for time: minutes must be multiples of step
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

function toggleValue() {
  localValue.value = !localValue.value
  emitChange()
}

const isDefaultValue = computed(() => {
  if (props.param.default_value === null || props.param.default_value === undefined) return true

  switch (props.param.type) {
    case 'boolean':
      return localValue.value === normalizeBoolean(props.param.default_value)
    case 'integer':
    case 'decimal':
      return Number(localValue.value) === Number(props.param.default_value)
    default:
      return localValue.value === props.param.default_value
  }
})
</script>

<template>
  <div
      class="flex items-center px-4 py-1 space-x-4 w-full hover:bg-gray-50 transition-colors duration-150 rounded"
      :class="{ 
        'flex-col items-start space-x-0 space-y-1': !horizontal,
        'px-2 py-1': compact
      }"
  >
    <div v-if="withLabel && !compact" class="flex items-center min-w-[25rem]">
      <span class="font-medium">{{ param.ui_label }}</span>
      <InfoPopover :text="param.ui_description"/>
    </div>

    <div class="flex items-center gap-1">
      <!-- Number inputs with default value overlay -->
      <div v-if="param.type === 'integer' || param.type === 'decimal'" class="relative">
        <input
            type="number"
            :min="param.min"
            :max="param.max"
            :step="Number(param.step) || undefined"
            v-model="localValue"
            @change="emitChange"
            @input="validateValue(localValue, param)"
            :disabled="disabled"
            class="w-24 border rounded px-2 py-1 pr-8 text-sm shadow-sm"
            :class="{ 
              'opacity-50 cursor-not-allowed': disabled,
              'bg-orange-100 border-orange-300': isChangedFromDefault(param) && !disabled,
              'border-red-300 bg-red-50': validationError,
              'border-gray-300': !validationError
            }"
        />
        <span v-if="showDefaultValue(param) && !validationError"
              class="absolute right-2 top-1/2 transform -translate-y-1/2 text-xs text-gray-400 pointer-events-none">
          {{ showDefaultValue(param) }}
        </span>
        <!-- Validation error tooltip -->
        <div v-if="validationError"
             class="absolute right-2 top-1/2 transform -translate-y-1/2 text-xs text-red-500 pointer-events-none">
          ⚠️
        </div>
      </div>

      <!-- Boolean inputs - Fancy toggle -->
      <div v-else-if="param.type === 'boolean'" class="flex items-center justify-center">
        <div class="relative flex border border-gray-300 rounded overflow-hidden w-24"
             :class="{
               'border-gray-300': isDefaultValue,
               'border-orange-500': !isDefaultValue,
               'opacity-50 cursor-not-allowed': disabled
             }">
          <!-- Ja button -->
          <button
              type="button"
              @click="!disabled && !localValue && toggleValue()"
              class="px-2 py-1 text-sm transition-all duration-150 flex-1"
              :class="{
                'bg-white text-black': !localValue && isDefaultValue,
                'bg-gray-200 text-gray-600': localValue && isDefaultValue,
                'text-black': !localValue && !isDefaultValue,
                'bg-orange-100 text-gray-600': localValue && !isDefaultValue,
                'cursor-not-allowed': disabled || localValue
              }"
          >
            Ja
          </button>

          <!-- Vertical separator -->
          <div class="w-px bg-gray-300"></div>

          <!-- Nein button -->
          <button
              type="button"
              @click="!disabled && localValue && toggleValue()"
              class="px-2 py-1 text-sm transition-all duration-150 flex-1"
              :class="{
                'bg-gray-200 text-gray-600': !localValue && isDefaultValue,
                'bg-white text-black': localValue && isDefaultValue,
                'bg-orange-100 text-gray-600': !localValue && !isDefaultValue,
                'text-black': localValue && !isDefaultValue,
                'cursor-not-allowed': disabled || !localValue
              }"
          >
            Nein
          </button>
        </div>
      </div>

      <!-- Date inputs with default value overlay -->
      <div v-else-if="param.type === 'date'" class="relative">
        <input
            type="date"
            v-model="localValue"
            @change="emitChange"
            :disabled="disabled"
            class="w-24 border border-gray-300 rounded px-2 py-1 pr-8 text-sm shadow-sm"
            :class="{ 
              'opacity-50 cursor-not-allowed': disabled,
              'bg-orange-100 border-orange-300': isChangedFromDefault(param) && !disabled
            }"
        />
        <span v-if="showDefaultValue(param)"
              class="absolute right-2 top-1/2 transform -translate-y-1/2 text-xs text-gray-400 pointer-events-none">
          {{ showDefaultValue(param) }}
        </span>
      </div>

      <!-- Time inputs with custom time picker -->
      <div v-else-if="param.type === 'time'" class="relative flex items-center gap-2">
        <div
            class="inline-block"
            :class="{
            'opacity-50': disabled,
            'ring-2 ring-orange-300 rounded': isChangedFromDefault(param) && !disabled,
            'ring-2 ring-red-300 rounded': validationError
          }"
        >
          <TimePicker
              :model-value="localValue"
              @update:model-value="localValue = $event; validateValue(localValue, param); emitChange()"
              @change="validateValue(localValue, param); emitChange()"
              :disabled="disabled"
              :min="param.min || undefined"
              :max="param.max || undefined"
              :step="Number(param.step) || 5"
          />
        </div>
        <span v-if="showDefaultValue(param) && !validationError"
              class="text-xs text-gray-400 pointer-events-none whitespace-nowrap">
          {{ showDefaultValue(param) }}
        </span>
        <!-- Validation error indicator -->
        <span v-if="validationError"
              class="text-xs text-red-500 pointer-events-none">
          ⚠️
        </span>
      </div>

      <!-- Text inputs with default value overlay -->
      <div v-else class="relative">
        <input
            type="text"
            v-model="localValue"
            @change="emitChange"
            :disabled="disabled"
            class="w-24 border border-gray-300 rounded px-2 py-1 pr-8 text-sm shadow-sm"
            :class="{ 
              'opacity-50 cursor-not-allowed': disabled,
              'bg-orange-100 border-orange-300': isChangedFromDefault(param) && !disabled
            }"
        />
        <span v-if="showDefaultValue(param)"
              class="absolute right-2 top-1/2 transform -translate-y-1/2 text-xs text-gray-400 pointer-events-none">
          {{ showDefaultValue(param) }}
        </span>
      </div>

      <!-- Info button for compact mode -->
      <InfoPopover v-if="compact" :text="param.ui_description"/>
    </div>

    <!-- Validation error message -->
    <div v-if="validationError" class="text-xs text-red-600 mt-1 ml-4">
      {{ validationError }}
    </div>
  </div>
</template>


<style scoped>

</style>