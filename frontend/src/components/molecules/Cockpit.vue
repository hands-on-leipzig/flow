<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import {useRouter} from 'vue-router'
import axios from 'axios'
import VolunteerStaffingFilterBar from '@/components/molecules/VolunteerStaffingFilterBar.vue'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import StatisticsExpertParametersModal from '@/components/molecules/statistics/StatisticsExpertParametersModal.vue'
import StatisticsExtraBlocksModal from '@/components/molecules/statistics/StatisticsExtraBlocksModal.vue'
import StatisticsGeneratorChartModal from '@/components/molecules/statistics/StatisticsGeneratorChartModal.vue'
import StatisticsAccessChartModal from '@/components/molecules/statistics/StatisticsAccessChartModal.vue'
import {showGlassToast} from '@/composables/useGlassToast'
import {useEventStore} from '@/stores/event'
import {useProgramsStore} from '@/stores/programs'
import {formatDateOnly, formatDateTime} from '@/utils/dateTimeFormat'
import {programDisplayName, programId, type EventProgramRef} from '@/utils/eventPrograms'
import {flowFilename} from '@/utils/flowFilename'
import '@/assets/volunteers.css'

defineOptions({name: 'Cockpit'})

type Season = {id: number; name: string; year: number}

type CockpitDots = {
  plan: boolean
  team: null
  rooms: boolean
  staffing: boolean
}

type CockpitEvent = {
  event_id: number
  regional_partner_id: number | null
  regional_partner_name: string | null
  event_date: string | null
  event_name: string
  plan_id: number | null
  programs: number[]
  teams: Record<string, number | null>
  dots: CockpitDots
  generator_last_end: string | null
  generator_count: number | null
  param_changes: {input: number; expert: number} | null
  extra_blocks: {free: number; slot: number} | null
  helferliste_count: number
  public_helper_search: boolean
  publication_level: number | null
  access_count: number
}

type SortKey = 'rp' | 'date' | 'generator' | 'publish'
type ModalMode = 'params' | 'blocks' | 'timeline' | 'access' | null
type HelferFilter = 'empty' | 'filled' | 'both'

const HELFER_FILTERS: {id: HelferFilter; label: string}[] = [
  {id: 'empty', label: 'Liste leer'},
  {id: 'filled', label: 'Liste nicht leer'},
  {id: 'both', label: 'Beides'},
]

const seasons = ref<Season[]>([])
const selectedSeasonId = ref<number | null>(null)
const events = ref<CockpitEvent[]>([])
const loading = ref(true)
const upcomingOnly = ref(true)
const withoutPlanOnly = ref(false)
const helferFilter = ref<HelferFilter>('both')
const activeProgramFilters = ref<Set<number>>(new Set())
const programFiltersSeeded = ref(false)
const sortKey = ref<SortKey>('date')
const sortDir = ref<'asc' | 'desc'>('asc')
const modalMode = ref<ModalMode>(null)
const modalPlanId = ref<number | null>(null)
const modalEventId = ref<number | null>(null)
const exportBusy = ref(false)

const router = useRouter()
const eventStore = useEventStore()
const programsStore = useProgramsStore()

const programChips = computed<EventProgramRef[]>(() => {
  const present = new Set(events.value.flatMap((row) => row.programs))
  return programsStore.catalog
    .filter((row) => present.has(programId(row)))
    .slice()
    .sort((a, b) => {
      const seqA = a.sequence ?? Number.POSITIVE_INFINITY
      const seqB = b.sequence ?? Number.POSITIVE_INFINITY
      if (seqA !== seqB) return seqA - seqB
      return programId(a) - programId(b)
    })
})

const showProgramChips = computed(() => programChips.value.length > 1)

function todayBerlin(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Berlin',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

function compareNullable(a: string | number | null | undefined, b: string | number | null | undefined): number {
  if (a == null && b == null) return 0
  if (a == null) return 1
  if (b == null) return -1
  if (a < b) return -1
  if (a > b) return 1
  return 0
}

const filteredRows = computed(() => {
  if (showProgramChips.value && activeProgramFilters.value.size === 0) {
    return []
  }
  const today = todayBerlin()
  let rows = events.value.slice()
  if (upcomingOnly.value) {
    rows = rows.filter((row) => !row.event_date || row.event_date >= today)
  }
  if (withoutPlanOnly.value) {
    rows = rows.filter((row) => !generatorRan(row))
  }
  if (helferFilter.value === 'empty') {
    rows = rows.filter((row) => row.helferliste_count === 0)
  } else if (helferFilter.value === 'filled') {
    rows = rows.filter((row) => row.helferliste_count > 0)
  }
  if (showProgramChips.value) {
    rows = rows.filter((row) => row.programs.some((id) => activeProgramFilters.value.has(id)))
  }
  const dir = sortDir.value === 'desc' ? -1 : 1
  rows.sort((a, b) => {
    let cmp = 0
    if (sortKey.value === 'rp') cmp = compareNullable(a.regional_partner_name, b.regional_partner_name)
    else if (sortKey.value === 'generator') cmp = compareNullable(a.generator_last_end, b.generator_last_end)
    else if (sortKey.value === 'publish') cmp = compareNullable(a.publication_level, b.publication_level)
    else cmp = compareNullable(a.event_date, b.event_date)
    if (cmp !== 0) return cmp * dir
    return compareNullable(a.regional_partner_name, b.regional_partner_name)
  })
  return rows
})

function toggleProgramFilter(id: number) {
  const next = new Set(activeProgramFilters.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  activeProgramFilters.value = next
}

function toggleSort(key: SortKey) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    return
  }
  sortKey.value = key
  sortDir.value = 'asc'
}

function sortIcon(key: SortKey) {
  if (sortKey.value !== key) return 'bi-arrow-down-up'
  return sortDir.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down'
}

function generatorRan(row: CockpitEvent): boolean {
  return !!row.generator_last_end
}

function liveDotClass(row: CockpitEvent, on: boolean): string {
  if (!generatorRan(row)) return 'cockpit-dot--disabled'
  return on ? 'cockpit-dot--on' : 'cockpit-dot--off'
}

function teamCell(row: CockpitEvent, programIdValue: number): string {
  const value = row.teams[String(programIdValue)]
  if (value === null || value === undefined) return ''
  return String(value)
}

function publishTitle(level: number | null): string {
  if (level == null) return ''
  if (level <= 2) return 'Keine'
  if (level === 3) return 'Nur wichtige Zeiten'
  return 'Volle Details'
}

function paramLabel(row: CockpitEvent): string {
  if (!row.param_changes) return ''
  return `${row.param_changes.input} + ${row.param_changes.expert}`
}

function hasParamDrill(row: CockpitEvent): boolean {
  if (!row.plan_id || !row.param_changes) return false
  return row.param_changes.input > 0 || row.param_changes.expert > 0
}

function extraBlocksLabel(row: CockpitEvent): string {
  if (!row.extra_blocks) return ''
  return `${row.extra_blocks.free} + ${row.extra_blocks.slot}`
}

function hasBlockDrill(row: CockpitEvent): boolean {
  if (!row.plan_id || !row.extra_blocks) return false
  return row.extra_blocks.free > 0 || row.extra_blocks.slot > 0
}

function openParams(planId: number) {
  modalEventId.value = null
  modalPlanId.value = planId
  modalMode.value = 'params'
}

function openBlocks(planId: number) {
  modalEventId.value = null
  modalPlanId.value = planId
  modalMode.value = 'blocks'
}

function openTimeline(planId: number) {
  modalEventId.value = null
  modalPlanId.value = planId
  modalMode.value = 'timeline'
}

function openAccess(eventId: number) {
  modalPlanId.value = null
  modalEventId.value = eventId
  modalMode.value = 'access'
}

function closeModal() {
  modalMode.value = null
  modalPlanId.value = null
  modalEventId.value = null
}

async function selectEvent(row: CockpitEvent) {
  if (!row.event_id || !row.regional_partner_id) return
  await axios.post('/user/select-event', {
    event: row.event_id,
    regional_partner: row.regional_partner_id,
  })
  await eventStore.fetchSelectedEvent()
  await router.push('/overview')
}

async function downloadExcel() {
  if (!selectedSeasonId.value || exportBusy.value) return
  exportBusy.value = true
  try {
    const programIds = showProgramChips.value
      ? Array.from(activeProgramFilters.value)
      : programChips.value.map((program) => programId(program))
    const programs = programIds.join(',')
    const response = await axios.get('/admin/cockpit.xlsx', {
      params: {
        season: selectedSeasonId.value,
        upcoming: upcomingOnly.value ? '1' : '0',
        without_plan: withoutPlanOnly.value ? '1' : '0',
        helferliste: helferFilter.value,
        programs,
        sort: sortKey.value,
        dir: sortDir.value,
      },
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = response.headers['x-filename'] || flowFilename('Cockpit', 'xlsx')
    link.click()
    window.URL.revokeObjectURL(url)
  } catch (err) {
    console.error(err)
    showGlassToast('Cockpit konnte nicht geladen werden.', 'error')
  } finally {
    exportBusy.value = false
  }
}

async function loadSeasons() {
  loading.value = true
  try {
    const [seasonsResponse, currentResponse] = await Promise.all([
      axios.get('/seasons'),
      axios.get('/current-season'),
    ])
    seasons.value = Array.isArray(seasonsResponse.data) ? seasonsResponse.data : []
    selectedSeasonId.value = currentResponse.data?.id ?? seasons.value[0]?.id ?? null
  } catch (err) {
    console.error(err)
    seasons.value = []
    selectedSeasonId.value = null
    events.value = []
    showGlassToast('Cockpit konnte nicht geladen werden.', 'error')
  } finally {
    loading.value = false
  }
}

async function loadEvents() {
  if (!selectedSeasonId.value) {
    events.value = []
    return
  }
  loading.value = true
  try {
    const {data} = await axios.get('/admin/cockpit', {params: {season: selectedSeasonId.value}})
    events.value = Array.isArray(data?.events) ? data.events : []
  } catch (err) {
    console.error(err)
    events.value = []
    showGlassToast('Cockpit konnte nicht geladen werden.', 'error')
  } finally {
    loading.value = false
  }
}

watch(programChips, (chips) => {
  if (programFiltersSeeded.value || chips.length < 2) return
  activeProgramFilters.value = new Set(chips.map((program) => programId(program)))
  programFiltersSeeded.value = true
}, {immediate: true})

watch(selectedSeasonId, (id) => {
  if (id == null) {
    events.value = []
    return
  }
  void loadEvents()
})

onMounted(async () => {
  await programsStore.ensureLoaded()
  await loadSeasons()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <h2 class="text-xl font-bold">Cockpit</h2>
      <button
          type="button"
          class="glass-btn-secondary"
          :disabled="exportBusy"
          @click="downloadExcel"
      >
        <i class="bi bi-file-earmark-excel" aria-hidden="true"/>
        Excel
      </button>
    </div>

    <div class="flex flex-wrap gap-2">
      <label
          v-for="season in seasons"
          :key="season.id"
          class="cursor-pointer"
      >
        <input
            v-model="selectedSeasonId"
            type="radio"
            :value="season.id"
            class="mr-1"
        >
        {{ season.year }} – {{ season.name }}
      </label>
    </div>

    <VolunteerStaffingFilterBar>
      <template #middle>
        <template v-if="showProgramChips">
        <button
            v-for="program in programChips"
            :key="programId(program)"
            type="button"
            class="vol-staffing-filter"
            :class="{'vol-staffing-filter--active': activeProgramFilters.has(programId(program))}"
            :aria-pressed="activeProgramFilters.has(programId(program))"
            @click="toggleProgramFilter(programId(program))"
        >
          <ProgramLogo
              :program="program"
              size="chip"
              decorative
              class="vol-staffing-filter__logo"
          />
          <span class="vol-staffing-filter__label">{{ programDisplayName(program) }}</span>
        </button>
        </template>
      </template>
      <template #trailing>
        <span class="vol-staffing-filters__sep" aria-hidden="true"/>
        <button
            type="button"
            class="vol-staffing-filter"
            :class="{'vol-staffing-filter--active': upcomingOnly}"
            :aria-pressed="upcomingOnly"
            @click="upcomingOnly = !upcomingOnly"
        >
          <span class="vol-staffing-filter__label">Nur Zukunft</span>
        </button>
        <button
            type="button"
            class="vol-staffing-filter"
            :class="{'vol-staffing-filter--active': withoutPlanOnly}"
            :aria-pressed="withoutPlanOnly"
            @click="withoutPlanOnly = !withoutPlanOnly"
        >
          <span class="vol-staffing-filter__label">Noch ohne Plan</span>
        </button>
        <span class="vol-staffing-filters__sep" aria-hidden="true"/>
        <div
            class="inline-flex flex-wrap gap-2 items-center"
            role="radiogroup"
            aria-label="Helfer:innen"
        >
          <i class="bi bi-person-heart vol-staffing-filter__icon" aria-hidden="true"/>
          <button
              v-for="option in HELFER_FILTERS"
              :key="option.id"
              type="button"
              role="radio"
              class="vol-staffing-filter"
              :class="{'vol-staffing-filter--active': helferFilter === option.id}"
              :aria-checked="helferFilter === option.id"
              @click="helferFilter = option.id"
          >
            <span class="vol-staffing-filter__label">{{ option.label }}</span>
          </button>
        </div>
      </template>
    </VolunteerStaffingFilterBar>

    <div v-if="loading" class="text-[var(--color-text-subtle)] text-sm">
      Lade Events…
    </div>

    <div v-else class="glass-card liquid-surface-inner overflow-hidden">
      <div class="max-h-[70vh] overflow-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-[var(--color-bg-muted)] text-left sticky top-0 z-10">
            <tr>
              <th class="px-3 py-2" scope="col">
                <button
                    type="button"
                    class="vol-sort"
                    :class="{'vol-sort--active': sortKey === 'rp'}"
                    @click="toggleSort('rp')"
                >
                  RP
                  <i class="bi" :class="sortIcon('rp')" aria-hidden="true"/>
                </button>
              </th>
              <th class="px-3 py-2" scope="col">
                <button
                    type="button"
                    class="vol-sort"
                    :class="{'vol-sort--active': sortKey === 'date'}"
                    @click="toggleSort('date')"
                >
                  Datum
                  <i class="bi" :class="sortIcon('date')" aria-hidden="true"/>
                </button>
              </th>
              <th class="px-3 py-2" scope="col">Event</th>
              <th
                  v-for="program in programChips"
                  :key="`col-${programId(program)}`"
                  class="px-3 py-2 text-center"
                  scope="col"
              >
                <ProgramLogo
                    :program="program"
                    size="chip"
                    decorative
                    :title="programDisplayName(program)"
                    class="mx-auto"
                />
                <span class="sr-only">{{ programDisplayName(program) }}</span>
              </th>
              <th class="px-3 py-2 text-center" scope="col">Ablauf</th>
              <th class="px-3 py-2 text-center" scope="col">Räume</th>
              <th class="px-3 py-2 text-center" scope="col">Zuordnung</th>
              <th class="px-3 py-2" scope="col">
                <button
                    type="button"
                    class="vol-sort"
                    :class="{'vol-sort--active': sortKey === 'generator'}"
                    @click="toggleSort('generator')"
                >
                  Letzter Generatorlauf
                  <i class="bi" :class="sortIcon('generator')" aria-hidden="true"/>
                </button>
              </th>
              <th class="px-3 py-2" scope="col">Veränderte Parameter</th>
              <th class="px-3 py-2" scope="col">Extra-Blöcke</th>
              <th class="px-3 py-2" scope="col">Helferliste</th>
              <th class="px-3 py-2" scope="col">
                <button
                    type="button"
                    class="vol-sort"
                    :class="{'vol-sort--active': sortKey === 'publish'}"
                    @click="toggleSort('publish')"
                >
                  Veröffentlichung
                  <i class="bi" :class="sortIcon('publish')" aria-hidden="true"/>
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
                v-for="row in filteredRows"
                :key="row.event_id"
                class="border-t border-[var(--color-border)]"
            >
              <td class="px-3 py-2">
                {{ row.regional_partner_name ?? '' }}
              </td>
              <td class="px-3 py-2">{{ formatDateOnly(row.event_date) }}</td>
              <td class="px-3 py-2">
                <button
                    type="button"
                    class="text-left text-[var(--color-accent)] hover:underline"
                    @click="selectEvent(row)"
                >
                  {{ row.event_name }}
                </button>
              </td>
              <td
                  v-for="program in programChips"
                  :key="`${row.event_id}-${programId(program)}`"
                  class="px-3 py-2 text-center"
              >
                {{ teamCell(row, programId(program)) }}
              </td>
              <td class="px-3 py-2 text-center">
                <span class="cockpit-dot" :class="liveDotClass(row, row.dots.plan)"/>
              </td>
              <td class="px-3 py-2 text-center">
                <span class="cockpit-dot" :class="liveDotClass(row, row.dots.rooms)"/>
              </td>
              <td class="px-3 py-2 text-center">
                <span class="cockpit-dot" :class="liveDotClass(row, row.dots.staffing)"/>
              </td>
              <td class="px-3 py-2">
                <div v-if="row.generator_last_end">{{ formatDateTime(row.generator_last_end) }}</div>
                <div v-if="row.plan_id && generatorRan(row)" class="flex items-center gap-1">
                  <span>{{ row.generator_count }}</span>
                  <button
                      type="button"
                      class="text-[var(--color-accent)]"
                      title="Generierungen anzeigen"
                      @click="openTimeline(row.plan_id)"
                  >
                    <i class="bi bi-graph-up" aria-hidden="true"/>
                  </button>
                </div>
              </td>
              <td class="px-3 py-2">
                <span v-if="row.param_changes">{{ paramLabel(row) }}</span>
                <button
                    v-if="hasParamDrill(row) && row.plan_id"
                    type="button"
                    class="ml-1 text-[var(--color-accent)]"
                    title="Veränderte Parameter anzeigen"
                    @click="openParams(row.plan_id)"
                >
                  <i class="bi bi-search" aria-hidden="true"/>
                </button>
              </td>
              <td class="px-3 py-2">
                <span v-if="row.extra_blocks">{{ extraBlocksLabel(row) }}</span>
                <button
                    v-if="hasBlockDrill(row) && row.plan_id"
                    type="button"
                    class="ml-1 text-[var(--color-accent)]"
                    title="Extra-Blöcke anzeigen"
                    @click="openBlocks(row.plan_id)"
                >
                  <i class="bi bi-search" aria-hidden="true"/>
                </button>
              </td>
              <td class="px-3 py-2">
                <span class="inline-flex items-center gap-1">
                  {{ row.helferliste_count }}
                  <i
                      v-if="row.public_helper_search"
                      class="bi bi-person-heart text-[var(--color-accent)]"
                      title="Suche nach Helfer:innen"
                      aria-label="Suche nach Helfer:innen"
                  />
                </span>
              </td>
              <td class="px-3 py-2" :title="publishTitle(row.publication_level)">
                <div v-if="row.publication_level != null" class="inline-flex">
                  <span
                      v-for="n in 4"
                      :key="n"
                      class="w-3 h-3 rounded-full mx-0.5"
                      :class="n <= row.publication_level ? 'bg-blue-600' : 'bg-gray-300'"
                  />
                </div>
                <div v-if="row.publication_level != null" class="flex items-center gap-1">
                  <span>{{ row.access_count }}</span>
                  <button
                      type="button"
                      class="text-[var(--color-accent)]"
                      title="Zugriffe anzeigen"
                      @click="openAccess(row.event_id)"
                  >
                    <i class="bi bi-graph-up" aria-hidden="true"/>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <teleport to="body">
    <div
        v-if="modalMode && (modalPlanId || modalEventId)"
        class="glass-scrim fixed inset-0 flex items-center justify-center z-50"
    >
      <StatisticsExpertParametersModal
          v-if="modalMode === 'params' && modalPlanId"
          :plan-id="modalPlanId"
          @close="closeModal"
      />
      <StatisticsExtraBlocksModal
          v-if="modalMode === 'blocks' && modalPlanId"
          :plan-id="modalPlanId"
          @close="closeModal"
      />
      <StatisticsGeneratorChartModal
          v-if="modalMode === 'timeline' && modalPlanId"
          :plan-id="modalPlanId"
          @close="closeModal"
      />
      <StatisticsAccessChartModal
          v-if="modalMode === 'access' && modalEventId"
          :event-id="modalEventId"
          @close="closeModal"
      />
    </div>
  </teleport>
</template>

<style scoped>
.cockpit-dot {
  display: inline-block;
  width: 0.7rem;
  height: 0.7rem;
  border-radius: 999px;
  vertical-align: middle;
}

.cockpit-dot--on {
  background: #dc2626;
}

.cockpit-dot--off {
  background: #fff;
  box-shadow: inset 0 0 0 1px var(--color-border);
}

.cockpit-dot--disabled {
  background: #d1d5db;
  opacity: 0.45;
}
</style>
