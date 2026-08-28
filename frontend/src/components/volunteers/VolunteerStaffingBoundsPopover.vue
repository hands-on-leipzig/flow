<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {useAnchoredPanel} from '@/composables/useAnchoredPanel'
import {showGlassToast} from '@/composables/useGlassToast'

export type StaffingRoleBounds = {
  label: string
  min: number
  best: number
  max: number
}

const props = defineProps<{
  role: StaffingRoleBounds | null
  anchor: HTMLElement | null
  saving?: boolean
}>()

const emit = defineEmits<{
  close: []
  save: [bounds: {min: number; best: number; max: number}]
}>()

const draft = ref({min: 1, best: 1, max: 1})

const isOpen = computed(() => !!props.role && !!props.anchor)

const {panelRef, panelStyle} = useAnchoredPanel({
  isOpen,
  anchor: computed(() => props.anchor),
  align: 'right',
  fallbackWidth: 320,
  fallbackHeight: 220,
  onClose: () => emit('close'),
})

watch(
  () => props.role,
  (role) => {
    if (!role) return
    draft.value = {
      min: Number(role.min),
      best: Number(role.best),
      max: Number(role.max),
    }
  },
  {immediate: true},
)

function boundsValidationError(min: number, best: number, max: number) {
  if (!Number.isInteger(min) || !Number.isInteger(best) || !Number.isInteger(max)) {
    return 'Bitte min, ideal und max eintragen.'
  }
  if (min < 1 || best < 1 || max < 1) {
    return 'min, ideal und max müssen mindestens 1 sein.'
  }
  if (min > best || best > max) {
    return 'Es muss min ≤ ideal ≤ max gelten.'
  }
  return null
}

function save() {
  const min = Number(draft.value.min)
  const best = Number(draft.value.best)
  const max = Number(draft.value.max)
  const validationError = boundsValidationError(min, best, max)
  if (validationError) {
    showGlassToast(validationError, 'info')
    return
  }
  emit('save', {min, best, max})
}
</script>

<template>
  <Teleport to="body">
    <div
        v-if="role"
        ref="panelRef"
        class="glass-modal staffing-bounds-modal staffing-bounds-popover"
        :style="panelStyle"
        @click.stop
    >
      <h3 class="staffing-bounds-modal__title">Besetzung bearbeiten</h3>
      <p class="staffing-bounds-modal__role">{{ role.label }}</p>
      <div class="staffing-bounds staffing-bounds--modal">
        <label class="staffing-bounds__field">
          <span>min</span>
          <input
              v-model.number="draft.min"
              class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
              type="number"
              min="1"
          >
        </label>
        <label class="staffing-bounds__field">
          <span>ideal</span>
          <input
              v-model.number="draft.best"
              class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
              type="number"
              min="1"
          >
        </label>
        <label class="staffing-bounds__field">
          <span>max</span>
          <input
              v-model.number="draft.max"
              class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
              type="number"
              min="1"
          >
        </label>
      </div>
      <p class="item-card__hint">min ≤ ideal ≤ max — wie viele Personen diese Rolle braucht.</p>
      <div class="staffing-bounds-modal__actions">
        <button type="button" class="glass-btn-secondary" :disabled="saving" @click="emit('close')">
          Abbrechen
        </button>
        <button type="button" class="glass-btn-accent" :disabled="saving" @click="save">
          Speichern
        </button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.staffing-bounds-modal {
  z-index: 1200;
  width: min(18rem, calc(100vw - 1rem));
  padding: 0.85rem 1rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-popover-fill);
  backdrop-filter: blur(var(--liquid-popover-blur));
  box-shadow: var(--shadow-lg);
}

.staffing-bounds-modal__title {
  margin: 0 0 0.35rem;
  font-size: 0.875rem;
  font-weight: 600;
}

.staffing-bounds-modal__role {
  margin: 0 0 0.75rem;
  font-size: 0.8125rem;
  color: var(--color-text-muted);
}

.staffing-bounds {
  display: flex;
  gap: 0.5rem;
}

.staffing-bounds__field {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  flex: 1;
  min-width: 0;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.staffing-bounds__input {
  width: 100%;
}

.staffing-bounds-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.85rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--color-border);
}
</style>
