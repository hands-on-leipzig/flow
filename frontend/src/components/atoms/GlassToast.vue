<script setup lang="ts">
import {computed} from 'vue'
import {useGlassToast} from '@/composables/useGlassToast'

const {visible, message, type, hide} = useGlassToast()

const panelClass = computed(() => {
  switch (type.value) {
    case 'success':
      return 'glass-toast--success'
    case 'error':
      return 'glass-toast--error'
    default:
      return 'glass-toast--info'
  }
})

const iconClass = computed(() => {
  switch (type.value) {
    case 'success':
      return 'bi-check-circle-fill'
    case 'error':
      return 'bi-exclamation-triangle-fill'
    default:
      return 'bi-info-circle-fill'
  }
})
</script>

<template>
  <Transition name="glass-toast">
    <div
        v-if="visible"
        class="glass-toast"
        :class="panelClass"
        role="status"
        aria-live="polite"
    >
      <i class="bi glass-toast__icon" :class="iconClass" aria-hidden="true"/>
      <p class="glass-toast__message">{{ message }}</p>
      <button
          type="button"
          class="glass-toast__close"
          aria-label="Schließen"
          @click="hide"
      >
        ×
      </button>
    </div>
  </Transition>
</template>

<style scoped>
.glass-toast {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 200;
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  min-width: min(20rem, calc(100vw - 2rem));
  max-width: min(26rem, calc(100vw - 2rem));
  padding: 0.85rem 0.95rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-popover-fill);
  backdrop-filter: blur(var(--liquid-popover-blur)) saturate(var(--liquid-popover-saturate));
  -webkit-backdrop-filter: blur(var(--liquid-popover-blur)) saturate(var(--liquid-popover-saturate));
  box-shadow:
    0 18px 40px rgba(15, 23, 42, 0.14),
    0 4px 12px rgba(15, 23, 42, 0.08),
    inset 0 1.5px 0 rgba(255, 255, 255, 0.9);
  color: var(--color-text);
}

.glass-toast__icon {
  font-size: 1.15rem;
  line-height: 1.3;
  flex-shrink: 0;
}

.glass-toast__message {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-size: 0.875rem;
  font-weight: 600;
  line-height: 1.4;
  white-space: pre-line;
}

.glass-toast__close {
  flex-shrink: 0;
  border: none;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
  padding: 0 0.15rem;
}

.glass-toast__close:hover {
  color: var(--color-text);
}

.glass-toast--success .glass-toast__icon {
  color: #16a34a;
}

.glass-toast--error {
  border-color: color-mix(in srgb, #dc2626 35%, var(--liquid-border));
}

.glass-toast--error .glass-toast__icon {
  color: #dc2626;
}

.glass-toast--info .glass-toast__icon {
  color: var(--color-accent);
}

.glass-toast-enter-active,
.glass-toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.glass-toast-enter-from,
.glass-toast-leave-to {
  opacity: 0;
  transform: translateY(-8px) scale(0.98);
}
</style>
