<script setup lang="ts">

import {computed, onMounted, onBeforeUnmount, ref} from "vue";
import {useRouter} from "vue-router";
import axios from "axios";
import {Slide} from "@/models/slide";
import FabricEditor from "@/components/FabricEditor.vue";
import InfoPopover from "@/components/atoms/InfoPopover.vue";
import SavingToast from "@/components/atoms/SavingToast.vue";

const router = useRouter();
const props = defineProps<{
  slideId: Number,
}>();

const slide = ref<Slide | null>(null);
const savingToast = ref(null);
const hasUnsavedChanges = ref(false);
const isSaving = ref(false);
const saveTimeoutId = ref<NodeJS.Timeout | null>(null);
const showIndicatorTimeoutId = ref<NodeJS.Timeout | null>(null);
const SAVE_DELAY = 5000; // 5 seconds delay
const SHOW_INDICATOR_DELAY = 1000; // Show "unsaved changes" after 1 second

const settingsSlideTypes = ['RobotGameSlideContent', 'PublicPlanSlideContent', 'UrlSlideContent'];

const hasSettings = computed<boolean>(() => {
  if (!slide.value) {
    return false;
  }
  const type = slide.value.type;
  return settingsSlideTypes.includes(type);
})

const saveButtonText = computed(() => {
  if (isSaving.value) {
    return 'Speichere...';
  }
  if (hasUnsavedChanges.value) {
    return 'Änderungen werden gespeichert...';
  }
  return 'Alle Änderungen gespeichert';
});

onMounted(loadSlide);
onBeforeUnmount(() => {
  // Save any pending changes before leaving
  if (saveTimeoutId.value) {
    clearTimeout(saveTimeoutId.value);
  }
  if (showIndicatorTimeoutId.value) {
    clearTimeout(showIndicatorTimeoutId.value);
  }
  if (hasUnsavedChanges.value) {
    saveSlide();
  }
});

async function loadSlide() {
  const response = await axios.get(`slides/${props.slideId}`)
  if (response && response.data) {
    slide.value = Slide.fromObject(response.data);
  }
  return null;
}

function scheduleSave() {
  // Clear existing timeouts
  if (saveTimeoutId.value) {
    clearTimeout(saveTimeoutId.value);
  }
  if (showIndicatorTimeoutId.value) {
    clearTimeout(showIndicatorTimeoutId.value);
  }
  
  // Show unsaved changes indicator after a delay (so it doesn't flash on every click)
  showIndicatorTimeoutId.value = setTimeout(() => {
    hasUnsavedChanges.value = true;
  }, SHOW_INDICATOR_DELAY);
  
  // Schedule new save
  saveTimeoutId.value = setTimeout(() => {
    saveSlide();
  }, SAVE_DELAY);
}

async function saveSlide() {
  if (!slide.value || isSaving.value) return;
  
  // Clear indicator timeout if save happens before it shows
  if (showIndicatorTimeoutId.value) {
    clearTimeout(showIndicatorTimeoutId.value);
    showIndicatorTimeoutId.value = null;
  }
  
  isSaving.value = true;
  const s = {...slide.value, content: slide.value.content.toJSON()};
  
  try {
    await axios.put(`slides/${slide.value.id}`, s);
    console.log('Slide saved:', s);
    hasUnsavedChanges.value = false;
    if (saveTimeoutId.value) {
      clearTimeout(saveTimeoutId.value);
      saveTimeoutId.value = null;
    }
  } catch (error) {
    console.error('Error saving slide:', error);
  } finally {
    isSaving.value = false;
  }
}

function updateByName(name: string, value: any) {
  if (!slide.value) return;
  slide.value.content[name] = value;
  scheduleSave();
  savingToast?.value?.show();
}

function handleManualSave() {
  if (saveTimeoutId.value) {
    clearTimeout(saveTimeoutId.value);
    saveTimeoutId.value = null;
  }
  saveSlide();
}

</script>

<template>
  <SavingToast ref="savingToast" message="Änderungen werden gespeichert..."/>

  <!-- Header -->
  <div class="flex items-center justify-between border-b pb-4 mb-6 mt-4">
    <router-link 
        to="/plan/presentation" 
        class="flex items-center gap-2 text-gray-600 hover:text-gray-800 transition-colors"
    >
      <i class="bi bi-arrow-left"></i>
      <span>Zurück zur Slideshow</span>
    </router-link>
    
    <button
        @click="handleManualSave"
        :disabled="isSaving || !hasUnsavedChanges"
        :class="[
          'flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-colors',
          hasUnsavedChanges
            ? 'bg-blue-600 hover:bg-blue-700 text-white'
            : isSaving
            ? 'bg-gray-400 text-white cursor-wait'
            : 'bg-gray-100 text-gray-600 cursor-default'
        ]"
    >
      <i v-if="isSaving" class="bi bi-hourglass-split animate-spin"></i>
      <i v-else-if="!hasUnsavedChanges" class="bi bi-check-circle"></i>
      <span>{{ saveButtonText }}</span>
    </button>
  </div>

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
      <FabricEditor :slide="slide" @change="scheduleSave" v-if="!!slide"></FabricEditor>
    </div>
  </div>
</template>

<style scoped>

</style>