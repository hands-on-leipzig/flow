<script setup lang="ts">

import draggable from "vuedraggable";
import SlideThumb from "@/components/SlideThumb.vue";
import {useEventStore} from "@/stores/event";
import {computed, onMounted, ref} from "vue";
import SvgIcon from '@jamescoyle/vue-icon';
import {mdiContentCopy} from '@mdi/js';
import {Slideshow} from "@/models/slideshow";
import axios from "axios";
import {Slide} from "@/models/slide";
import InfoPopover from "@/components/atoms/InfoPopover.vue";
import SavingToast from "@/components/atoms/SavingToast.vue";

type RobotGamePublicRounds = {
  vr1: boolean;
  vr2: boolean;
  vr3: boolean;
  vf: boolean;
  hf: boolean;
};

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const loading = ref(true);
const planId = ref<number | null>(null);
const slideshows = ref<Slideshow[]>([]);
const savingToast = ref(null);

const robotGameRounds = ref<RobotGamePublicRounds | null>(null);

const carouselLink = computed(() => {
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
});
const slidesKey = ref(1);

const slideType = ref("");
const slideTypes = [
  {value: 'RobotGameSlideContent', label: 'Robot-Game-Ergebnisse'},
  {value: 'PublicPlanSlideContent', label: 'Öffentlicher Zeitplan'},
  {value: 'UrlSlideContent', label: 'Externer Inhalt (URL)'},
  {value: 'FabricSlideContent', label: 'Eigener Inhalt'},
];

onMounted(loadSlideshows);
onMounted(getPublicRobotGameRounds);
onMounted(fetchPlanId);

async function loadSlideshows() {
  if (!event.value?.id) return;
  const response = await axios.get(`/slideshow/${event.value?.id}`);
  if (response && response.data) {
    slideshows.value = response.data;
  }
  loading.value = false;
}

async function fetchPlanId() {
  if (!event.value?.id) return;
  try {
    const response = await axios.get(`/plans/event/${event.value.id}`)
    planId.value = response.data.id
  } catch (error) {
    console.error('Error fetching plan ID:', error)
  }
}

async function updateOrder(slideshow: Slideshow) {
  const slideIds = slideshow.slides.map(slide => slide.id);

  savingToast?.value?.show();

  try {
    await axios.put(`/slideshow/${slideshow.id}/updateOrder`, {
      slide_ids: slideIds
    });
  } catch (e) {
    console.error(e);
  }
  console.log('update order');
}

function deleteSlide(slideshow: Slideshow, slideId: number) {
  const index = slideshow.slides.findIndex(s => s.id === slideId);
  if (index !== -1) {
    slideshow.slides.splice(index, 1);
  }
}

async function addSlideshow() {
  loading.value = true;
  const response = await axios.post(`/slideshow/${event.value?.id}`, {
    planId: planId.value
  });

  const slideshow = response.data.slideshow;
  slideshows.value.push(slideshow);
  loading.value = false;
}

async function updateTransitionTime(slideshow: Slideshow) {
  savingToast?.value?.show();
  try {
    await axios.put(`/slideshow/${slideshow.id}`, {
      transition_time: slideshow.transition_time
    });
  } catch (e) {
    console.error(e);
  }
}

async function addSlide(slideshow: Slideshow) {
  if (slideType.value) {
    let newSlide = Slide.createNewSlide(slideType.value);

    // TODO
    if (slideType.value === 'PublicPlanSlideContent') {
      newSlide.name = 'Öffentlicher Zeitplan';
      newSlide.content.planId = planId.value;
    } else if (slideType.value === 'RobotGameSlideContent') {
      newSlide.name = 'Robot-Game-Ergebnisse';
    } else if (slideType.value === 'UrlSlideContent') {
      newSlide.name = 'Externer Inhalt';
    } else if (slideType.value === 'FabricSlideContent') {
      newSlide.name = 'Eigener Inhalt';
    }

    const content = JSON.stringify(newSlide.content.toJSON());
    newSlide = {...newSlide, content, order: slideshow.slides.length + 1};

    try {
      const response = await axios.put(`slideshow/${slideshow.id}/add`, newSlide);
      console.log(response.data.slide);
      slideshow.slides.push(response.data.slide);
    } catch (e) {
      console.error(e);
    }
  }
}

async function getPublicRobotGameRounds() {
  try {
    const response = await axios.get('/contao/rounds/' + event.value?.id);
    robotGameRounds.value = response.data;
  } catch (error) {
    console.error('Error fetching rounds:', error);
  }
}

async function updateRobotGameRounds(round, value) {
  robotGameRounds.value[round] = value;
  savingToast?.value?.show();
  await pushPublicRobotGameRoundsUpdate();
}

async function pushPublicRobotGameRoundsUpdate() {
  try {
    await axios.put('/contao/rounds/' + event.value?.id, robotGameRounds.value);
  } catch (e) {
    console.error('Error updating rounds:', e);
  }
}

function copyUrl(url) {
  navigator.clipboard.writeText(url);
}
</script>

<template>
  <SavingToast ref="savingToast" message="Änderungen werden gespeichert..."/>

  <div class="rounded-xl shadow-lg bg-white p-6 flex flex-col gap-6">
    <!-- Header -->
    <div class="flex items-center justify-between border-b pb-4">
      <div class="flex items-center gap-3">
        <i class="bi bi-slides text-2xl text-blue-600"></i>
        <h2 class="text-xl font-bold text-gray-800">Präsentation</h2>
      </div>
      <button
          class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg shadow-sm transition-colors disabled:bg-gray-400 disabled:cursor-not-allowed"
          :disabled="loading || !planId || !event?.id || slideshows.length >= 1"
          @click="addSlideshow">
        <i class="bi bi-plus-circle"></i>
        <span>Slideshow erstellen</span>
      </button>
    </div>

    <!-- Info Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Carousel Link -->
      <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
        <div class="flex items-center gap-2 mb-2">
          <i class="bi bi-link-45deg text-blue-600"></i>
          <h3 class="font-semibold text-gray-800">Öffentliche Ansicht</h3>
        </div>
        <div class="flex items-center gap-2">
          <a :href="carouselLink" target="_blank" rel="noopener noreferrer"
             class="text-blue-600 hover:text-blue-800 underline font-medium text-sm flex-1 truncate">
            {{ carouselLink }}
          </a>
          <button
              type="button"
              class="flex items-center gap-1 px-3 py-1.5 bg-white border border-blue-300 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors"
              @click="copyUrl(carouselLink)"
              title="Link kopieren"
          >
            <i class="bi bi-clipboard"></i>
            <span class="text-xs">Kopieren</span>
          </button>
        </div>
      </div>

      <!-- Robot Game Rounds -->
      <div class="bg-orange-50 rounded-lg p-4 border border-orange-200" v-if="robotGameRounds">
        <div class="flex items-center gap-2 mb-3">
          <i class="bi bi-trophy text-orange-600"></i>
          <h3 class="font-semibold text-gray-800">Robot Game: Öffentliche Ergebnisse</h3>
          <InfoPopover
              text="Wähle aus, welche Ergebnisse öffentlich sichtbar sein sollen. Falls eine Wettbewerbsphase noch läuft oder später (z.B. auf der Bühne) veröffentlicht werden soll, sollte diese hier nicht ausgewählt werden."/>
        </div>
        <div class="grid grid-cols-5 gap-2">
          <label class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
            <input
                type="checkbox"
                :checked="robotGameRounds.vr1"
                @change="updateRobotGameRounds('vr1', ($event.target as HTMLInputElement).checked)"
                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <span class="text-sm font-medium">VR1</span>
          </label>
          <label class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
            <input
                type="checkbox"
                :checked="robotGameRounds.vr2"
                @change="updateRobotGameRounds('vr2', ($event.target as HTMLInputElement).checked)"
                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <span class="text-sm font-medium">VR2</span>
          </label>
          <label class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
            <input
                type="checkbox"
                :checked="robotGameRounds.vr3"
                @change="updateRobotGameRounds('vr3', ($event.target as HTMLInputElement).checked)"
                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <span class="text-sm font-medium">VR3</span>
          </label>
          <label class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
            <input
                type="checkbox"
                :checked="robotGameRounds.vf"
                @change="updateRobotGameRounds('vf', ($event.target as HTMLInputElement).checked)"
                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <span class="text-sm font-medium">VF</span>
          </label>
          <label class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
            <input
                type="checkbox"
                :checked="robotGameRounds.hf"
                @change="updateRobotGameRounds('hf', ($event.target as HTMLInputElement).checked)"
                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <span class="text-sm font-medium">HF</span>
          </label>
        </div>
      </div>
    </div>

    <!-- Slideshows -->
    <div class="space-y-4" v-if="slideshows.length > 0">
      <div v-for="(slideshow, index) in slideshows" :key="slideshow.id" 
           class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-5 border border-gray-200 shadow-sm">
        
        <!-- Slideshow Header -->
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-300">
          <div class="flex items-center gap-3">
            <i class="bi bi-collection-play text-xl text-gray-600"></i>
            <h3 class="text-lg font-bold text-gray-800">{{ slideshow.name }}</h3>
            <span class="text-sm text-gray-500">({{ slideshow.slides.length }} Folie{{ slideshow.slides.length !== 1 ? 'n' : '' }})</span>
          </div>
        </div>

        <!-- Settings Row -->
        <div class="bg-white rounded-lg p-4 mb-4 border border-gray-200">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
            <!-- Transition Time -->
            <div class="flex-1">
              <label class="block text-sm font-medium text-gray-700 mb-2">
                <i class="bi bi-clock"></i> Anzeigezeit pro Folie
              </label>
              <div class="flex items-center gap-3">
                <input
                    type="range"
                    :min="1"
                    :max="60"
                    :step="1"
                    v-model.number="slideshow.transition_time"
                    @change="updateTransitionTime(slideshow)"
                    class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                    aria-label="Transition time slider"
                />
                <span class="text-sm font-semibold text-gray-700 min-w-[3rem] text-right">{{ slideshow.transition_time }}s</span>
              </div>
            </div>

            <!-- Add Slide -->
            <div class="flex-1">
              <label class="block text-sm font-medium text-gray-700 mb-2">
                <i class="bi bi-plus-square"></i> Neue Folie hinzufügen
              </label>
              <div class="flex gap-2">
                <select 
                    v-model="slideType" 
                    class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                  <option value="">Folientyp wählen...</option>
                  <option v-for="type of slideTypes" :key="type.value" :value="type.value">
                    {{ type.label }}
                  </option>
                </select>
                <button
                    class="flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white font-medium px-4 py-2 rounded-lg shadow-sm transition-colors disabled:bg-gray-400 disabled:cursor-not-allowed"
                    :disabled="!slideType"
                    @click="addSlide(slideshow)">
                  <i class="bi bi-plus-circle"></i>
                  <span>Hinzufügen</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Slides Grid -->
        <div class="bg-gray-800 rounded-xl p-4 min-h-[200px]">
          <div v-if="slideshow.slides.length === 0" class="flex flex-col items-center justify-center py-12 text-gray-400">
            <i class="bi bi-inbox text-4xl mb-3"></i>
            <p class="text-sm">Noch keine Folien vorhanden</p>
            <p class="text-xs mt-1">Wählen Sie einen Folientyp aus und klicken Sie auf "Hinzufügen"</p>
          </div>
          <draggable 
              v-else
              v-model="slideshow.slides" 
              :key="slidesKey"
              class="flex flex-wrap gap-3" 
              group="slides"
              item-key="id"
              handle=".drag-handle"
              ghost-class="drag-ghost"
              chosen-class="drag-chosen"
              drag-class="drag-dragging"
              animation="200"
              @end="updateOrder(slideshow)">
            <template #item="{ element }">
              <SlideThumb :slide="element" @deleteSlide="deleteSlide(slideshow, element.id)"/>
            </template>
          </draggable>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-300">
      <i class="bi bi-slides text-5xl text-gray-400 mb-4"></i>
      <p class="text-gray-600 font-medium mb-2">Noch keine Slideshow vorhanden</p>
      <p class="text-sm text-gray-500 mb-4">Erstellen Sie eine Slideshow, um Präsentationsfolien hinzuzufügen</p>
      <button
          class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg shadow-sm transition-colors disabled:bg-gray-400 disabled:cursor-not-allowed"
          :disabled="loading || !planId || !event?.id"
          @click="addSlideshow">
        <i class="bi bi-plus-circle"></i>
        <span>Slideshow erstellen</span>
      </button>
    </div>
  </div>
</template>

<style scoped>

.drag-ghost {
  opacity: 0.4;
  transform: scale(0.98);
}

.drag-chosen {
  background-color: #fde68a; /* yellow-200 */
  box-shadow: 0 0 0 2px #facc15; /* yellow-400 */
}

.drag-dragging {
  cursor: grabbing;
}

</style>
