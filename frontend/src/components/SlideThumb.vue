<script setup lang="ts">
import {Slide as Slide} from "../models/slide.js";
import SlideContentRenderer from "./slideTypes/SlideContentRenderer.vue";
import ConfirmationModal from "@/components/molecules/ConfirmationModal.vue";
import axios from "axios";
import {useEventStore} from "@/stores/event";
import {computed, ref} from "vue";

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const props = withDefaults(defineProps<{
  slide: Slide
  defaultTransitionTime?: number
}>(), {
  defaultTransitionTime: 15,
});

// Robot game slides page through their tables at their own pace.
const hasDuration = computed(() => props.slide.type !== 'RobotGameSlideContent');
const hasOwnDuration = computed(() => (props.slide.transition_time ?? 0) > 0);
const effectiveDuration = computed(() => hasOwnDuration.value ? props.slide.transition_time : props.defaultTransitionTime);

function saveTransitionTime(seconds: number) {
  if (seconds === (props.slide.transition_time ?? 0)) return;
  props.slide.transition_time = seconds;
  componentSlide.transition_time = seconds;
  axios.put(`slides/${props.slide.id}`, {transition_time: seconds}).catch(error => {
    console.error('Error updating slide duration:', error);
  });
}

/** Empty or equal to the slideshow time means: follow the slideshow time. */
function onDurationChange(event: Event) {
  const input = event.target as HTMLInputElement;
  const seconds = Math.max(0, Math.round(Number(input.value) || 0));
  saveTransitionTime(seconds === props.defaultTransitionTime ? 0 : seconds);
  input.value = String(effectiveDuration.value);
}

const showDeleteModal = ref(false);
const loadingDelete = ref(false);

function confirmDelete() {
  showDeleteModal.value = true;
}

async function deleteSlide() {
  try {
    loadingDelete.value = true;
    const response = await axios.delete(`/slides/${props.slide.id}`);
    if (response.status === 200) {
      emit('deleteSlide')
    }
  } catch (error) {
    console.error("Error deleting slide:", error);
  } finally {
    showDeleteModal.value = false;
    loadingDelete.value = false;
  }
}

function cancelDelete() {
  showDeleteModal.value = false;
}

async function toggleActive() {
  const active = componentSlide.active === 1 ? 0 : 1;
  componentSlide.active = active;
  props.slide.active = active;
  axios.put(`slides/${props.slide.id}`, {active}).then().catch(error => {
    console.error('Error saving slide:', error);
  });
}

async function updateSlideName(slide: Slide) {
  const s = {name: slide.name};
  axios.put(`slides/${slide.id}`, s).then().catch(error => {
    console.error('Error updating slide name:', error);
  });
}

function copySingleLink() {
  const url = `${window.location.origin}/carousel/${event.value.id}/${props.slide.id}`;
  navigator.clipboard.writeText(url);
}

const emit = defineEmits(['deleteSlide', 'editSlide']);
const componentSlide = Slide.fromObject(props.slide);
</script>

<template>
  <div
      :data-slide-id="slide.id"
      class="flex flex-col relative bg-white w-56 h-52 m-2 rounded-lg shadow-md border border-[var(--color-border)] hover:shadow-lg transition-shadow overflow-hidden group">
    <!-- Preview Area -->
    <div class="relative w-full h-36 bg-[var(--color-bg-muted)] flex items-center justify-center overflow-hidden">
      <div class="w-full h-full flex items-center justify-center relative z-0 group-hover:pointer-events-none">
        <SlideContentRenderer :slide="componentSlide" :preview="true" :eventId="event.id"></SlideContentRenderer>
      </div>

      <!-- Overlay with controls (shown on hover) -->
      <div
          class="absolute inset-0 z-[60] bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-3 opacity-0 group-hover:opacity-100 pointer-events-none group-hover:pointer-events-auto">
        <router-link
            :to="'/editSlide/' + slide.id"
            class="relative z-50 w-10 h-10 flex items-center justify-center bg-white rounded-full shadow-lg hover:bg-blue-50 transition-colors pointer-events-auto"
            @click="emit('editSlide')"
            title="Bearbeiten"
        >
          <i class="bi bi-pencil text-[var(--color-text-muted)]"></i>
        </router-link>
        <button class="relative z-50 w-10 h-10 flex items-center justify-center bg-white rounded-full shadow-lg hover:bg-red-50 transition-colors pointer-events-auto"
                @click="copySingleLink"
                title="Direktlink kopieren">
          <i class="bi bi-clipboard-plus text-[var(--color-text-muted)]"></i>
        </button>
        <button
            class="relative z-50 w-10 h-10 flex items-center justify-center bg-white rounded-full shadow-lg hover:bg-red-50 transition-colors pointer-events-auto"
            @click.stop="confirmDelete"
            title="Löschen"
        >
          <i class="bi bi-trash-fill text-red-600"></i>
        </button>
      </div>

      <!-- Anzeigedauer -->
      <label v-if="hasDuration" class="duration-bubble" :class="{ 'duration-bubble--own': hasOwnDuration }"
             :title="hasOwnDuration
               ? 'Eigene Anzeigezeit dieser Folie. Zurücksetzen, um die Zeit der Slideshow zu verwenden.'
               : 'Zeit der Slideshow. Wert ändern, um dieser Folie eine eigene Zeit zu geben.'">
        <i class="bi bi-clock"></i>
        <input type="number" min="1" max="600" :value="effectiveDuration"
               aria-label="Anzeigezeit dieser Folie in Sekunden" draggable="false"
               @change="onDurationChange" @keydown.enter="($event.target as HTMLInputElement).blur()"/>
        <span>s</span>
        <button v-if="hasOwnDuration" type="button" class="duration-bubble__reset"
                title="Zeit der Slideshow verwenden" @click.prevent="saveTransitionTime(0)">
          <i class="bi bi-arrow-counterclockwise"></i>
        </button>
      </label>
      <span v-else class="duration-bubble duration-bubble--auto" title="Blättert selbstständig durch die Seiten der Tabelle">
        <i class="bi bi-arrow-repeat"></i> auto
      </span>
    </div>

    <!-- Bottom Section -->
    <div class="flex-1 flex flex-col p-2 bg-white">
      <!-- Name Input -->
      <input
          v-model="slide.name"
          @blur="updateSlideName(slide)"
          class="text-sm font-medium px-2 py-1 border border-transparent bg-transparent hover:bg-[var(--color-bg-hover)] cursor-text rounded hover:border-[var(--color-border)] focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 transition-colors mb-1"
          draggable="false"
          placeholder="Folienname…"
      />

      <!-- Controls Bar -->
      <div class="flex items-center justify-between mt-auto pt-1 border-t border-[var(--color-border)]">
        <div
            class="drag-handle cursor-grab active:cursor-grabbing p-1 rounded text-[var(--color-text-subtle)] hover:text-[var(--color-text-muted)] hover:bg-[var(--color-bg-hover)] transition-colors"
            title="Ziehen" draggable="false">
          <i class="bi bi-arrows-move text-sm"></i>
        </div>

        <div class="flex items-center gap-2">
          <label class="cursor-pointer">
            <input
                type="checkbox"
                class="sr-only"
                :checked="slide.active === 1"
                @change="toggleActive"
                aria-label="Aktivieren/Deaktivieren"
            />
            <div class="flex items-center gap-1.5">
              <span class="text-xs text-[var(--color-text-subtle)]" :class="slide.active === 1 ? 'text-green-600 font-medium' : ''">
                {{ slide.active === 1 ? 'Aktiv' : 'Inaktiv' }}
              </span>
              <span
                  class="w-8 h-4 flex items-center rounded-full p-0.5 transition-colors duration-300"
                  :class="slide.active === 1 ? 'bg-green-500' : 'bg-gray-300'"
              >
                <span
                    class="bg-white w-3 h-3 rounded-full shadow-sm transform transition-transform duration-300"
                    :class="slide.active === 1 ? 'translate-x-4' : 'translate-x-0'"
                ></span>
              </span>
            </div>
          </label>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <ConfirmationModal
        :show="showDeleteModal"
        title="Folie löschen"
        :message="`Folie ${slide.name || 'Unbenannt'} wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`"
        type="danger"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        :disable-confirm-button="loadingDelete"
        @confirm="deleteSlide"
        @cancel="cancelDelete"
    />
  </div>
</template>

<style scoped>
.duration-bubble {
  position: absolute;
  top: 0.4rem;
  right: 0.4rem;
  z-index: 70;
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  height: 1.6rem;
  padding: 0 0.5rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.25);
  font-size: 0.75rem;
  font-weight: 500;
  color: #475569;
  cursor: text;
}

.duration-bubble:focus-within {
  outline: 2px solid var(--color-accent);
  outline-offset: 1px;
}

.duration-bubble input {
  width: 1.9rem;
  border: 0;
  background: transparent;
  text-align: right;
  font: inherit;
  font-variant-numeric: tabular-nums;
  color: inherit;
  outline: none;
  -moz-appearance: textfield;
}

.duration-bubble input::-webkit-outer-spin-button,
.duration-bubble input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.duration-bubble--own {
  background: var(--color-accent);
  color: var(--color-on-accent);
  font-weight: 700;
}

.duration-bubble--auto {
  cursor: default;
}

.duration-bubble__reset {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.1rem;
  height: 1.1rem;
  margin-left: 0.1rem;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.25);
  color: inherit;
  font-size: 0.65rem;
}

.duration-bubble__reset:hover {
  background: rgba(255, 255, 255, 0.45);
}
</style>
