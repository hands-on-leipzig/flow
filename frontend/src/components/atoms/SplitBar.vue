<script setup>
import {ref, computed} from 'vue'
import IconSunrise from '@/components/icons/IconSunrise.vue'
import IconSunset from '@/components/icons/IconSunset.vue'

const props = defineProps({
  total: {type: Number, required: true},
  e1: {type: Number, required: true},
  e2: {type: Number, required: true}
})
const emit = defineEmits(['update:e1', 'update:e2'])

const barRef = ref(null)
const isDragging = ref(false)

const e1Percent = computed(() => props.total ? (props.e1 / props.total) * 100 : 0)

function startDrag(e) {
  isDragging.value = true
  document.addEventListener('mousemove', onDrag)
  document.addEventListener('mouseup', stopDrag)
  onDrag(e)
}

function stopDrag() {
  isDragging.value = false
  document.removeEventListener('mousemove', onDrag)
  document.removeEventListener('mouseup', stopDrag)
}

function onDrag(e) {
  if (!isDragging.value || !barRef.value || !props.total) return
  
  const rect = barRef.value.getBoundingClientRect()
  const offsetX = e.clientX - rect.left
  const ratio = Math.max(0, Math.min(offsetX / rect.width, 1))
  const newE1 = Math.round(ratio * props.total)
  
  emit('update:e1', newE1)
  emit('update:e2', props.total - newE1)
}
</script>

<template>
  <div class="w-full max-w-xl select-none">
    <label class="block font-medium mb-3 text-gray-700">Teamverteilung</label>

    <div class="flex items-center gap-3">
      <div class="flex flex-col items-center">
        <IconSunrise />
      </div>

      <div
          ref="barRef"
          class="flex-1 relative h-12 bg-gray-100 rounded-lg border border-gray-300 overflow-hidden cursor-ew-resize transition-all duration-200 hover:shadow-md hover:border-gray-400"
          :class="{ 'ring-2 ring-gray-400 ring-opacity-50': isDragging }"
          @mousedown="startDrag"
      >
        <div
            class="absolute top-0 left-0 h-full bg-gray-200 flex items-center justify-center text-gray-700 font-medium text-sm transition-all duration-200"
            :style="{ width: `${e1Percent}%` }"
        >
          <span v-if="e1 > 0">{{ e1 }} Team{{ e1 === 1 ? '' : 's' }}</span>
        </div>

        <div
            class="absolute top-0 right-0 h-full bg-gray-300 flex items-center justify-center text-gray-700 font-medium text-sm transition-all duration-200"
            :style="{ width: `${100 - e1Percent}%` }"
        >
          <span v-if="e2 > 0">{{ e2 }} Team{{ e2 === 1 ? '' : 's' }}</span>
        </div>

        <div
            class="absolute top-0 h-full w-1 bg-gray-500 transition-all duration-200"
            :style="{ left: `${e1Percent}%` }"
        >
          <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-6 h-6 bg-gray-500 rounded-full border-2 border-white shadow-sm flex items-center justify-center gap-1">
            <svg class="w-3.5 h-3.5 text-white" fill="currentColor" viewBox="0 0 12 12">
              <path d="M7 2L4 6l3 4"/>
            </svg>
            <svg class="w-3.5 h-3.5 text-white" fill="currentColor" viewBox="0 0 12 12">
              <path d="M5 2L8 6l-3 4"/>
            </svg>
          </div>
        </div>
        
        <div
            v-if="isDragging"
            class="absolute top-0 h-full w-1 bg-gray-600 shadow-lg"
            :style="{ left: `${e1Percent}%` }"
        ></div>
      </div>
      
      <div class="flex flex-col items-center">
        <IconSunset />
      </div>
    </div>
  </div>
</template>
