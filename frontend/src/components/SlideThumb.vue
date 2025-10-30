<script setup lang="ts">
import {Slide as Slide} from "../models/slide.js";
import SlideContentRenderer from "./slideTypes/SlideContentRenderer.vue";
import {mdiTrashCanOutline, mdiPencil} from '@mdi/js';
import SvgIcon from '@jamescoyle/vue-icon';
import axios from "axios";
import IconDraggable from "@/components/icons/IconDraggable.vue";
import {useEventStore} from "@/stores/event";
import {computed} from "vue";

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const props = defineProps<{
  slide: Slide
}>();

async function deleteSlide() {
  try {
    const response = await axios.delete(`/slides/${props.slide.id}`);
    if (response.status === 200) {
      emit('deleteSlide')
    }
  } catch (error) {
    console.error("Error deleting slide:", error);
  }
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
  <div class="flex flex-col relative bg-blue-400 w-56 h-52 m-2 rounded-xl shadow">
    <div class="flex justify-between gap-1 pt-2 pr-2 items-center">

      <div class="flex items-center cursor-pointer gap-1">
        <div class="drag-handle cursor-grab p-1 rounded" title="Ziehen" draggable="false">
          <IconDraggable/>
        </div>
        <!-- Label wird benötigt, damit der Klick registriert wird -->
        <label>
          <input type="checkbox" class="sr-only"
                 :checked="slide.active === 1" @change="toggleActive"
                 aria-label="Aktivieren/Deaktivieren"
          />
          <span class="w-10 h-6 flex items-center bg-gray-300 rounded-full p-1 transition-colors duration-300"
                :class="slide.active === 1 ? 'bg-green-400' : 'bg-gray-300'"
          >
            <span class="bg-white w-4 h-4 rounded-full shadow-md transform transition-transform duration-300"
                  :class="slide.active === 1 ? 'translate-x-4' : ''"
            ></span>
          </span>
        </label>

      </div>
      <div class="flex gap-1 items-center">
        <router-link :to="'/editSlide/' + slide.id">
          <svg-icon type="mdi" :path="mdiPencil" @click="emit('editSlide')"/>
        </router-link>
        <svg-icon type="mdi" :path="mdiTrashCanOutline" @click="deleteSlide"></svg-icon>
      </div>
    </div>
    <div class="w-56 h-32 mx-auto bg-blue-300 m-2 flex items-center justify-center overflow-hidden">
      <SlideContentRenderer :slide="componentSlide" :preview="true" :eventId="event.id"></SlideContentRenderer>
    </div>
    <input
        v-model="slide.name"
        @blur="updateSlideName(slide)"
        class="editable-input flex-1 text-sm px-2 py-1 border border-transparent bg-transparent z-1 hover:bg-white cursor-text rounded hover:border-gray-300 focus:border-blue-500 focus:outline-none transition-colors"
        draggable="false"
    />
  </div>
</template>

<style scoped>

</style>
