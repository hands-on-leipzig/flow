<script setup>
import {ref, computed} from 'vue'

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
    <label class="block font-medium mb-2">Teamverteilung</label>

    <div
        ref="barRef"
        class="relative h-10 bg-gray-100 rounded border border-gray-300 overflow-hidden cursor-ew-resize"
        @mousedown="startDrag"
    >
      <div
          class="absolute top-0 left-0 h-full flex items-center justify-start pl-2 text-xs text-gray-800"
          :style="{ width: `${e1Percent}%` }"
      >
        {{ e1 }} Team{{ e1 === 1 ? '' : 's' }}
      </div>

      <div
          class="absolute top-0 right-0 h-full flex items-center justify-end pr-2 text-xs text-gray-800"
          :style="{ width: `${100 - e1Percent}%` }"
      >
        {{ e2 }} Team{{ e2 === 1 ? '' : 's' }}
      </div>

      <div
          class="absolute top-0 h-full w-[2px] bg-gray-500 opacity-70"
          :style="{ left: `${e1Percent}%` }"
      ></div>
    </div>
  </div>
</template>
