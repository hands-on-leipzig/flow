<script setup lang="ts">
import {computed, onMounted, onBeforeUnmount} from 'vue'
import IconInfo from "@/components/icons/IconInfo.vue";
import { useInfoPopover } from '@/composables/useInfoPopover'

const props = defineProps<{ text?: string | null }>()
const { toggle, isOpen, close } = useInfoPopover()

// Generate a unique ID for this popover instance
const popoverId = `info-popover-${Math.random().toString(36).substr(2, 9)}`

const open = computed(() => isOpen(popoverId))

function handleToggle() {
  toggle(popoverId)
}

function handleClickOutside(e: MouseEvent) {
  const target = e.target as HTMLElement
  if (!target.closest('.info-popover')) {
    close()
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
  <span v-if="props.text" class="relative inline-block info-popover">
    <button
        type="button"
        class="ml-1 text-gray-500 hover:text-blue-600 align-middle"
        title="Mehr Informationen"
        @click.stop="handleToggle"
    >
      <!-- Your custom icon, or fallback: -->
      <!--<IconInfo class="h-4 w-4 relative -top-0.5"/>-->
      ⓘ
    </button>

    <div
        v-if="open"
        class="absolute left-0 mt-2 w-64 rounded-md bg-white p-2 shadow-lg border text-sm text-gray-700 z-10"
    >
      {{ props.text }}
    </div>
  </span>
</template>
