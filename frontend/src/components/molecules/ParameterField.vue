<script setup>
import {ref, watch, computed} from 'vue'
import InfoPopover from "@/components/atoms/InfoPopover.vue";

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
  console.log('Parameter:', param.name, 'current:', param.value, 'default:', param.default_value)
  switch (param.type) {
    case 'boolean':
      return normalizeBoolean(param.default_value) ? 'an' : 'aus'
    default:
      return param.default_value
  }
}

const isChangedFromDefault = (param) => {
  if (param.default_value === null || param.default_value === undefined) return false

  // Don't highlight time fields as they're configuration, not parameter changes
  if (param.type === 'time') return false

  // Don't highlight team-related parameters as they're configuration, not parameter changes
  if (param.name && param.name.toLowerCase().includes('team')) return false

  switch (param.type) {
    case 'boolean':
      return localValue.value !== normalizeBoolean(param.default_value)
    case 'integer':
    case 'decimal':
      return Number(localValue.value) !== Number(param.default_value)
    default:
      return localValue.value !== param.default_value
  }
}

function validateValue(value, param) {
  // Clear previous error
  validationError.value = ''
  
  // Special validation for time inputs (hh:mm format)
  if (param.type === 'time') {
    return validateTimeValue(value)
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

function validateTimeValue(timeValue) {
  // Check if time format is valid (hh:mm)
  const timeRegex = /^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/
  if (!timeRegex.test(timeValue)) {
    validationError.value = 'Ungültiges Zeitformat (hh:mm)'
    return false
  }
  
  // Extract minutes and check if they are multiples of 5
  const [, , minutes] = timeValue.match(timeRegex)
  const minutesNum = parseInt(minutes, 10)
  
  if (minutesNum % 5 !== 0) {
    validationError.value = 'Nur 5-Min-Schritte erlaubt.'
    return false
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
            :step="param.step"
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
                // When default value is selected: dark grey for selected, light grey for unselected
                'bg-gray-700 text-white': localValue && isDefaultValue,
                'bg-gray-200 text-gray-600': !localValue && isDefaultValue,
                // When different from default: orange for selected, light orange for unselected
                'bg-orange-100 text-orange-800': localValue && !isDefaultValue,
                'bg-orange-50 text-orange-600': !localValue && !isDefaultValue,
                'cursor-not-allowed': disabled || localValue,
                'opacity-60': localValue && !disabled
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
                // When default value is selected: dark grey for selected, light grey for unselected
                'bg-gray-700 text-white': !localValue && isDefaultValue,
                'bg-gray-200 text-gray-600': localValue && isDefaultValue,
                // When different from default: orange for selected, light orange for unselected
                'bg-orange-100 text-orange-800': !localValue && !isDefaultValue,
                'bg-orange-50 text-gray-600': localValue && !isDefaultValue,
                'cursor-not-allowed': disabled || !localValue,
                'opacity-60': !localValue && !disabled
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

      <!-- Time inputs without default value overlay -->
      <div v-else-if="param.type === 'time'" class="relative">
        <input
            type="time"
            v-model="localValue"
            @change="emitChange"
            @input="validateValue(localValue, param)"
            :disabled="disabled"
            class="w-24 border rounded px-2 py-1 text-sm shadow-sm"
            :class="{ 
              'opacity-50 cursor-not-allowed': disabled,
              'bg-orange-100 border-orange-300': isChangedFromDefault(param) && !disabled,
              'border-red-300 bg-red-50': validationError,
              'border-gray-300': !validationError
            }"
        />
        <!-- Validation error tooltip -->
        <div v-if="validationError" 
             class="absolute right-2 top-1/2 transform -translate-y-1/2 text-xs text-red-500 pointer-events-none">
          ⚠️
        </div>
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