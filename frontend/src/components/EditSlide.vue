<script setup lang="ts">

import {computed, onMounted, onBeforeUnmount, ref} from "vue";
import {useRouter} from "vue-router";
import axios from "axios";
import {Slide} from "@/models/slide";
import FabricEditor from "@/components/FabricEditor.vue";
import ColorField from "@/components/slideEditor/ColorField.vue";
import NumberField from "@/components/slideEditor/NumberField.vue";
import SearchSelect from "@/components/slideEditor/SearchSelect.vue";
import ProgramLogo from "@/components/atoms/ProgramLogo.vue";
import SavingToast from "@/components/atoms/SavingToast.vue";
import {useEventStore} from "../stores/event";
import {eventPrograms, programDisplayName, programId} from "@/utils/eventPrograms";
import {resolvedAudienceSelection, type AbstractPublicPlanSlideContent} from "@/models/abstractPublicPlanSlideContent";

const router = useRouter();
const props = defineProps<{
  slideId: Number,
}>();

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const slide = ref<Slide | null>(null);
const rooms = ref([]);

const savingToast = ref(null);
const hasUnsavedChanges = ref(false);
const isSaving = ref(false);
const saveTimeoutId = ref<NodeJS.Timeout | null>(null);
const showIndicatorTimeoutId = ref<NodeJS.Timeout | null>(null);
const SAVE_DELAY = 5000; // 5 seconds delay
const SHOW_INDICATOR_DELAY = 1000; // Show "unsaved changes" after 1 second

const saveButtonText = computed(() => {
  if (isSaving.value) {
    return 'Speichere…';
  }
  if (hasUnsavedChanges.value) {
    return 'Speichern';
  }
  return 'Alle Änderungen gespeichert';
});

const shouldLoadRooms = ['PublicPlanSlideContent', 'PublicPlanNextSlideContent', 'PublicPlanNextEventSlideContent'];
const isPublicPlan = computed(() => shouldLoadRooms.includes(slide.value?.type ?? ''));
const isPlanLookahead = computed(() => ['PublicPlanNextSlideContent', 'PublicPlanNextEventSlideContent'].includes(slide.value?.type ?? ''));

const roomOptions = computed(() => [
  {value: 0, label: 'Alle Räume', description: 'Alle Aktivitäten der Veranstaltung', icon: 'bi-grid-3x3-gap'},
  ...rooms.value.map((room: any) => {
    const types = (room.room_types ?? []).map((t: any) => t.name).filter(Boolean).join(', ');
    return {
      value: room.id,
      label: room.name,
      description: types || room.navigation_instruction || undefined,
      keywords: room.navigation_instruction ?? '',
      icon: 'bi-door-open',
      badges: room.is_accessible ? [{icon: 'bi-universal-access', title: 'Barrierefrei'}] : [],
    };
  }),
]);

const attachedPrograms = computed(() => eventPrograms(event.value));

const audienceSelection = computed(() => {
  const content = slide.value?.content as AbstractPublicPlanSlideContent | undefined;
  if (!content || content.legacyRole === undefined && content.joint === undefined) {
    return {joint: false, programs: [] as number[]};
  }
  return resolvedAudienceSelection(content, event.value);
});

function materializeAudienceSelection() {
  const content = slide.value?.content as AbstractPublicPlanSlideContent | undefined;
  if (!content || content.legacyRole == null) return;
  if (content.legacyRole === 14 && !event.value) return;
  const resolved = resolvedAudienceSelection(content, event.value);
  content.joint = resolved.joint;
  content.programs = resolved.programs;
  content.legacyRole = null;
}

onMounted(async () => {
  await loadSlide();
  if (shouldLoadRooms.includes(slide.value.type)) {
    await loadRooms();
  }
});
onBeforeUnmount(() => {
  // Save any pending changes before leaving (the indicator only appears after a delay)
  const hasPendingSave = hasUnsavedChanges.value || !!saveTimeoutId.value;
  if (saveTimeoutId.value) {
    clearTimeout(saveTimeoutId.value);
  }
  if (showIndicatorTimeoutId.value) {
    clearTimeout(showIndicatorTimeoutId.value);
  }
  if (hasPendingSave) {
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

async function loadRooms() {
  const {data: roomsData} = await axios.get(`/events/${event.value.id}/rooms`)
  rooms.value = Array.isArray(roomsData) ? roomsData : (roomsData?.rooms ?? [])
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

  materializeAudienceSelection();

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

function setJoint(on: boolean) {
  materializeAudienceSelection();
  updateByName('joint', on);
}

function toggleProgram(id: number, on: boolean) {
  materializeAudienceSelection();
  const content = slide.value?.content as AbstractPublicPlanSlideContent | undefined;
  if (!content) return;
  const next = (content.programs ?? []).map((program) => Number(program)).filter((program) => program !== id);
  if (on) next.push(id);
  updateByName('programs', next);
}

const teamSlideTypes = ['TeamsTableSlideContent', 'TeamsMapSlideContent'];

function teamPrograms(): number[] | null | undefined {
  const content = slide.value?.content as {programs?: number[] | null} | undefined;
  if (!content || !teamSlideTypes.includes(slide.value?.type ?? '')) {
    return undefined;
  }
  return content.programs ?? null;
}

function teamProgramChecked(id: number): boolean {
  const programs = teamPrograms();
  return programs == null || programs.includes(id);
}

function toggleTeamProgram(id: number, on: boolean) {
  const programs = teamPrograms();
  if (programs === undefined) return;
  const base = programs == null
      ? attachedPrograms.value.map((program) => programId(program)).filter((program) => program > 0)
      : programs.map((program) => Number(program));
  const next = base.filter((program) => program !== id);
  if (on) next.push(id);
  updateByName('programs', next);
}

function handleManualSave() {
  if (saveTimeoutId.value) {
    clearTimeout(saveTimeoutId.value);
    saveTimeoutId.value = null;
  }
  saveSlide();
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

function updateSlideDurationOverride(event: Event) {
  const target = event.target as HTMLInputElement;
  const isChecked = target.checked;
  if (isChecked) {
    updateDuration(15);
  } else {
    updateDuration(0);
  }
}

function updateDuration(value: number) {
  slide.value.transition_time = value;
  scheduleSave();
}

</script>

<template>
  <div class="flex flex-col min-h-0">
  <SavingToast ref="savingToast" message="Änderungen werden gespeichert…"/>

  <!-- Header -->
  <div class="flex shrink-0 items-center justify-between border-b pb-3 mb-4 mt-2">
    <router-link
        to="/plan/publish/digital"
        class="flex items-center gap-2 text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
    >
      <i class="bi bi-arrow-left"></i>
      <span>Zurück zur Slideshow</span>
    </router-link>

    <span v-if="!!slide" class="font-bold">{{ slide.name }}</span>

    <button
        @click="handleManualSave"
        :disabled="isSaving || !hasUnsavedChanges"
        :class="[
          'flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition-colors',
          hasUnsavedChanges
            ? 'bg-blue-600 hover:bg-blue-700 text-white'
            : isSaving
            ? 'bg-gray-400 text-white cursor-wait'
            : 'bg-[var(--color-bg-muted)] text-[var(--color-text-muted)] cursor-default'
        ]"
    >
      <i v-if="isSaving" class="bi bi-hourglass-split animate-spin"></i>
      <i v-else-if="!hasUnsavedChanges" class="bi bi-check-circle"></i>
      <span>{{ saveButtonText }}</span>
    </button>
  </div>

  <div v-if="!!slide" class="glass-card liquid-surface-inner !p-0 flex flex-1 flex-col min-h-0">
    <div v-if="slide.type !== 'FabricSlideContent'" class="shrink-0 px-5 py-2.5 text-sm text-[var(--color-text-muted)]">
      <i class="bi bi-info-circle"></i>
      Hier gestaltest du den <strong>Hintergrund</strong>. Die Inhalte der Folie werden bei der Anzeige darüber gelegt.
    </div>
    <FabricEditor :slide="slide"
                  :default-panel="slide.type === 'FabricSlideContent' ? 'design' : 'settings'"
                  @change="scheduleSave">
      <template #settings>
        <section class="se-section">
          <h3 class="se-section__title">Anzeige</h3>
          <label class="flex items-center justify-between gap-2 cursor-pointer">
            <span class="se-label">Saison-Logo anzeigen</span>
            <input type="checkbox" class="se-switch" :checked="slide.content.showSeasonLogo"
                   @change="updateByName('showSeasonLogo', ($event.target as HTMLInputElement).checked)"/>
          </label>
          <!-- Eigene Anzeigezeit - Alle Slides außer Robot Game -->
          <template v-if="slide.type !== 'RobotGameSlideContent'">
            <label class="flex items-center justify-between gap-2 cursor-pointer">
              <span class="se-label">Eigene Anzeigedauer</span>
              <input type="checkbox" class="se-switch" :checked="slide.transition_time !== 0"
                     @change="updateSlideDurationOverride"/>
            </label>
            <div v-if="slide.transition_time !== 0" class="flex items-center justify-between gap-2">
              <span class="se-label">Sekunden</span>
              <NumberField class="w-24" :min="1" :max="600" suffix="s" :model-value="slide.transition_time"
                           @update:model-value="updateDuration"/>
            </div>
            <p class="se-hint">Ohne eigene Dauer gilt die Zeit pro Folie der Slideshow.</p>
          </template>
        </section>

        <section v-if="isPublicPlan" class="se-section">
          <h3 class="se-section__title">Zeitplan</h3>
          <div v-if="isPlanLookahead" class="flex items-center justify-between gap-2">
            <span class="se-label">Vorausschau</span>
            <NumberField class="w-24" :min="1" :max="120" suffix="min" :model-value="slide.content.interval"
                         @update:model-value="updateByName('interval', $event)"/>
          </div>
          <div class="flex flex-col gap-1">
            <span class="se-label">Raum</span>
            <SearchSelect :model-value="Number(slide.content.room ?? 0)" :options="roomOptions"
                          placeholder="Raum suchen…" empty-text="Kein Raum gefunden"
                          @update:model-value="updateByName('room', Number($event))"/>
          </div>
          <div class="flex flex-col gap-1">
            <span class="se-label">Sichtbare Programmpunkte</span>
            <div class="program-list">
              <label class="program-list__row">
                <input type="checkbox" :checked="audienceSelection.joint"
                       @change="setJoint(($event.target as HTMLInputElement).checked)"/>
                <i class="bi bi-intersect program-list__icon" aria-hidden="true"></i>
                <span>Übergreifend</span>
              </label>
              <label v-for="program in attachedPrograms" :key="programId(program)" class="program-list__row">
                <input type="checkbox" :checked="audienceSelection.programs.includes(programId(program))"
                       @change="toggleProgram(programId(program), ($event.target as HTMLInputElement).checked)"/>
                <ProgramLogo :program="program" size="chip" decorative/>
                <span>{{ programDisplayName(program) }}</span>
              </label>
            </div>
            <p v-if="!audienceSelection.joint && audienceSelection.programs.length === 0" class="se-hint se-hint--warn">
              <i class="bi bi-exclamation-triangle"></i> Nichts ausgewählt. Es werden keine Programmpunkte angezeigt.
            </p>
          </div>
        </section>

        <section v-if="slide.type === 'TeamsTableSlideContent' || slide.type === 'TeamsMapSlideContent'"
                 class="se-section">
          <h3 class="se-section__title">Teams</h3>
          <span class="se-label">Sichtbare Programme</span>
          <div class="program-list">
            <label v-for="program in attachedPrograms" :key="programId(program)" class="program-list__row">
              <input type="checkbox" :checked="teamProgramChecked(programId(program))"
                     @change="toggleTeamProgram(programId(program), ($event.target as HTMLInputElement).checked)"/>
              <ProgramLogo :program="program" size="chip" decorative/>
              <span>{{ programDisplayName(program) }}</span>
            </label>
          </div>
          <p v-if="Array.isArray(slide.content.programs) && slide.content.programs.length === 0"
             class="se-hint se-hint--warn">
            <i class="bi bi-exclamation-triangle"></i> Nichts ausgewählt. Es werden keine Teams angezeigt.
          </p>
        </section>

        <section v-if="slide.type === 'RobotGameSlideContent'" class="se-section">
          <h3 class="se-section__title">Tabelle</h3>
          <div class="flex items-center justify-between gap-2">
            <span class="se-label">Teams pro Seite</span>
            <NumberField class="w-24" :min="1" :model-value="Number(slide.content.teamsPerPage)"
                         @update:model-value="updateByName('teamsPerPage', $event)"/>
          </div>
          <div class="flex items-center justify-between gap-2">
            <span class="se-label">Sekunden pro Seite</span>
            <NumberField class="w-24" :min="1" suffix="s" :model-value="Number(slide.content.secondsPerPage)"
                         @update:model-value="updateByName('secondsPerPage', $event)"/>
          </div>
          <ColorField label="Textfarbe" :model-value="slide.content.textColor"
                      @update:model-value="updateByName('textColor', $event || '#222222')"/>
          <ColorField label="Hervorhebung" :model-value="slide.content.highlightColor"
                      @update:model-value="updateByName('highlightColor', $event || '#FFD700')"/>
          <ColorField label="Rahmenfarbe" :model-value="slide.content.tableBorderColor"
                      @update:model-value="updateByName('tableBorderColor', $event || '#000000')"/>
          <ColorField label="Hintergrund" :model-value="tableBgHex ?? '#ffffff'"
                      @update:model-value="setTableBackgroundFromHexAndOpacity($event || '#ffffff', tableBgOpacity ?? 100)"/>
          <label class="se-range">
            <span class="se-label">Deckkraft Hintergrund</span>
            <input type="range" min="0" max="100" step="1" :value="tableBgOpacity ?? 100"
                   @input="setTableBackgroundFromHexAndOpacity(tableBgHex ?? '#ffffff', Number(($event.target as HTMLInputElement).value))"/>
            <span class="se-range__value">{{ tableBgOpacity ?? 100 }}%</span>
          </label>
        </section>

        <section v-if="slide.type === 'UrlSlideContent'" class="se-section">
          <h3 class="se-section__title">Website</h3>
          <label class="flex flex-col gap-1">
            <span class="se-label">URL</span>
            <input class="se-input" type="url" placeholder="https://…" :value="slide.content.url"
                   @input="updateByName('url', ($event.target as HTMLInputElement).value || '')"/>
          </label>
          <p class="se-hint">Die Website wird auf der ganzen Folie angezeigt.</p>
        </section>
      </template>
    </FabricEditor>
  </div>
  </div>
</template>

<style scoped>
.program-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 0.3rem;
  border-radius: 10px;
  border: 1px solid var(--color-border-strong);
  background: var(--color-bg-elevated);
}

.program-list__row {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  min-height: 2.1rem;
  padding: 0.2rem 0.45rem;
  border-radius: 7px;
  font-size: 0.85rem;
  cursor: pointer;
}

.program-list__row:hover {
  background: var(--color-bg-hover);
}

.program-list__row input {
  accent-color: var(--color-accent);
}

.program-list__icon {
  font-size: 1rem;
  line-height: 1;
  flex-shrink: 0;
}
</style>