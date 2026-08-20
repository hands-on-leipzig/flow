<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, onMounted, ref, watch} from 'vue'
import {useInfoPopover} from '@/composables/useInfoPopover'

const props = defineProps<{ text?: string | null }>()
const {toggle, isOpen, close} = useInfoPopover()

const popoverId = `info-popover-${Math.random().toString(36).slice(2, 11)}`
const open = computed(() => isOpen(popoverId))
const buttonRef = ref<HTMLElement | null>(null)
const panelRef = ref<HTMLElement | null>(null)
const panelStyle = ref<Record<string, string>>({
  position: 'fixed',
  top: '0',
  left: '0',
  visibility: 'hidden',
})

function handleToggle() {
  toggle(popoverId)
}

function placePanel() {
  const btn = buttonRef.value
  const panel = panelRef.value
  if (!btn || !panel) return

  const rect = btn.getBoundingClientRect()
  const width = panel.offsetWidth || 256
  const height = panel.offsetHeight || 80
  const margin = 8
  const vw = window.innerWidth
  const vh = window.innerHeight

  let top = rect.bottom + margin
  if (top + height > vh - margin && rect.top - height - margin >= margin) {
    top = rect.top - height - margin
  }
  top = Math.min(Math.max(top, margin), Math.max(margin, vh - margin - height))

  let left = rect.left
  if (left + width > vw - margin) left = vw - margin - width
  if (left < margin) left = margin

  panelStyle.value = {
    position: 'fixed',
    top: `${Math.round(top)}px`,
    left: `${Math.round(left)}px`,
    visibility: 'visible',
  }
}

watch(open, async (isOpenNow) => {
  if (!isOpenNow) return
  panelStyle.value = {
    position: 'fixed',
    top: '0',
    left: '0',
    visibility: 'hidden',
  }
  await nextTick()
  placePanel()
})

function handleClickOutside(e: MouseEvent) {
  const target = e.target as HTMLElement
  if (!target.closest('.info-popover')) close()
}

function onReposition() {
  if (open.value) placePanel()
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  window.addEventListener('resize', onReposition)
  window.addEventListener('scroll', onReposition, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
  window.removeEventListener('resize', onReposition)
  window.removeEventListener('scroll', onReposition, true)
  if (open.value) close()
})
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
