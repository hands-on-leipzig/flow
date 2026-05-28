<template>
  <div
      v-if="show"
      class="glass-scrim fixed inset-0 flex items-center justify-center z-[100]"
      @click="handleBackdropClick"
  >
    <div class="glass-modal" @click.stop>
      <div class="flex items-center mb-4">
        <div class="flex-shrink-0 w-10 h-10 mx-auto rounded-full flex items-center justify-center" :class="iconBgClass">
          <svg v-if="type === 'danger'" class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
          </svg>
          <svg v-else-if="type === 'warning'" class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
          </svg>
          <svg v-else class="w-6 h-6 text-[var(--color-accent)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
        </div>
      </div>

      <div class="text-center">
        <h3 class="text-lg font-medium text-[var(--color-text)] mb-2">
          {{ title }}
        </h3>
        <p class="text-sm text-[var(--color-text-muted)] mb-6">
          {{ message }}
        </p>

        <div class="flex space-x-3 justify-center">
          <button
              @click="handleCancel"
              class="glass-btn-secondary"
          >
            {{ cancelText }}
          </button>
          <button
              @click="handleConfirm"
              class="px-4 py-2 text-sm font-medium text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2"
              :class="[confirmButtonClass, disableConfirmButton ? 'opacity-50 cursor-not-allowed' : '']"
              :disabled="disableConfirmButton"
          >
            {{ confirmText }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  title: {
    type: String,
    required: true
  },
  message: {
    type: String,
    required: true
  },
  type: {
    type: String,
    default: 'danger',
    validator: (value) => ['danger', 'warning', 'info'].includes(value)
  },
  confirmText: {
    type: String,
    default: 'Bestätigen'
  },
  cancelText: {
    type: String,
    default: 'Abbrechen'
  },
  closeOnBackdrop: {
    type: Boolean,
    default: true
  },
  disableConfirmButton: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['confirm', 'cancel'])

const iconBgClass = computed(() => {
  switch (props.type) {
    case 'danger':
      return 'bg-red-100'
    case 'warning':
      return 'bg-yellow-100'
    case 'info':
    default:
      return 'bg-[var(--color-accent-muted)]'
  }
})

const confirmButtonClass = computed(() => {
  switch (props.type) {
    case 'danger':
      return 'bg-red-600 hover:bg-red-700 focus:ring-red-500'
    case 'warning':
      return 'bg-yellow-600 hover:bg-yellow-700 focus:ring-yellow-500'
    case 'info':
    default:
      return 'glass-btn-accent !px-4 !py-2 !text-sm'
  }
})

const handleConfirm = () => {
  emit('confirm')
}

const handleCancel = () => {
  emit('cancel')
}

const handleBackdropClick = () => {
  if (props.closeOnBackdrop) {
    emit('cancel')
  }
}
</script>
