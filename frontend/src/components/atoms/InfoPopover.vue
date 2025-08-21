<script setup lang="ts">
import {ref, onMounted, onBeforeUnmount} from 'vue'
import IconInfo from "@/components/icons/IconInfo.vue";

const props = defineProps<{ text: string }>()
const open = ref(false)

function toggle() {
  open.value = !open.value
}

function handleClickOutside(e: MouseEvent) {
  const target = e.target as HTMLElement
  if (!target.closest('.info-popover')) {
    open.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <span class="relative inline-block info-popover">
    <button
        type="button"
        class="ml-1 text-gray-500 hover:text-blue-600 align-middle"
        title="Mehr Informationen"
        @click.stop="toggle"
    >
      <!-- Your custom icon, or fallback: -->
      <!--<IconInfo class="h-4 w-4 relative -top-0.5"/>-->
      ⓘ
    </button>

    <div
        v-if="open"
        class="absolute left-0 mt-2 w-64 rounded-md bg-white p-2 shadow-lg border text-sm text-gray-700 z-10"
    >
      {{ text }}
    </div>
  </span>
</template>
