<script setup lang="ts">
import {computed, ref} from 'vue'
import {useAnchoredPanel} from '@/composables/useAnchoredPanel'
import {useInfoPopover} from '@/composables/useInfoPopover'

const props = defineProps<{ text?: string | null }>()
const {toggle, isOpen, close} = useInfoPopover()

const popoverId = `info-popover-${Math.random().toString(36).slice(2, 11)}`
const open = computed(() => isOpen(popoverId))
const buttonRef = ref<HTMLElement | null>(null)

const {panelRef, panelStyle} = useAnchoredPanel({
  isOpen: open,
  anchor: buttonRef,
  fallbackWidth: 256,
  fallbackHeight: 80,
  onClose: close,
})

function handleToggle() {
  toggle(popoverId)
}
</script>

<template>
  <span v-if="props.text" class="inline-block info-popover">
    <button
        ref="buttonRef"
        type="button"
        class="ml-1 text-[var(--color-text-subtle)] hover:text-blue-600 align-middle"
        title="Mehr Informationen"
        @click.stop="handleToggle"
    >
      ⓘ
    </button>

    <Teleport to="body">
      <div
          v-if="open"
          ref="panelRef"
          class="info-popover info-popover__panel"
          :style="panelStyle"
          role="tooltip"
      >
        {{ props.text }}
      </div>
    </Teleport>
  </span>
</template>

<style scoped>
.info-popover__panel {
  z-index: 4000;
  width: 16rem;
  max-width: calc(100vw - 1rem);
  padding: 0.5rem 0.65rem;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  background: #fff;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
  font-size: 0.875rem;
  line-height: 1.4;
  color: var(--color-text-muted);
  white-space: pre-wrap;
}
</style>
