<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    default: ''
  },
  min: {
    type: String,
    default: null
  },
  max: {
    type: String,
    default: null
  },
  step: {
    type: Number,
    default: 5
  },
  disabled: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue', 'change'])

// Parse time string (HH:MM) to hours and minutes
function parseTime(timeStr) {
  if (!timeStr || timeStr === '') {
    return { hours: 12, minutes: 0 }
  }
  const [hours, minutes] = timeStr.split(':').map(Number)
  return { hours: hours || 12, minutes: minutes || 0 }
}

// Format hours and minutes to time string (HH:MM)
function formatTime(hours, minutes) {
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
}

// Round minutes to nearest step
function roundToStep(minutes, step) {
  return Math.round(minutes / step) * step
}

// Generate minute options based on step
function getMinuteOptions(step) {
  const options = []
  // Generate all 5-minute increments: 0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55
  for (let i = 0; i < 60; i += step) {
    options.push(i)
  }
  return options
}

const isOpen = ref(false)
const mode = ref('hours')
const hours = ref(12)
const minutes = ref(0)
const tempHours = ref(12)
const tempMinutes = ref(0)
const buttonRef = ref(null)
const dropdownRef = ref(null)
const clockRef = ref(null)
const dropdownStyle = ref({})

// Minute options - always generate all 12 options (00, 05, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55)
const minuteOptions = computed(() => {
  // Always use step 5 for minute options (5-minute increments)
  const options = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55]
  return options
})

// Display time
const displayTime = computed(() => {
  if (!props.modelValue || props.modelValue === '') {
    return '--:--'
  }
  const parsed = parseTime(props.modelValue)
  return formatTime(parsed.hours, parsed.minutes)
})

// Initialize from modelValue
function initialize() {
  const parsed = parseTime(props.modelValue)
  hours.value = parsed.hours
  minutes.value = roundToStep(parsed.minutes, props.step)
  tempHours.value = hours.value
  tempMinutes.value = minutes.value
}

// Open dropdown and adjust position
function openDropdown(event) {
  if (props.disabled) return
  
  // Prevent immediate closing when clicking the button
  event?.stopPropagation()
  
  initialize()
  mode.value = 'hours'
  isOpen.value = true
  
  // Adjust position after dropdown is rendered
  setTimeout(() => {
    adjustDropdownPosition()
  }, 0)
}

// Adjust dropdown position to stay within viewport
function adjustDropdownPosition() {
  if (!dropdownRef.value || !buttonRef.value) return
  
  const buttonRect = buttonRef.value.getBoundingClientRect()
  const dropdownRect = dropdownRef.value.getBoundingClientRect()
  const viewportWidth = window.innerWidth
  const viewportHeight = window.innerHeight
  const margin = 16 // 16px margin from screen edges
  
  let left = 0
  let top = 0
  let positionAbove = false
  
  // Check if dropdown would overflow bottom - position above button instead
  if (buttonRect.bottom + dropdownRect.height + margin > viewportHeight) {
    positionAbove = true
    // Position above button
    top = -(dropdownRect.height + 8) // 8px gap above button
  }
  
  // Check right edge - move left if dropdown would overflow
  const dropdownRight = positionAbove 
    ? buttonRect.left + dropdownRect.width
    : dropdownRect.right
    
  if (dropdownRight > viewportWidth - margin) {
    left = (viewportWidth - margin - dropdownRect.width) - buttonRect.left
  }
  // Check left edge - move right if dropdown would overflow
  else if (buttonRect.left + left < margin) {
    left = margin - buttonRect.left
  }
  
  // Check top edge if positioned above - move down if needed
  if (positionAbove && buttonRect.top - dropdownRect.height - 8 < margin) {
    top = margin - (buttonRect.bottom - dropdownRect.height)
  }
  
  dropdownStyle.value = {
    transform: `translate(${left}px, ${top}px)`
  }
}

// Close dropdown
function closeDropdown() {
  isOpen.value = false
}

// Apply time selection
function applyTime() {
  hours.value = tempHours.value
  minutes.value = tempMinutes.value
  const timeStr = formatTime(hours.value, minutes.value)
  emit('update:modelValue', timeStr)
  emit('change', timeStr)
  closeDropdown()
}

// Cancel and revert
function cancel() {
  initialize()
  closeDropdown()
}

// Get angle from click position (0-360, starting from top)
function getAngleFromClick(event, centerX, centerY) {
  const rect = clockRef.value.getBoundingClientRect()
  const x = event.clientX - rect.left - centerX
  const y = event.clientY - rect.top - centerY
  let angle = Math.atan2(y, x) * (180 / Math.PI) + 90
  if (angle < 0) angle += 360
  return angle
}

// Get distance from center
function getDistanceFromCenter(event, centerX, centerY) {
  const rect = clockRef.value.getBoundingClientRect()
  const x = event.clientX - rect.left - centerX
  const y = event.clientY - rect.top - centerY
  return Math.sqrt(x * x + y * y)
}

// Handle clock click - immediately update time
function handleClockClick(event) {
  if (!clockRef.value) return
  
  const centerX = 140
  const centerY = 140
  const angle = getAngleFromClick(event, centerX, centerY)
  const distance = getDistanceFromCenter(event, centerX, centerY)
  
  if (mode.value === 'hours') {
    // Outer circle (1-12): distance > 70
    // Inner circle (13-24, then 00): distance <= 70
    if (distance > 70) {
      // Outer circle: hours 1-12 (AM hours)
      let hour12 = Math.round(angle / 30)
      if (hour12 === 0) hour12 = 12
      if (hour12 > 12) hour12 = hour12 - 12
      
      // Convert to 24-hour format: 12 = 0 (midnight), 1-11 = 1-11 (AM)
      tempHours.value = hour12 === 12 ? 0 : hour12
    } else if (distance <= 70 && distance > 30) {
      // Inner circle: hours 13-24, then 00 (PM hours)
      let hour12 = Math.round(angle / 30)
      if (hour12 === 0) hour12 = 12
      if (hour12 > 12) hour12 = hour12 - 12
      
      // Convert to 24-hour format: 12 = 12 (noon), others = hour12 + 12 (PM)
      if (hour12 === 12) {
        tempHours.value = 12
      } else {
        tempHours.value = hour12 + 12
      }
    }
    
    // Update hours immediately and switch to minutes mode
    hours.value = tempHours.value
    const timeStr = formatTime(hours.value, minutes.value)
    emit('update:modelValue', timeStr)
    emit('change', timeStr)
    
    // Switch to minutes mode after selecting hour
    setTimeout(() => {
      mode.value = 'minutes'
    }, 300)
  } else {
    // Minutes mode: convert angle to minutes (starting from top = 0)
    const rawMinute = Math.round(angle / 6)
    const roundedMinute = roundToStep(rawMinute, props.step)
    tempMinutes.value = roundedMinute % 60
    
    // Update minutes immediately
    minutes.value = tempMinutes.value
    const timeStr = formatTime(hours.value, minutes.value)
    emit('update:modelValue', timeStr)
    emit('change', timeStr)
    
    // Close dropdown after selecting minutes
    setTimeout(() => {
      closeDropdown()
    }, 200)
  }
}

// Click outside to close
function handleClickOutside(event) {
  if (!isOpen.value) return
  
  // Don't close if clicking on the button or inside the dropdown
  if (buttonRef.value && buttonRef.value.contains(event.target)) {
    return
  }
  
  if (dropdownRef.value && dropdownRef.value.contains(event.target)) {
    return
  }
  
  // Use a small delay to ensure the click event from opening the dropdown has finished
  setTimeout(() => {
    if (isOpen.value) {
      closeDropdown()
    }
  }, 10)
}

// Watch for external changes
watch(() => props.modelValue, (newVal) => {
  if (!isOpen.value) {
    const parsed = parseTime(newVal)
    hours.value = parsed.hours
    minutes.value = roundToStep(parsed.minutes, props.step)
  }
})

// Watch for window resize to adjust position
watch(isOpen, (newVal) => {
  if (newVal) {
    window.addEventListener('resize', adjustDropdownPosition)
    window.addEventListener('scroll', adjustDropdownPosition, true)
  } else {
    window.removeEventListener('resize', adjustDropdownPosition)
    window.removeEventListener('scroll', adjustDropdownPosition, true)
  }
})

onMounted(() => {
  initialize()
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <div class="relative">
    <!-- Display Button -->
    <button
      ref="buttonRef"
      type="button"
      @click="openDropdown"
      :disabled="disabled"
      class="px-3 py-1.5 text-sm font-semibold border border-gray-300 rounded-md bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors min-w-[80px] text-center"
    >
      {{ displayTime }}
    </button>

    <!-- Dropdown with Circular Clock Picker -->
    <div
      v-if="isOpen"
      ref="dropdownRef"
      class="absolute top-full left-0 mt-2 bg-white rounded-lg shadow-lg z-50 p-6"
      :style="{
        boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
        ...dropdownStyle
      }"
    >
      <!-- Clock Face -->
      <div class="relative mb-6">
        <svg
          ref="clockRef"
          width="280"
          height="280"
          viewBox="0 0 280 280"
          class="cursor-pointer"
          @click="handleClockClick"
        >
          <!-- Hours Mode -->
          <template v-if="mode === 'hours'">
            <!-- Outer Circle: Hours 1-12 (AM hours: 1-11, 12 = 0) -->
            <g v-for="hour in 12" :key="hour" class="hour-marker-group">
              <!-- Invisible circle for hit area -->
              <circle
                :cx="140 + 95 * Math.cos((hour - 3) * 30 * Math.PI / 180)"
                :cy="140 + 95 * Math.sin((hour - 3) * 30 * Math.PI / 180)"
                r="20"
                fill="transparent"
                class="cursor-pointer"
              />
              <!-- Text with hover shadow effect -->
              <!-- Outer circle: hour 1-11 matches tempHours 1-11, hour 12 matches tempHours 0 -->
              <text
                :x="140 + 95 * Math.cos((hour - 3) * 30 * Math.PI / 180)"
                :y="140 + 95 * Math.sin((hour - 3) * 30 * Math.PI / 180) + 5"
                text-anchor="middle"
                :fill="(hour === 12 ? tempHours === 0 : tempHours === hour) && tempHours < 12 ? '#3b82f6' : '#111827'"
                :font-weight="(hour === 12 ? tempHours === 0 : tempHours === hour) && tempHours < 12 ? 'bold' : '600'"
                class="pointer-events-none hour-text"
                style="font-size: 14px; font-family: system-ui, -apple-system, sans-serif; filter: drop-shadow(0 0 0 transparent); transition: filter 0.2s;"
              >
                {{ hour }}
              </text>
            </g>
            
            <!-- Inner Circle: Hours 13-24, then 00 (PM hours: 13-23, 12 = 12, 0 = 0) -->
            <g v-for="(innerHour, index) in [13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 0]" :key="innerHour" class="hour-marker-group">
              <!-- Invisible circle for hit area -->
              <circle
                :cx="140 + 55 * Math.cos((index + 1 - 3) * 30 * Math.PI / 180)"
                :cy="140 + 55 * Math.sin((index + 1 - 3) * 30 * Math.PI / 180)"
                r="16"
                fill="transparent"
                class="cursor-pointer"
              />
              <!-- Text with hover shadow effect -->
              <!-- Inner circle: only highlight if it's actually the selected hour (13-23, 12, or 0) -->
              <text
                :x="140 + 55 * Math.cos((index + 1 - 3) * 30 * Math.PI / 180)"
                :y="140 + 55 * Math.sin((index + 1 - 3) * 30 * Math.PI / 180) + 4"
                text-anchor="middle"
                :fill="tempHours === innerHour ? '#3b82f6' : '#111827'"
                :font-weight="tempHours === innerHour ? 'bold' : '600'"
                class="pointer-events-none hour-text"
                style="font-size: 12px; font-family: system-ui, -apple-system, sans-serif; filter: drop-shadow(0 0 0 transparent); transition: filter 0.2s;"
              >
                {{ innerHour === 0 ? '00' : innerHour }}
              </text>
            </g>
          </template>
          
          <!-- Minutes Mode -->
          <template v-else>
            <!-- Minute markers (5-minute steps, starting from top) - ALL 12 minutes: 00, 05, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55 -->
            <g v-for="minute in minuteOptions" :key="`minute-${minute}`" class="minute-marker-group">
              <!-- Invisible circle for hit area -->
              <circle
                :cx="140 + 95 * Math.cos((minute - 15) * 6 * Math.PI / 180)"
                :cy="140 + 95 * Math.sin((minute - 15) * 6 * Math.PI / 180)"
                r="20"
                fill="transparent"
                class="cursor-pointer"
              />
              <!-- Text with hover shadow effect -->
              <text
                :x="140 + 95 * Math.cos((minute - 15) * 6 * Math.PI / 180)"
                :y="140 + 95 * Math.sin((minute - 15) * 6 * Math.PI / 180) + 6"
                text-anchor="middle"
                :fill="tempMinutes === minute ? '#3b82f6' : '#111827'"
                :font-weight="tempMinutes === minute ? 'bold' : '600'"
                class="pointer-events-none minute-text"
                style="font-size: 13px; font-family: system-ui, -apple-system, sans-serif; filter: drop-shadow(0 0 0 transparent); transition: filter 0.2s;"
              >
                {{ String(minute).padStart(2, '0') }}
              </text>
            </g>
          </template>
        </svg>
      </div>

      <!-- Mode Toggle / Back Button -->
      <div class="flex gap-2 mb-4">
        <button
          v-if="mode === 'minutes'"
          type="button"
          @click.stop="mode = 'hours'"
          class="flex-1 px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
        >
          ← Zurück zu Stunden
        </button>
        <div v-else class="flex-1"></div>
        <button
          type="button"
          @click.stop="closeDropdown"
          class="px-3 py-2 text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 rounded-md transition-colors"
        >
          Schließen
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.minute-marker-group:hover .minute-text,
.hour-marker-group:hover .hour-text {
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}
</style>
