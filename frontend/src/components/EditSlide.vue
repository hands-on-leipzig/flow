<script setup lang="ts">

import {computed, onMounted, ref} from "vue";
import {useEventStore} from "@/stores/event";
import axios from "axios";
import {Slide} from "@/models/slide";
import FabricEditor from "@/components/FabricEditor.vue";
import InfoPopover from "@/components/atoms/InfoPopover.vue";

type RobotGamePublicRounds = {
  vr1: boolean;
  vr2: boolean;
  vr3: boolean;
  af: boolean;
  vf: boolean;
  hf: boolean;
};

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const props = defineProps<{
  slideId: Number,
}>();

const robotGameRounds = ref<RobotGamePublicRounds | null>(null);
const slide = ref<Slide | null>(null);

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

  if (slide.value.type === 'RobotGameSlideContent') {
    await getPublicRobotGameRounds();
  }
  return null;
}

function updateByName(name: string, value: any) {
  if (!slide.value) return;
  slide.value.content[name] = value;
  saveSlide();
}

async function getPublicRobotGameRounds() {
  if (!slide.value || slide.value.type !== 'RobotGameSlideContent') {
    return;
  }
  try {
    const response = await axios.get('/contao/rounds/' + event.value?.id);
    robotGameRounds.value = response.data;
  } catch (error) {
    console.error('Error fetching rounds:', error);
  }
}

async function updateRobotGameRounds(round, value) {
  robotGameRounds.value[round] = value;
  await pushPublicRobotGameRoundsUpdate();
}

async function pushPublicRobotGameRoundsUpdate() {
  try {
    await axios.put('/contao/rounds/' + event.value?.id, robotGameRounds.value);
  } catch (e) {
    console.error('Error updating rounds:', e);
  }
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
        <br>

        <!-- Öffentliche Ergebnisse -->
        <div class="mt-4 rounded-lg border px-2 py-2 transition hover:border-gray-400" v-if="robotGameRounds">
          <label class="text-sm font-medium pl-2">Öffentliche Ergebnisse</label>
          <InfoPopover text="Wählen Sie aus, welche Ergebnisse öffentlich sichtbar sein sollen. Falls eine Wettbewerbsphase noch läuft oder später (z.B. auf der Bühne) veröffentlicht werden soll, sollte diese hier nicht ausgewählt werden."/>
          <div class="grid grid-cols-6 gap-2 mt-2">
            <label class="flex items-center gap-2 px-2">
              <input
                  type="checkbox"
                  :checked="robotGameRounds.vr1"
                  @change="updateRobotGameRounds('vr1', ($event.target as HTMLInputElement).checked)"
              />
              <span>VR1</span>
            </label>
            <label class="flex items-center gap-2 px-2">
              <input
                  type="checkbox"
                  :checked="robotGameRounds.vr2"
                  @change="updateRobotGameRounds('vr2', ($event.target as HTMLInputElement).checked)"
              />
              <span>VR2</span>
            </label>
            <label class="flex items-center gap-2 px-2">
              <input
                  type="checkbox"
                  :checked="robotGameRounds.vr3"
                  @change="updateRobotGameRounds('vr3', ($event.target as HTMLInputElement).checked)"
              />
              <span>VR3</span>
            </label><label class="flex items-center gap-2 px-2">
              <input
                  type="checkbox"
                  :checked="robotGameRounds.af"
                  @change="updateRobotGameRounds('af', ($event.target as HTMLInputElement).checked)"
              />
              <span>AF</span>
            </label>
            <label class="flex items-center gap-2 px-2">
              <input
                  type="checkbox"
                  :checked="robotGameRounds.vf"
                  @change="updateRobotGameRounds('vf', ($event.target as HTMLInputElement).checked)"
              />
              <span>VF</span>
            </label><label class="flex items-center gap-2 px-2">
              <input
                  type="checkbox"
                  :checked="robotGameRounds.hf"
                  @change="updateRobotGameRounds('hf', ($event.target as HTMLInputElement).checked)"
              />
              <span>HF</span>
            </label>
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