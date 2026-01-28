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

const tableBgHex = computed<string | undefined>({
  get: () => {
    if (slide.value?.content?.tableBackgroundColor) {
      return parseRgbaString(slide.value?.content.tableBackgroundColor || '#ffffff').hex;
    }
    return undefined;
  },
  set: (value: string | undefined) => {
    if (value) {
      setTableBackgroundFromHexAndOpacity(value, tableBgOpacity.value);
    }
  }
});

const tableBgOpacity = computed<number | undefined>({
  get: () => {
    if (slide.value?.content.tableBackgroundColor) {
      return parseRgbaString(slide.value?.content.tableBackgroundColor || '#ffffff').alphaPercent;
    }
    return undefined;
  },
  set: (value: number | undefined) => {
    if (value !== undefined) {
      setTableBackgroundFromHexAndOpacity(tableBgHex.value, value);
    }
  }
});

function hexToRgb(hex: string): { r: number, g: number, b: number } {
  let h = hex.replace('#', '').trim();
  if (h.length === 3) {
    h = h.split('').map(c => c + c).join('');
  }
  const r = parseInt(h.substring(0, 2), 16);
  const g = parseInt(h.substring(2, 4), 16);
  const b = parseInt(h.substring(4, 6), 16);
  return {r, g, b};
}

function toHex(n: number): string {
  return n.toString(16).padStart(2, '0');
}

function rgbToHex(r: number, g: number, b: number): string {
  return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
}

function parseRgbaString(value: string): { hex: string, alphaPercent: number } {
  if (!value) return {hex: '#ffffff', alphaPercent: 100};
  const rgbaMatch = value.match(/rgba?\(\s*(\d+),\s*(\d+),\s*(\d+)(?:,\s*([0-9.]+))?\s*\)/i);
  if (rgbaMatch) {
    const r = parseInt(rgbaMatch[1], 10);
    const g = parseInt(rgbaMatch[2], 10);
    const b = parseInt(rgbaMatch[3], 10);
    const a = rgbaMatch[4] !== undefined ? parseFloat(rgbaMatch[4]) : 1;
    return {hex: rgbToHex(r, g, b), alphaPercent: Math.round(a * 100)};
  }
  if (value.startsWith('#')) {
    return {hex: value, alphaPercent: 100};
  }
  return {hex: '#ffffff', alphaPercent: 100};
}

function setTableBackgroundFromHexAndOpacity(hex: string, opacityPercent: number) {
  const {r, g, b} = hexToRgb(hex);
  const a = Math.max(0, Math.min(100, Number(opacityPercent))) / 100;
  const rgba = `rgba(${r}, ${g}, ${b}, ${a})`;
  updateByName('tableBackgroundColor', rgba);
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

          <!-- Tabellen-Hintergrundfarbe -->
          <div>
            <label class="text-sm font-medium">Tabellen-Hintergrundfarbe</label>
            <InfoPopover text="Hintergrundfarbe der Tabelle; Transparenz in Prozent einstellen."/>
          </div>
          <div class="flex items-center gap-2">
            <input
                type="color"
                class="mt-1 border rounded px-2 py-1"
                v-model="tableBgHex"
                @input="() => setTableBackgroundFromHexAndOpacity(tableBgHex, tableBgOpacity)"
            />
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 px-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path d="M12 2.69L6 10.5c-3 4 1 11.5 6 11.5s9-7.5 6-11.5L12 2.69z"/>
            </svg>
            <input
                type="number"
                min="0"
                max="100"
                class="w-16 mt-1 border rounded px-2 py-1"
                v-model.number="tableBgOpacity"
                @input="() => setTableBackgroundFromHexAndOpacity(tableBgHex, tableBgOpacity)"
                aria-label="Transparenz in Prozent"
            />
            %
          </div>

          <!-- Tabellen Rahmenfarbe -->
          <div>
            <label class="text-sm font-medium">Tabellen-Rahmenfarbe</label>
            <InfoPopover text="Farbe der Tabellenränder."/>
          </div>
          <div>
            <input
                type="color"
                class="mt-1 w-full border rounded px-2 py-1"
                :value="slide.content.tableBorderColor"
                @input="updateByName('tableBorderColor', ($event.target as HTMLInputElement).value || '#000000')"
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