<script setup lang="ts">
import {Slide as Slide} from "../models/slide.js";
import SlideContentRenderer from "./slideTypes/SlideContentRenderer.vue";
import ConfirmationModal from "@/components/molecules/ConfirmationModal.vue";
import axios from "axios";
import {useEventStore} from "@/stores/event";
import {computed, ref} from "vue";

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const props = defineProps<{
  slide: Slide
}>();

const showDeleteModal = ref(false);

function confirmDelete() {
  showDeleteModal.value = true;
}

async function deleteSlide() {
  try {
    const response = await axios.delete(`/slides/${props.slide.id}`);
    if (response.status === 200) {
      emit('deleteSlide')
    }
    showDeleteModal.value = false;
  } catch (error) {
    console.error("Error deleting slide:", error);
  }
}

function cancelDelete() {
  showDeleteModal.value = false;
}

async function toggleActive() {
  const active = componentSlide.active === 1 ? 0 : 1;
  componentSlide.active = active;
  props.slide.active = active;
  const s = {...componentSlide, content: componentSlide.content.toJSON()};
  axios.put(`slides/${props.slide.id}`, s).then().catch(error => {
    console.error('Error saving slide:', error);
  });
}

async function updateSlideName(slide: Slide) {
  const s = {name: slide.name};
  axios.put(`slides/${slide.id}`, s).then().catch(error => {
    console.error('Error updating slide name:', error);
  });
}

const emit = defineEmits(['deleteSlide', 'editSlide']);
const componentSlide = Slide.fromObject(props.slide);
</script>

<template>
  <div
      :data-slide-id="slide.id"
      class="flex flex-col relative bg-white w-56 h-52 m-2 rounded-lg shadow-md border border-gray-200 hover:shadow-lg transition-shadow overflow-hidden group">
    <!-- Preview Area -->
    <div class="relative w-full h-36 bg-gray-100 flex items-center justify-center overflow-hidden">
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
          <i class="bi bi-pencil text-gray-700"></i>
        </router-link>
        <button
            class="relative z-50 w-10 h-10 flex items-center justify-center bg-white rounded-full shadow-lg hover:bg-red-50 transition-colors pointer-events-auto"
            @click.stop="confirmDelete"
            title="Löschen"
        >
          <i class="bi bi-trash-fill text-red-600"></i>
        </button>
      </div>
    </div>

    <!-- Bottom Section -->
    <div class="flex-1 flex flex-col p-2 bg-white">
      <!-- Name Input -->
      <input
          v-model="slide.name"
          @blur="updateSlideName(slide)"
          class="text-sm font-medium px-2 py-1 border border-transparent bg-transparent hover:bg-gray-50 cursor-text rounded hover:border-gray-300 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 transition-colors mb-1"
          draggable="false"
          placeholder="Folienname..."
      />

      <!-- Controls Bar -->
      <div class="flex items-center justify-between mt-auto pt-1 border-t border-gray-100">
        <div
            class="drag-handle cursor-grab active:cursor-grabbing p-1 rounded text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
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
              <span class="text-xs text-gray-500" :class="slide.active === 1 ? 'text-green-600 font-medium' : ''">
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
        @confirm="deleteSlide"
        @cancel="cancelDelete"
    />
  </div>
</template>

<style scoped>

</style>
