<script setup lang="ts">

import {computed, onMounted, ref} from "vue";
import axios from "axios";
import {Slide} from "@/models/slide";
import FabricEditor from "@/components/FabricEditor.vue";
import InfoPopover from "@/components/atoms/InfoPopover.vue";
import SavingToast from "@/components/atoms/SavingToast.vue";

const props = defineProps<{
  slideId: Number,
}>();

const slide = ref<Slide | null>(null);
const savingToast = ref(null);

const settingsSlideTypes = ['RobotGameSlideContent', 'PublicPlanSlideContent', 'UrlSlideContent'];

const hasSettings = computed<boolean>(() => {
  if (!slide.value) {
    return false;
  }
  const type = slide.value.type;
  return settingsSlideTypes.includes(type);
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
  savingToast?.value?.show();
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
  <SavingToast ref="savingToast" message="Änderungen werden gespeichert..."/>

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
      <div v-if="slide.type === 'RobotGameSlideContent'">
        <div class="grid grid-cols-2 gap-2 items-center">
          <!-- Teams -->
          <div class="flex items-center space-x-2">
            <label class="text-sm font-medium">Teams pro Seite</label>
            <InfoPopover text="Anzahl an Teams, die pro Seite angezeigt werden sollen."/>
          </div>
          <div>
            <input
                class="mt-1 w-full border rounded px-2 py-1"
                type="number"
                :value="slide.content.teamsPerPage"
                @input="updateByName('teamsPerPage', ($event.target as HTMLInputElement).value || 0)"
            />
          </div>

          <!-- Zeit pro Seite -->
          <div class="flex items-center space-x-2">
            <label class="text-sm font-medium">Sekunden pro Seite</label>
            <InfoPopover text="Zeit in Sekunden, bis zur nächsten Seite geblättert wird."/>
          </div>
          <div>
            <input
                class="mt-1 w-full border rounded px-2 py-1"
                type="number"
                :value="slide.content.secondsPerPage"
                @input="updateByName('secondsPerPage', ($event.target as HTMLInputElement).value || 0)"
            />
          </div>

          <!-- Text-Farbe -->
          <div>
            <label class="text-sm font-medium">Text-Farbe</label>
            <InfoPopover text="Die Farbe, die für den Text in der Tabelle verwendet wird."/>
          </div>
          <div>
            <input
                class="mt-1 w-full border rounded px-2 py-1"
                type="color"
                :value="slide.content.textColor"
                @input="updateByName('textColor', ($event.target as HTMLInputElement).value || '#222222')"
            />
          </div>

          <!-- Highlight-Farbe -->
          <div>
            <label class="text-sm font-medium">Highlight-Farbe</label>
            <InfoPopover text="Die Farbe, die für Hervorhebungen in der Tabelle verwendet wird."/>
          </div>
          <div>
            <input
                class="mt-1 w-full border rounded px-2 py-1"
                type="color"
                :value="slide.content.highlightColor"
                @input="updateByName('highlightColor', ($event.target as HTMLInputElement).value || '#FFD700')"
            />
          </div>
        </div>
      </div>
      <div v-if="slide.type === 'UrlSlideContent'">
        <!-- URL -->
        <label class="text-sm font-medium pl-2">URL</label>
        <InfoPopover text="Die Website, die auf der Folie angezeigt werden soll."/>
        &nbsp;
        <input
            class="mt-1 w-80 border rounded px-2 py-1"
            type="text"
            :value="slide.content.url"
            @input="updateByName('url', ($event.target as HTMLInputElement).value || '')"
        />
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