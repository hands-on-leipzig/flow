<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import {useVolunteerMealOptions, type VolunteerMealOption} from '@/composables/useVolunteerMealOptions'

const props = defineProps<{
  open: boolean
  eventId: number | undefined
}>()

const emit = defineEmits<{
  close: []
  changed: []
}>()

const eventIdRef = computed(() => props.eventId)
const {options, loading, error, load, replace} = useVolunteerMealOptions(eventIdRef)

const saving = ref(false)
const deleteTarget = ref<VolunteerMealOption | null>(null)
const draftLabel = ref('')

const canAdd = computed(() => draftLabel.value.trim().length > 0 && !saving.value)

const deleteMessage = computed(() => {
  const target = deleteTarget.value
  if (!target) return ''
  const count = target.usage_count ?? 0
  if (count > 0) {
    const assignees = count === 1 ? '1 Helfer:in' : `${count} Helfer:innen`
    const verb = count === 1 ? 'verliert' : 'verlieren'
    return `„${target.label}" wird gelöscht. ${assignees} ${verb} die gespeicherte Essenswahl.`
  }
  return `„${target.label}" wird dauerhaft entfernt.`
})

function requestClose() {
  if (deleteTarget.value || saving.value) return
  emit('close')
}

function onDialogKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    requestClose()
  }
}

async function persistOptions(next: VolunteerMealOption[]) {
  saving.value = true
  const ok = await replace(next.map((option) => ({value: option.value, label: option.label})))
  saving.value = false
  if (ok) {
    emit('changed')
  }
  return ok
}

async function addOption() {
  const label = draftLabel.value.trim()
  if (!label || saving.value) return
  const value = label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_|_$/g, '') || 'option'
  const next = [...options.value, {value, label}]
  if (await persistOptions(next)) {
    draftLabel.value = ''
  }
}

async function updateLabel(option: VolunteerMealOption, label: string) {
  const trimmed = label.trim()
  if (!trimmed || trimmed === option.label) return
  const next = options.value.map((item) =>
    item.value === option.value ? {...item, label: trimmed} : item,
  )
  await persistOptions(next)
}

async function moveOption(option: VolunteerMealOption, direction: 'up' | 'down') {
  const index = options.value.findIndex((item) => item.value === option.value)
  if (index < 0) return
  const target = index + (direction === 'up' ? -1 : 1)
  if (target < 0 || target >= options.value.length) return
  const next = [...options.value]
  const [item] = next.splice(index, 1)
  next.splice(target, 0, item)
  await persistOptions(next)
}

async function confirmDeleteOption() {
  const target = deleteTarget.value
  if (!target || saving.value) return
  const next = options.value.filter((item) => item.value !== target.value)
  if (next.length === 0) {
    error.value = 'Mindestens eine Essensoption ist erforderlich.'
    deleteTarget.value = null
    return
  }
  saving.value = true
  const ok = await replace(next.map((option) => ({value: option.value, label: option.label})))
  saving.value = false
  if (ok) {
    deleteTarget.value = null
    emit('changed')
  }
}

watch(
  () => [props.open, props.eventId] as const,
  ([open]) => {
    if (open) {
      deleteTarget.value = null
      draftLabel.value = ''
      void load()
    }
  },
  {immediate: true},
)
</script>

<template>
  <Teleport to="body">
    <div
        v-if="open"
        class="glass-scrim fixed inset-0 z-[100] flex items-center justify-center p-4"
        @click="requestClose"
    >
      <div
          class="glass-modal vol-meal-dialog"
          role="dialog"
          aria-labelledby="vol-meal-dialog-title"
          aria-modal="true"
          @click.stop
          @keydown="onDialogKeydown"
      >
        <header class="vol-meal-dialog__header">
          <h2 id="vol-meal-dialog-title" class="vol-meal-dialog__title">Essensoptionen</h2>
          <p class="vol-meal-dialog__hint">
            Diese Liste gilt für die Helfer:innen und die Teams.
          </p>
        </header>

        <div class="vol-meal-dialog__body">
          <div v-if="error" class="glass-alert-warning vol-meal-dialog__alert">{{ error }}</div>
          <p v-if="loading" class="vol-muted">Laden…</p>

          <section v-if="options.length" class="vol-meal-dialog__section">
            <h3 class="vol-meal-dialog__section-title">Optionen</h3>
            <div class="vol-meal-list">
              <div v-for="option in options" :key="option.value" class="vol-meal-item">
                <input
                    class="glass-input glass-input--sm"
                    :value="option.label"
                    :disabled="saving"
                    @change="updateLabel(option, ($event.target as HTMLInputElement).value)"
                >
                <div class="vol-meal-item__actions">
                  <button type="button" class="vol-icon-btn" :disabled="saving" title="Nach oben" @click="moveOption(option, 'up')">
                    <i class="bi bi-arrow-up" aria-hidden="true"/>
                  </button>
                  <button type="button" class="vol-icon-btn" :disabled="saving" title="Nach unten" @click="moveOption(option, 'down')">
                    <i class="bi bi-arrow-down" aria-hidden="true"/>
                  </button>
                  <button
                      type="button"
                      class="vol-icon-btn"
                      :disabled="saving || options.length <= 1"
                      title="Löschen"
                      @click="deleteTarget = option"
                  >
                    <i class="bi bi-trash" aria-hidden="true"/>
                  </button>
                </div>
              </div>
            </div>
          </section>

          <section class="vol-meal-dialog__section">
            <h3 class="vol-meal-dialog__section-title">Neue Option</h3>
            <div class="vol-meal-add">
              <input
                  v-model="draftLabel"
                  class="glass-input glass-input--sm"
                  placeholder="z. B. Glutenfrei"
                  :disabled="saving"
              >
              <button type="button" class="glass-btn-accent" :disabled="!canAdd" @click="addOption">
                Hinzufügen
              </button>
            </div>
          </section>
        </div>

        <footer class="vol-meal-dialog__footer">
          <button type="button" class="glass-btn-secondary" @click="requestClose">Schließen</button>
        </footer>
      </div>
    </div>
  </Teleport>

  <Teleport to="body">
    <ConfirmationModal
        :show="!!deleteTarget"
        scrim-class="z-[110]"
        type="warning"
        title="Essensoption löschen?"
        :message="deleteMessage"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        :disable-confirm-button="saving"
        @confirm="confirmDeleteOption"
        @cancel="deleteTarget = null"
    />
  </Teleport>
</template>

<style scoped>
.vol-meal-dialog {
  display: flex;
  flex-direction: column;
  width: min(100%, 32rem);
  max-height: min(90vh, 40rem);
  overflow: hidden;
  padding: 0;
}

.vol-meal-dialog__header {
  flex-shrink: 0;
  padding: 1.25rem 1.25rem 0.75rem;
  border-bottom: 1px solid var(--liquid-border-soft);
}

.vol-meal-dialog__title {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  font-weight: 650;
}

.vol-meal-dialog__hint {
  margin: 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.vol-meal-dialog__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 1rem 1.25rem;
}

.vol-meal-dialog__section {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.vol-meal-dialog__section + .vol-meal-dialog__section {
  margin-top: 1.25rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--liquid-border-soft);
}

.vol-meal-dialog__section-title {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 600;
}

.vol-meal-dialog__alert {
  margin-bottom: 0.75rem;
  padding: 0.65rem 0.85rem;
  border-radius: 0.75rem;
}

.vol-meal-list {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.vol-meal-item {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0.5rem;
  align-items: center;
  padding: 0.65rem;
  border: 1px solid var(--liquid-border-soft);
  border-radius: var(--radius);
  background: var(--liquid-tile-bg-inner);
}

.vol-meal-item__actions {
  display: inline-flex;
  gap: 0.15rem;
}

.vol-meal-add {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.vol-meal-dialog__footer {
  flex-shrink: 0;
  display: flex;
  justify-content: flex-end;
  padding: 0.85rem 1.25rem 1.25rem;
  border-top: 1px solid var(--liquid-border-soft);
}

.vol-muted {
  opacity: 0.7;
  font-size: 0.9rem;
  margin: 0;
}

.vol-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.85rem;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
}

.vol-icon-btn:hover:not(:disabled) {
  background: var(--color-bg-hover);
  color: var(--color-text);
}

.vol-icon-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
