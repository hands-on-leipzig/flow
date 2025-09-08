<script setup lang="ts">

import {computed, onMounted, ref} from "vue";
import {useEventStore} from "@/stores/event";
import FllEvent from "@/models/FllEvent";
import axios from "axios";
import {Slide} from "@/models/slide";
import FabricEditor from "@/components/FabricEditor.vue";
import InfoPopover from "@/components/atoms/InfoPopover.vue";

const props = defineProps<{
  slideId: Number,
}>();

const slide = ref<Slide | null>(null);

const hasSettings = computed<boolean>(() => {
  if (!slide.value) {
    return false;
  }
  const type = slide.value.type;
  return type === 'RobotGameSlideContent' || type === 'PublicPlanSlideContent';
})

onMounted(loadSlide);

async function loadSlide() {
  const response = await axios.get(`slides/${props.slideId}`)
  if (response && response.data) {
    slide.value = Slide.fromObject(response.data);
  }
  return null;
}

function updateByName(name: string, value: any) {
  if (!slide.value) return;
  slide.value.content[name] = value;
  saveSlide();
}

function saveSlide() {
  const s = {...slide.value, content: slide.value.content.toJSON()};
  axios.put(`slides/${slide.value.id}`, s).then(response => {
    console.log('Slide saved:', response.data);
  }).catch(error => {
    console.error('Error saving slide:', error);
  });
}

</script>

<template>
  <div class="grid gap-6 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-3 mt-1">
    <div class="rounded-xl shadow bg-white p-4 col-span-1" v-if="hasSettings">
      <span class="font-semibold px-2">
        Einstellungen
      </span>
      <div v-if="slide.type === 'PublicPlanSlideContent'">
        <!-- Stunden -->
        <label class="text-sm font-medium pl-2">Stunden</label>
        <InfoPopover text="Anzahl Stunden, auf die vorausgeblickt werden soll."/>
        &nbsp;
        <input
            class="mt-1 w-32 border rounded px-2 py-1"
            type="number"
            min="1"
            max="12"
            :value="slide.content.hours"
            @input="updateByName('hours', Number(($event.target as HTMLInputElement).value || 0))"
        />
        <!-- Inhalte Anzeigen (Rolle) -->
        <div class="grid grid-cols-1 gap-2 mb-4">
          <div class="rounded-lg border px-2 py-2 transition hover:border-gray-400">
            <label class="text-sm font-medium">Sichtbare Programmpunkte</label>
            <InfoPopover text="Wähle aus, ob Programmpunkte aus Explore oder Challenge angezeigt werden sollen."/>

            <div class="flex gap-2 items-center">
              <button
                  type="button"
                  class="px-2 py-1 rounded-md border text-sm transition
                     focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  :class="slide.content.role == 14 ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
                  @click="updateByName('role', 14)"
              >
                Explore & Challenge
              </button>
              <button
                  type="button"
                  class="px-3 py-1.5 rounded-md border text-sm transition
                     focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  :class="slide.content.role == 10 ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
                  @click="updateByName('role', 10)"
              >
                Nur Explore
              </button>
              <button
                  type="button"
                  class="px-3 py-1.5 rounded-md border text-sm transition
                     focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  :class="slide.content.role == 6 ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
                  @click="updateByName('role', 6)"
              >
                Nur Challenge
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>

    <div class="rounded-xl shadow bg-white p-4 col-span-2">
      <span class="font-semibold">Hintergrund</span> <br>
      <FabricEditor :slide="slide" v-if="!!slide"></FabricEditor>
    </div>
  </div>
</template>

<style scoped>

</style>