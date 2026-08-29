<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, ref, watch} from 'vue'

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
const rootRef = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)

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

async function openDialog() {
  open.value = true
  draft.value = String(initialDraftValue())
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

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    e.stopPropagation()
    closeDialog()
  } else if (e.key === 'Enter') {
    e.preventDefault()
    apply()
  }
}

function onDocPointerDown(e: PointerEvent) {
  if (!open.value) return
  const root = rootRef.value
  if (root && !root.contains(e.target as Node)) closeDialog()
}

watch(open, (isOpen) => {
  if (isOpen) document.addEventListener('pointerdown', onDocPointerDown, true)
  else document.removeEventListener('pointerdown', onDocPointerDown, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocPointerDown, true)
})
</script>

<template>
  <div ref="rootRef" class="capacity-override">
    <button
        type="button"
        class="capacity-override__trigger"
        title="Kapazität testweise übersteuern"
        aria-label="Kapazität testweise übersteuern"
        :aria-expanded="open"
        @click.stop="toggle"
    >
      <i class="bi bi-sliders" aria-hidden="true"/>
    </button>

    <div
        v-if="open"
        class="capacity-override__panel liquid-surface-inner"
        role="dialog"
        aria-label="Kapazität testweise übersteuern"
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
  position: absolute;
  top: calc(100% + 0.4rem);
  right: 0;
  z-index: 40;
  width: 16.5rem;
  max-width: min(16.5rem, calc(100vw - 2rem));
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
  padding: 0.35rem 0.2rem 0.35rem 0.45rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-bg, #fff);
  color: var(--color-text);
  font-size: 0.9rem;
  font-variant-numeric: tabular-nums;
  text-align: right;
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
