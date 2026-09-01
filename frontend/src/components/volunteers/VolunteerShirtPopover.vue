<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import {useAnchoredPanel} from '@/composables/useAnchoredPanel'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'
import {T_SHIRT_CUTS, T_SHIRT_SIZES} from '@/volunteers/rosterConstants'
import {defaultRosterDetail, type RosterEntry} from '@/volunteers/rosterTypes'

const props = defineProps<{
  eventId?: number | null
  entry: RosterEntry | null
  anchor: HTMLElement | null
  saving?: boolean
}>()

const emit = defineEmits<{
  close: []
  saved: [entry: RosterEntry]
}>()

const draft = ref<{cut: string | null; size: string | null}>({cut: null, size: null})

const isOpen = computed(() => !!props.entry && !!props.anchor)

const {panelRef, panelStyle} = useAnchoredPanel({
  isOpen,
  anchor: computed(() => props.anchor),
  fallbackWidth: 260,
  fallbackHeight: 300,
  closeOn: 'mousedown',
  onClose: () => emit('close'),
})

watch(
  () => props.entry,
  (entry) => {
    if (!entry) return
    const detail = entry.detail ?? defaultRosterDetail()
    draft.value = {
      cut: detail.t_shirt_cut,
      size: detail.t_shirt_size,
    }
  },
  {immediate: true},
)

function draftCutValue() {
  return draft.value.cut ?? ''
}

function draftSizeValue() {
  return draft.value.size ?? ''
}

function onCutPick(cut: string | null) {
  draft.value.cut = cut
}

function onSizePick(size: string | null) {
  draft.value.size = size
}

async function confirm() {
  const entry = props.entry
  if (!entry || !props.eventId) return

  const cut = draft.value.cut
  const size = draft.value.size
  const hasCut = cut !== null && cut !== ''
  const hasSize = size !== null && size !== ''

  if (hasCut !== hasSize) {
    showGlassToast('Bitte Schnitt und Größe gemeinsam wählen — oder „?“ in beiden Spalten.', 'info')
    return
  }

  const detail = entry.detail ?? defaultRosterDetail()
  const meal = detail.meal
  const notes = detail.notes
  const photoConsent = detail.photo_consent

  try {
    const {data} = await axios.patch(
      `/events/${props.eventId}/volunteer-roster/${entry.person.id}/detail`,
      {
        t_shirt_cut: hasCut ? cut : null,
        t_shirt_size: hasSize ? size : null,
        meal,
        notes,
        photo_consent: photoConsent,
      },
    )
    entry.detail = data.detail ?? {
      ...detail,
      t_shirt_cut: hasCut ? cut : null,
      t_shirt_size: hasSize ? size : null,
    }
    emit('saved', entry)
    emit('close')
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  }
}
</script>

<template>
  <Teleport to="body">
    <div
        v-if="entry"
        ref="panelRef"
        class="glass-modal vol-shirt-popover"
        :style="panelStyle"
        @click.stop
    >
      <h3 class="vol-shirt-popover__title">T-Shirt</h3>
      <div class="vol-shirt-popover__columns">
        <fieldset class="vol-shirt-popover__group">
          <legend class="vol-shirt-popover__legend">Schnitt</legend>
          <label class="vol-shirt-popover__option">
            <input
                type="radio"
                name="vol-shirt-cut"
                value=""
                :checked="draftCutValue() === ''"
                @change="onCutPick(null)"
            >
            <span>?</span>
          </label>
          <label
              v-for="cut in T_SHIRT_CUTS"
              :key="cut.value"
              class="vol-shirt-popover__option"
          >
            <input
                type="radio"
                name="vol-shirt-cut"
                :value="cut.value"
                :checked="draftCutValue() === cut.value"
                @change="onCutPick(cut.value)"
            >
            <span>{{ cut.label }}</span>
          </label>
        </fieldset>
        <fieldset class="vol-shirt-popover__group">
          <legend class="vol-shirt-popover__legend">Größe</legend>
          <label class="vol-shirt-popover__option">
            <input
                type="radio"
                name="vol-shirt-size"
                value=""
                :checked="draftSizeValue() === ''"
                @change="onSizePick(null)"
            >
            <span>?</span>
          </label>
          <label
              v-for="size in T_SHIRT_SIZES"
              :key="size"
              class="vol-shirt-popover__option"
          >
            <input
                type="radio"
                name="vol-shirt-size"
                :value="size"
                :checked="draftSizeValue() === size"
                @change="onSizePick(size)"
            >
            <span>{{ size }}</span>
          </label>
        </fieldset>
      </div>
      <div class="vol-shirt-popover__actions">
        <button type="button" class="glass-btn-secondary" :disabled="saving" @click="emit('close')">
          Abbruch
        </button>
        <button type="button" class="glass-btn-accent" :disabled="saving" @click="confirm">
          Übernehmen
        </button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.vol-shirt-popover {
  z-index: 1200;
  width: min(20rem, calc(100vw - 1rem));
  padding: 0.85rem 1rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-popover-fill);
  backdrop-filter: blur(var(--liquid-popover-blur));
  box-shadow: var(--shadow-lg);
}

.vol-shirt-popover__title {
  margin: 0 0 0.65rem;
  font-size: 0.875rem;
  font-weight: 600;
}

.vol-shirt-popover__columns {
  display: flex;
  flex-direction: row;
  align-items: flex-start;
  gap: 1rem;
}

.vol-shirt-popover__group {
  flex: 1;
  min-width: 0;
  margin: 0;
  padding: 0;
  border: none;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}

.vol-shirt-popover__legend {
  padding: 0;
  margin-bottom: 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.vol-shirt-popover__option {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  width: 100%;
  margin: 0.12rem 0;
  font-size: 0.8125rem;
  cursor: pointer;
}

.vol-shirt-popover__option input {
  margin: 0;
}

.vol-shirt-popover__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.85rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--color-border);
}
</style>
