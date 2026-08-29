<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, ref, watch} from 'vue'

const GAP_PX = 6
const PANEL_WIDTH_PX = 264

const props = defineProps<{
  /** Effective capacity currently shown (override or DRAHT). */
  capacity: number
  min: number
  max: number
}>()

const emit = defineEmits<{
  (e: 'apply', value: number): void
}>()

const open = ref(false)
const draft = ref('')
const triggerRef = ref<HTMLElement | null>(null)
const panelRef = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)
const panelStyle = ref<Record<string, string>>({})

const minBound = computed(() => Math.min(props.min, props.max))
const maxBound = computed(() => Math.max(props.min, props.max))

const parsed = computed(() => {
  const n = Number(draft.value)
  return Number.isFinite(n) ? Math.round(n) : NaN
})

const valid = computed(() => {
  const n = parsed.value
  return Number.isFinite(n) && n >= minBound.value && n <= maxBound.value
})

const errorText = computed(() => {
  if (draft.value.trim() === '') return `Wert zwischen ${minBound.value} und ${maxBound.value} eingeben.`
  if (!Number.isFinite(parsed.value)) return 'Bitte eine ganze Zahl eingeben.'
  if (parsed.value < minBound.value || parsed.value > maxBound.value) {
    return `Erlaubt: ${minBound.value}–${maxBound.value}.`
  }
  return ''
})

function initialDraftValue(): number {
  const current = Number(props.capacity)
  if (!Number.isFinite(current) || current <= 0) return minBound.value
  const rounded = Math.round(current)
  if (rounded < minBound.value) return minBound.value
  if (rounded > maxBound.value) return maxBound.value
  return rounded
}

function updatePanelPosition() {
  const trigger = triggerRef.value
  if (!trigger) return
  const rect = trigger.getBoundingClientRect()
  const accent = getComputedStyle(trigger).getPropertyValue('--program-accent').trim()
  const width = Math.min(PANEL_WIDTH_PX, window.innerWidth - 16)
  let left = Math.round(rect.right - width)
  left = Math.max(8, Math.min(left, window.innerWidth - width - 8))
  let top = Math.round(rect.bottom + GAP_PX)
  const panel = panelRef.value
  const height = panel?.offsetHeight || 220
  if (top + height > window.innerHeight - 8) {
    top = Math.max(8, Math.round(rect.top - height - GAP_PX))
  }
  panelStyle.value = {
    left: `${left}px`,
    top: `${top}px`,
    width: `${width}px`,
    ...(accent ? {'--program-accent': accent} : {}),
  }
}

async function openDialog() {
  open.value = true
  draft.value = String(initialDraftValue())
  await nextTick()
  updatePanelPosition()
  await nextTick()
  inputRef.value?.focus()
  inputRef.value?.select()
}

function closeDialog() {
  open.value = false
}

function toggle() {
  if (open.value) closeDialog()
  else void openDialog()
}

function apply() {
  if (!valid.value) return
  emit('apply', parsed.value)
  closeDialog()
}

function eventPathContains(e: Event, el: HTMLElement | null): boolean {
  if (!el) return false
  const path = typeof e.composedPath === 'function' ? e.composedPath() : []
  if (path.includes(el)) return true
  const target = e.target
  return target instanceof Node && el.contains(target)
}

function onDocPointerDown(e: PointerEvent) {
  if (!open.value) return
  if (eventPathContains(e, triggerRef.value)) return
  if (eventPathContains(e, panelRef.value)) return
  closeDialog()
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    e.stopPropagation()
    closeDialog()
  } else if (e.key === 'Enter') {
    e.preventDefault()
    apply()
  }
}

function onReposition() {
  if (!open.value) return
  updatePanelPosition()
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocPointerDown, true)
    window.addEventListener('resize', onReposition)
    window.addEventListener('scroll', onReposition, true)
  } else {
    document.removeEventListener('pointerdown', onDocPointerDown, true)
    window.removeEventListener('resize', onReposition)
    window.removeEventListener('scroll', onReposition, true)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocPointerDown, true)
  window.removeEventListener('resize', onReposition)
  window.removeEventListener('scroll', onReposition, true)
})
</script>

<template>
  <div class="capacity-override">
    <button
        ref="triggerRef"
        type="button"
        class="capacity-override__trigger"
        title="Kapazität testweise übersteuern"
        aria-label="Kapazität testweise übersteuern"
        :aria-expanded="open"
        @click.stop="toggle"
    >
      <i class="bi bi-sliders" aria-hidden="true"/>
    </button>

    <Teleport to="body">
      <div
          v-if="open"
          ref="panelRef"
          class="capacity-override__panel liquid-surface-inner"
          role="dialog"
          aria-label="Kapazität testweise übersteuern"
          :style="panelStyle"
          @keydown="onKeydown"
      >
        <p class="capacity-override__title">Kapazität testweise übersteuern.</p>
        <label class="capacity-override__field">
          <span class="sr-only">Kapazität</span>
          <input
              ref="inputRef"
              v-model="draft"
              class="capacity-override__input"
              type="number"
              inputmode="numeric"
              :min="minBound"
              :max="maxBound"
              step="1"
              @keydown="onKeydown"
          />
        </label>
        <p v-if="errorText && !valid" class="capacity-override__error">{{ errorText }}</p>
        <p class="capacity-override__note">
          Beim nächsten Öffnen dieser Seite wird wieder der offizielle Wert genommen.<br>
          Um den zu Ändern, bitte in der Geschäftsstelle melden.
        </p>
        <div class="capacity-override__actions">
          <button type="button" class="glass-btn-secondary !px-2.5 !py-1 !text-xs" @click="closeDialog">
            Abbrechen
          </button>
          <button
              type="button"
              class="glass-btn-accent !px-2.5 !py-1 !text-xs"
              :disabled="!valid"
              @click="apply"
          >
            Übernehmen
          </button>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.capacity-override {
  position: relative;
  flex-shrink: 0;
}

.capacity-override__trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  margin: 0;
  padding: 0;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  border-radius: var(--radius);
  background: color-mix(in srgb, var(--color-bg) 80%, transparent);
  color: var(--color-text-muted);
  cursor: pointer;
  transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}

.capacity-override__trigger:hover {
  color: var(--color-text);
  border-color: color-mix(in srgb, var(--program-accent, var(--color-accent)) 45%, var(--color-border));
  background: color-mix(in srgb, var(--program-accent, var(--color-accent)) 8%, #fff);
}

.capacity-override__trigger:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--program-accent, var(--color-accent)) 45%, transparent);
  outline-offset: 2px;
}

.capacity-override__panel {
  position: fixed;
  z-index: 4000;
  max-width: calc(100vw - 1rem);
  padding: 0.75rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: #fff;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
}

.capacity-override__title {
  margin: 0 0 0.55rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text);
  line-height: 1.3;
}

.capacity-override__field {
  display: block;
  width: fit-content;
}

.capacity-override__input {
  width: 4.25rem;
  box-sizing: border-box;
  padding: 0.35rem 0.45rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-bg, #fff);
  color: var(--color-text);
  font-size: 0.9rem;
  font-variant-numeric: tabular-nums;
  text-align: right;
  /* Native spin buttons break outside-click handling in some browsers. */
  -moz-appearance: textfield;
  appearance: textfield;
}

.capacity-override__input::-webkit-outer-spin-button,
.capacity-override__input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.capacity-override__input:focus {
  outline: 2px solid color-mix(in srgb, var(--program-accent, var(--color-accent)) 40%, transparent);
  outline-offset: 1px;
}

.capacity-override__error {
  margin: 0.35rem 0 0;
  font-size: 0.75rem;
  color: #dc2626;
  line-height: 1.3;
}

.capacity-override__note {
  margin: 0.55rem 0 0;
  font-size: 0.75rem;
  color: var(--color-text-muted);
  line-height: 1.35;
}

.capacity-override__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
  margin-top: 0.65rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
