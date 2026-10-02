<script setup lang="ts">
import {computed, nextTick, onUnmounted, ref, watch} from 'vue';
import {formatTimeOnly} from '@/utils/dateTimeFormat';
import {programLogoAlt, programLogoSrc} from '@/utils/images';
import {eventPrograms, programDisplayName, programId, type EventProgramRef} from '@/utils/eventPrograms';
import {defaultTableFieldLabel} from '@/utils/tableFieldLabels';

const props = withDefaults(defineProps<{
  result: any;
  programs?: EventProgramRef[];
  /** Runs tabs and scrolling. Off in previews and while the slide is hidden. */
  active?: boolean;
  /** Leaves room for the season logo at the top. */
  reserveTop?: boolean;
  emptyText?: string;
}>(), {
  programs: () => [],
  active: false,
  reserveTop: false,
  emptyText: 'Gerade keine Programmpunkte',
});

const TAB_SECONDS = 10;
const HOLD_SECONDS = 3;
const SCROLL_EM_PER_SECOND = 1.2;
const JOINT_KEY = 'joint';

type BoardSection = {
  kind: 'now' | 'next';
  groups: any[];
};

type BoardTab = {
  key: string;
  label: string;
  logoSrc: string;
  logoAlt: string;
  color: string;
  live: boolean;
  sections: BoardSection[];
};

/** Plan times are Berlin wall clock without zone; parse them as local like the pivot. */
function wallMs(value: string | null | undefined): number {
  return new Date(String(value ?? '').replace(' ', 'T')).getTime();
}

const clockOffset = ref(0);
const tickMs = ref(Date.now());
const clockTimer = setInterval(() => { tickMs.value = Date.now(); }, 15 * 1000);
const boardNow = computed(() => tickMs.value + clockOffset.value);

watch(() => props.result?.pivot, (pivot) => {
  const ms = wallMs(pivot);
  tickMs.value = Date.now();
  clockOffset.value = Number.isFinite(ms) ? ms - tickMs.value : 0;
}, {immediate: true});

function splitSections(groups: any[]): BoardSection[] {
  const now = boardNow.value;
  const running: any[] = [];
  const coming: any[] = [];
  for (const group of groups) {
    const live = group.activities.filter((a: any) => wallMs(a.start_time) <= now && wallMs(a.end_time) >= now);
    const later = group.activities.filter((a: any) => wallMs(a.start_time) > now);
    if (live.length) running.push({...group, activities: live});
    if (later.length) coming.push({...group, activities: later});
  }
  const sections: BoardSection[] = [];
  if (running.length) sections.push({kind: 'now', groups: running});
  if (coming.length) sections.push({kind: 'next', groups: coming});
  return sections;
}

function toArray(list: any): any[] {
  if (!list) return [];
  if (Array.isArray(list)) return list;
  return Object.values(list);
}

function groupProgramId(group: any): number {
  const id = Number(group?.group_meta?.first_program_id ?? 0);
  return Number.isFinite(id) && id > 0 ? id : 0;
}

const tabs = computed<BoardTab[]>(() => {
  const groups = toArray(props.result?.groups).map((g: any) => ({...g, activities: toArray(g.activities)}));
  const byProgram = new Map<number, any[]>();
  for (const group of groups) {
    const id = groupProgramId(group);
    if (!byProgram.has(id)) byProgram.set(id, []);
    byProgram.get(id)!.push(group);
  }

  const result: BoardTab[] = [];
  const joint = byProgram.get(0);
  if (joint?.length) {
    const sections = splitSections(joint);
    result.push({
      key: JOINT_KEY,
      label: 'Gemeinsam',
      logoSrc: programLogoSrc(null),
      logoAlt: 'FIRST LEGO League Logo',
      color: '#334155',
      live: sections.some((s) => s.kind === 'now'),
      sections,
    });
  }

  const ordered = eventPrograms({programs: props.programs});
  const knownIds = new Set(ordered.map((p) => programId(p)));
  const extraIds = [...byProgram.keys()].filter((id) => id > 0 && !knownIds.has(id)).sort((a, b) => a - b);

  for (const program of ordered) {
    const list = byProgram.get(programId(program));
    if (list?.length) result.push(programTab(program, list));
  }
  for (const id of extraIds) {
    const meta = byProgram.get(id)![0].group_meta ?? {};
    result.push(programTab({
      first_program: id,
      name: meta.first_program_name ?? null,
      display_name: meta.display_name ?? null,
      official_name: meta.official_name ?? null,
      logo_stem: meta.logo_stem ?? null,
    }, byProgram.get(id)!));
  }
  return result.filter((tab) => tab.sections.length);
});

function programTab(program: EventProgramRef, groups: any[]): BoardTab {
  const sections = splitSections(groups);
  return {
    key: `p${programId(program)}`,
    label: programDisplayName(program) || String(program.name ?? ''),
    logoSrc: programLogoSrc(program),
    logoAlt: programLogoAlt(program),
    color: program.color_hex ? `#${String(program.color_hex).replace(/^#/, '')}` : '#0f172a',
    live: sections.some((s) => s.kind === 'now'),
    sections,
  };
}

const activeIndex = ref(0);
const offsets = ref<number[]>([]);
const scrollSeconds = ref<number[]>([]);
const tabSeconds = ref(TAB_SECONDS);
const rotates = computed(() => tabs.value.length > 1);
const progressRun = ref(0);
const viewportEls: (HTMLElement | null)[] = [];
const trackEls: (HTMLElement | null)[] = [];
let timers: ReturnType<typeof setTimeout>[] = [];

function schedule(ms: number, fn: () => void) {
  timers.push(setTimeout(fn, ms));
}

function clearTimers() {
  timers.forEach(clearTimeout);
  timers = [];
}

function setScroll(index: number, offset: number, seconds: number) {
  const nextOffsets = [...offsets.value];
  const nextSeconds = [...scrollSeconds.value];
  nextOffsets[index] = offset;
  nextSeconds[index] = seconds;
  offsets.value = nextOffsets;
  scrollSeconds.value = nextSeconds;
}

function overflowOf(index: number): { px: number; emPx: number } {
  const viewport = viewportEls[index];
  const track = trackEls[index];
  if (!viewport || !track) return {px: 0, emPx: 16};
  const emPx = parseFloat(getComputedStyle(track).fontSize) || 16;
  return {px: Math.max(0, track.scrollHeight - viewport.clientHeight), emPx};
}

async function runTab(index: number) {
  clearTimers();
  activeIndex.value = index;
  setScroll(index, 0, 0);
  if (!props.active || !tabs.value.length) return;

  await nextTick();
  const {px, emPx} = overflowOf(index);
  const scroll = px > 0 ? px / (emPx * SCROLL_EM_PER_SECOND) : 0;
  if (px > 0) {
    schedule(HOLD_SECONDS * 1000, () => setScroll(index, -px, scroll));
  }
  if (!rotates.value && px === 0) return;

  const needed = px > 0 ? HOLD_SECONDS * 2 + scroll : 0;
  tabSeconds.value = rotates.value ? Math.max(TAB_SECONDS, needed) : needed;
  progressRun.value++;
  schedule(tabSeconds.value * 1000, advance);
}

function advance() {
  runTab((activeIndex.value + 1) % tabs.value.length);
}

function handleArrow(direction: 'left' | 'right'): boolean {
  const target = activeIndex.value + (direction === 'right' ? 1 : -1);
  if (target < 0 || target >= tabs.value.length) return false;
  runTab(target);
  return true;
}

defineExpose({handleArrow});

watch(() => props.active, (active) => {
  if (active) {
    runTab(0);
  } else {
    clearTimers();
    activeIndex.value = 0;
    offsets.value = [];
    scrollSeconds.value = [];
  }
}, {immediate: true});

watch(() => tabs.value.map((t) => t.key).join('|'), (keys, previous) => {
  if (!props.active) return;
  if (!previous || activeIndex.value >= tabs.value.length || !timers.length) {
    runTab(0);
  }
});

onUnmounted(() => {
  clearTimers();
  clearInterval(clockTimer);
});

function roomLabel(a: any): string {
  const r = a?.room;
  if (r?.room_name) return r.room_name;
  if (a?.room_name) return a.room_name;
  if (r?.room_type_name) return r.room_type_name;
  return '';
}

function teamLabel(name?: string | null): string {
  const nm = (name ?? '').trim();
  return nm || '–';
}

function tableName(a: any, side: 1 | 2): string {
  const name = side === 1 ? a?.table_1_name : a?.table_2_name;
  const num = side === 1 ? a?.table_1 : a?.table_2;
  const fp = Number(a?.first_program_id ?? a?.activity_first_program_id ?? 3);
  const count = (Number(a?.table_1) === 3 || Number(a?.table_1) === 4
    || Number(a?.table_2) === 3 || Number(a?.table_2) === 4) ? 4 : 0;
  return name ?? (num != null ? defaultTableFieldLabel(fp, Number(num), count) : '');
}

function hasTables(a: any): boolean {
  return a?.table_1 != null || a?.table_2 != null;
}

function hasSingleTeam(a: any): boolean {
  return a?.team != null || !!(a?.team_name?.trim());
}

function groupDescription(group: any): string {
  const own = String(group?.group_meta?.description ?? '').trim();
  if (own) return own;
  const acts = group?.activities ?? [];
  return acts.length === 1 ? String(acts[0]?.meta?.description ?? '').trim() : '';
}

function countdownLabel(a: any): string {
  const minutes = Math.max(1, Math.ceil((wallMs(a.start_time) - boardNow.value) / 60000));
  return `in ${minutes} Min.`;
}
</script>

<template>
  <div class="board" :class="{ 'board--reserve-top': props.reserveTop }">
    <div v-if="tabs.length" class="board-tabs" role="tablist">
      <div
          v-for="(tab, index) in tabs"
          :key="tab.key"
          class="board-tab"
          :class="{ 'board-tab--active': index === activeIndex }"
          :style="{ '--tab-color': tab.color }"
          role="tab"
          :aria-selected="index === activeIndex"
      >
        <img :src="tab.logoSrc" :alt="tab.logoAlt" class="board-tab-logo"/>
        <span class="board-tab-label">{{ tab.label }}</span>
        <span v-if="tab.live" class="board-live-dot" aria-label="läuft gerade"/>
        <span
            v-if="index === activeIndex && props.active && rotates"
            :key="progressRun"
            class="board-tab-progress"
            :style="{ animationDuration: `${tabSeconds}s` }"
        />
      </div>
    </div>

    <div v-if="tabs.length" class="board-stage">
      <div class="board-panels" :style="{ transform: `translateX(-${activeIndex * 100}%)` }">
        <div
            v-for="(tab, index) in tabs"
            :key="tab.key"
            :ref="(el) => { viewportEls[index] = el as HTMLElement | null }"
            class="board-viewport"
            :aria-hidden="index !== activeIndex"
        >
          <div
              :ref="(el) => { trackEls[index] = el as HTMLElement | null }"
              class="board-track"
              :style="{
                transform: `translateY(${offsets[index] ?? 0}px)`,
                transitionDuration: `${scrollSeconds[index] ?? 0}s`,
              }"
          >
            <template v-for="section in tab.sections" :key="section.kind">
            <div class="board-section" :class="`board-section--${section.kind}`">
              <span v-if="section.kind === 'now'" class="board-live-dot" aria-hidden="true"/>
              <i v-else class="bi bi-clock" aria-hidden="true"/>
              {{ section.kind === 'now' ? 'Läuft gerade' : 'Als nächstes' }}
            </div>
            <section
                v-for="g in section.groups"
                :key="`${section.kind}-${g.activity_group_id}`"
                class="board-group"
                :class="`board-group--${section.kind}`"
            >
              <h2 class="board-group-title">
                {{ g.group_meta?.name ?? '' }}
                <span v-if="groupDescription(g)" class="board-group-description">{{ groupDescription(g) }}</span>
              </h2>
              <div v-for="a in g.activities" :key="a.activity_id" class="board-row">
                <span v-if="section.kind === 'now'" class="board-time board-time--now">
                  bis {{ formatTimeOnly(a.end_time, true) }}
                </span>
                <span v-else class="board-time">
                  {{ formatTimeOnly(a.start_time, true) }}
                  <span class="board-countdown">{{ countdownLabel(a) }}</span>
                </span>
                <span class="board-main">
                  <span v-if="hasTables(a)" class="board-match">
                    <span class="board-side">
                      <span class="board-label">{{ tableName(a, 1) }}</span>
                      <span class="board-team">{{ teamLabel(a.table_1_team_name) }}</span>
                    </span>
                    <span class="board-side board-side--right">
                      <span class="board-team">{{ teamLabel(a.table_2_team_name) }}</span>
                      <span class="board-label">{{ tableName(a, 2) }}</span>
                    </span>
                  </span>
                  <span v-else-if="hasSingleTeam(a)" class="board-team">{{ teamLabel(a.team_name) }}</span>
                </span>
                <span class="board-room">
                  <template v-if="roomLabel(a)">
                    <i class="bi bi-geo" aria-hidden="true"/>
                    {{ roomLabel(a) }}
                  </template>
                </span>
              </div>
            </section>
            </template>
          </div>
        </div>
      </div>
    </div>

    <div v-else-if="props.result" class="board-empty">{{ props.emptyText }}</div>
  </div>
</template>

<style scoped>
.board {
  --board-pad-x: 4vw;
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 0.6em;
  padding: 4vh var(--board-pad-x) 3vh;
  box-sizing: border-box;
  font-size: clamp(16px, 3.4vh, 64px);
  color: #0f172a;
}

.board--reserve-top {
  padding-top: 25vh;
}

.board-tabs {
  display: flex;
  gap: 0.5em;
  flex-shrink: 0;
}

.board-tab {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.45em;
  padding: 0.35em 0.9em 0.45em 0.5em;
  border-radius: 0.5em;
  background: rgba(255, 255, 255, 0.55);
  color: #475569;
  font-size: 0.85em;
  font-weight: 600;
  overflow: hidden;
  transition: background-color 400ms ease, color 400ms ease, transform 400ms ease;
}

.board-tab--active {
  background: rgba(255, 255, 255, 0.96);
  color: #0f172a;
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.15);
  transform: translateY(-0.08em);
}

.board-tab .board-live-dot {
  width: 0.4em;
  height: 0.4em;
}

.board-tab-logo {
  width: 1.5em;
  height: 1.5em;
  object-fit: contain;
  flex-shrink: 0;
}

.board-tab-progress {
  position: absolute;
  left: 0;
  bottom: 0;
  height: 0.16em;
  width: 100%;
  background: var(--tab-color);
  transform-origin: left center;
  animation-name: board-progress;
  animation-timing-function: linear;
  animation-fill-mode: forwards;
}

@keyframes board-progress {
  from { transform: scaleX(0); }
  to { transform: scaleX(1); }
}

.board-stage {
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

.board-panels {
  display: flex;
  height: 100%;
  transition: transform 700ms cubic-bezier(0.65, 0, 0.35, 1);
}

.board-viewport {
  flex: 0 0 100%;
  height: 100%;
  overflow: hidden;
  -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 0.6em, #000 calc(100% - 1.2em), transparent 100%);
  mask-image: linear-gradient(to bottom, transparent 0, #000 0.6em, #000 calc(100% - 1.2em), transparent 100%);
}

.board-track {
  display: flex;
  flex-direction: column;
  gap: 0.5em;
  padding: 0.6em 0 1.2em;
  transition-property: transform;
  transition-timing-function: linear;
  will-change: transform;
}

.board-live-dot {
  width: 0.5em;
  height: 0.5em;
  border-radius: 50%;
  background: #16a34a;
  flex-shrink: 0;
  animation: board-live 1.6s ease-out infinite;
}

@keyframes board-live {
  0% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.6); }
  70% { box-shadow: 0 0 0 0.45em rgba(22, 163, 74, 0); }
  100% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0); }
}

.board-section {
  display: flex;
  align-items: center;
  gap: 0.45em;
  align-self: flex-start;
  margin-top: 0.3em;
  padding: 0.15em 0.65em;
  border-radius: 999px;
  font-size: 0.8em;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.board-section:first-child {
  margin-top: 0;
}

.board-section--now {
  background: #16a34a;
  color: #fff;
}

.board-section--now .board-live-dot {
  background: #fff;
}

.board-section--next {
  background: rgba(255, 255, 255, 0.9);
  color: #334155;
}

.board-group {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr) max-content;
  column-gap: 1em;
  background: rgba(255, 255, 255, 0.92);
  border-radius: 0.4em;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
  overflow: hidden;
}

.board-group--now {
  background: #fff;
  box-shadow: inset 0.3em 0 0 #16a34a, 0 4px 18px rgba(22, 163, 74, 0.25);
}

.board-group--now .board-group-title {
  background: #f0fdf4;
  border-bottom-color: #bbf7d0;
}

.board-group--next {
  background: rgba(255, 255, 255, 0.82);
}

.board-group--next .board-group-title {
  background: rgba(248, 250, 252, 0.8);
}

.board-group-title {
  grid-column: 1 / -1;
  margin: 0;
  padding: 0.35em 0.6em;
  font-size: 1.05em;
  font-weight: 700;
  line-height: 1.2;
  background: #f8fafc;
  border-bottom: 2px solid #e2e8f0;
}

.board-row {
  display: contents;
}

.board-row > * {
  padding: 0.3em 0;
  border-bottom: 1px solid #e2e8f0;
  line-height: 1.25;
  min-width: 0;
}

.board-row:last-child > * {
  border-bottom: none;
}

.board-time {
  padding-left: 0.6em !important;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.board-group--now .board-time {
  padding-left: 0.9em !important;
}

.board-time--now {
  color: #15803d;
}

.board-countdown {
  margin-left: 0.3em;
  font-size: 0.7em;
  font-weight: 600;
  color: #64748b;
}

.board-room {
  padding-right: 0.6em !important;
  color: #475569;
  text-align: right;
  white-space: nowrap;
}

.board-room .bi-geo {
  margin-right: 0.15em;
  font-size: 0.85em;
  color: #94a3b8;
}

.board-main {
  overflow: hidden;
}

.board-match {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  column-gap: 1em;
}

.board-side {
  display: flex;
  align-items: baseline;
  gap: 0.4em;
  min-width: 0;
}

.board-side--right {
  justify-content: flex-end;
}

.board-label {
  font-size: 0.7em;
  color: #64748b;
  white-space: nowrap;
}

.board-team {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.board-group-description {
  display: -webkit-box;
  margin-top: 0.15em;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  font-size: 0.72em;
  font-weight: 500;
  line-height: 1.3;
  color: #475569;
}

.board-empty {
  margin: auto;
  padding: 0.6em 1.2em;
  border-radius: 0.5em;
  background: rgba(255, 255, 255, 0.85);
  font-size: 1.2em;
  font-weight: 600;
  color: #475569;
}
</style>
