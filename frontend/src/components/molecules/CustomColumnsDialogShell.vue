<script setup lang="ts">
defineProps<{
  open: boolean
  titleId: string
  hint: string
  error?: string
  loading?: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

function onDialogKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('close')
  }
}
</script>

<template>
  <Teleport to="body">
    <div
        v-if="open"
        class="glass-scrim fixed inset-0 z-[100] flex items-center justify-center p-4"
        @click="emit('close')"
    >
      <div
          class="glass-modal vol-columns-dialog"
          role="dialog"
          :aria-labelledby="titleId"
          aria-modal="true"
          @click.stop
          @keydown="onDialogKeydown"
      >
        <header class="vol-columns-dialog__header">
          <h2 :id="titleId" class="vol-columns-dialog__title">Spalten verwalten</h2>
          <p class="vol-columns-dialog__hint">{{ hint }}</p>
          <ul v-if="$slots.builtins" class="vol-columns-dialog__builtins" aria-label="Feste Spalten">
            <slot name="builtins"/>
          </ul>
        </header>

        <div class="vol-columns-dialog__body">
          <div v-if="error" class="glass-alert-warning vol-columns-dialog__alert">{{ error }}</div>
          <p v-if="loading" class="vol-muted">Laden…</p>
          <slot/>
        </div>

        <footer class="vol-columns-dialog__footer">
          <button type="button" class="glass-btn-secondary" @click="emit('close')">
            Schließen
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style>
@import '@/assets/columns-dialog.css';
</style>
