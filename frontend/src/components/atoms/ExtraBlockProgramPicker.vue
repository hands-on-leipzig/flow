<script setup lang="ts">
import {computed, ref} from 'vue'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import StaffingScopeLeading from '@/components/volunteers/StaffingScopeLeading.vue'
import {useAnchoredPanel} from '@/composables/useAnchoredPanel'
import {useInfoPopover} from '@/composables/useInfoPopover'
import {programDisplayName, programId, type EventProgramRef} from '@/utils/eventPrograms'

const JOINT_FP = 0

const props = withDefaults(
  defineProps<{
    modelValue: number | null | undefined
    programs: EventProgramRef[]
    disabled?: boolean
    size?: 'sm' | 'md'
    /** Match height of glass-input--sm date/time fields on block rows */
    matchInputHeight?: boolean
  }>(),
  {
    disabled: false,
    size: 'sm',
    matchInputHeight: false,
  },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: number): void
}>()

const popoverId = `extra-block-program-${Math.random().toString(36).slice(2, 11)}`
const {toggle, isOpen, close} = useInfoPopover()
const open = computed(() => isOpen(popoverId))
const buttonRef = ref<HTMLElement | null>(null)

const {panelRef, panelStyle} = useAnchoredPanel({
  isOpen: open,
  anchor: buttonRef,
  fallbackWidth: 220,
  fallbackHeight: 160,
  onClose: close,
})

const normalizedValue = computed(() => {
  const raw = props.modelValue
  return raw == null ? JOINT_FP : Number(raw)
})

const scopeOptions = computed(() => {
  const programOptions = (props.programs || [])
    .map((program) => ({
      value: programId(program),
      program,
      label: programDisplayName(program) || program.name || `Programm ${programId(program)}`,
    }))
    .filter((row) => row.value > 0)

  return [
    {value: JOINT_FP, program: null as EventProgramRef | null, label: 'Übergreifend'},
    ...programOptions,
  ]
})

function handleToggle() {
  if (props.disabled) return
  toggle(popoverId)
}

function selectValue(value: number) {
  emit('update:modelValue', value)
  close()
}
</script>

<template>
  <div class="extra-block-program-picker" :class="`extra-block-program-picker--${size}`">
    <button
        ref="buttonRef"
        type="button"
        class="extra-block-program-picker__trigger"
        :class="{
          'extra-block-program-picker__trigger--disabled': disabled,
          'glass-input glass-input--sm liquid-surface-control extra-block-program-picker__trigger--field': matchInputHeight,
        }"
        :disabled="disabled"
        title="Programm-Bereich wählen"
        aria-label="Programm-Bereich wählen"
        @click.stop="handleToggle"
    >
      <span class="extra-block-program-picker__eye" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
          <path
              d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"
              stroke-linecap="round"
              stroke-linejoin="round"
          />
          <circle cx="12" cy="12" r="3"/>
        </svg>
      </span>
      <span v-if="normalizedValue === JOINT_FP" class="extra-block-program-picker__scope-icon" title="Übergreifend">
        <StaffingScopeLeading filter-key="cross" size="chip" :boxed="false"/>
      </span>
      <ProgramLogo
          v-else
          :program="normalizedValue"
          size="xs"
          class="extra-block-program-picker__logo"
      />
    </button>

    <Teleport to="body">
      <div
          v-if="open"
          ref="panelRef"
          class="extra-block-program-picker__panel glass-dropdown"
          :style="panelStyle"
          role="dialog"
          aria-label="Programm-Bereich"
          @click.stop
      >
        <fieldset class="extra-block-program-picker__fieldset">
          <legend class="extra-block-program-picker__legend">Sichtbar für</legend>
          <label
              v-for="option in scopeOptions"
              :key="option.value"
              class="extra-block-program-picker__option"
          >
            <input
                type="radio"
                name="extra-block-program-scope"
                :value="option.value"
                :checked="normalizedValue === option.value"
                @change="selectValue(option.value)"
            />
            <span v-if="option.value === JOINT_FP" class="extra-block-program-picker__scope-icon">
              <StaffingScopeLeading filter-key="cross" size="chip" :boxed="false"/>
            </span>
            <ProgramLogo
                v-else-if="option.program"
                :program="option.program"
                size="xs"
            />
            <span class="extra-block-program-picker__label">{{ option.label }}</span>
          </label>
        </fieldset>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.extra-block-program-picker {
  display: inline-flex;
  flex-shrink: 0;
}

.extra-block-program-picker__trigger:not(.extra-block-program-picker__trigger--field) {
  padding: 0.15rem 0.35rem;
  border-radius: 0.5rem;
  border: 1px solid var(--color-border);
  background: var(--color-bg);
}

.extra-block-program-picker__trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  color: var(--color-text-muted);
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.extra-block-program-picker__trigger--field {
  gap: 0.35rem;
  padding: var(--field-padding-y-sm) var(--field-padding-x-sm);
  min-height: var(--field-min-height-sm);
  width: auto;
  margin: 0;
}

.extra-block-program-picker__trigger:not(.extra-block-program-picker__trigger--field):hover:not(.extra-block-program-picker__trigger--disabled) {
  background: var(--color-bg-hover);
  border-color: color-mix(in srgb, var(--color-accent) 35%, var(--color-border));
}

.extra-block-program-picker__trigger--disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.extra-block-program-picker__eye {
  display: inline-flex;
  width: 1rem;
  height: 1rem;
}

.extra-block-program-picker__eye svg {
  width: 100%;
  height: 100%;
}

.extra-block-program-picker__scope-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: var(--color-text-muted);
}

.extra-block-program-picker__panel {
  z-index: 4000;
  min-width: 12rem;
  max-width: calc(100vw - 1rem);
  padding: 0.55rem 0.65rem;
}

.extra-block-program-picker__fieldset {
  border: 0;
  margin: 0;
  padding: 0;
  min-width: 0;
}

.extra-block-program-picker__legend {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
  margin-bottom: 0.35rem;
}

.extra-block-program-picker__option {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.3rem 0.15rem;
  cursor: pointer;
  font-size: 0.8125rem;
}

.extra-block-program-picker__option:hover {
  color: var(--color-text);
}

.extra-block-program-picker__label {
  min-width: 0;
}
</style>
