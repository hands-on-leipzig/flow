<script setup lang="ts">
import {computed, UnwrapRef, watch, watchEffect} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import type {LanesIndex} from '@/utils/lanesIndex'

const props = defineProps<{
  parameters: any[]
  showChallenge: boolean
  lanesIndex?: LanesIndex | UnwrapRef<LanesIndex> | null
}>()

const emit = defineEmits<{
  (e: 'update-param', param: any): void
  (e: 'update-by-name', name: string, value: any): void
  (e: 'toggle-show', value: boolean): void
}>()

const paramMapByName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

function updateByName(name: string, value: any) {
  emit('update-by-name', name, value)
}

// Inputs
const cTeams = computed(() => Number(paramMapByName.value['c_teams']?.value || 0))
const rTables = computed(() => Number(paramMapByName.value['r_tables']?.value || 0) || 0)

// ---- Allowed TABLE VARIANTS (2 / 4) for the current team count ----
const tableVariantsForTeams = computed<number[]>(() => {
  const idx = props.lanesIndex?.challenge ?? {}
  const t = cTeams.value
  if (!t) return []
  const variants: number[] = []
  if (idx[`${t}|2`]?.length) variants.push(2)
  if (idx[`${t}|4`]?.length) variants.push(4)
  return variants
})

// ---- Allowed LANES for current selection (merge if tables not chosen yet) ----
const allowedJuryLanes = computed<number[]>(() => {
  const idx = props.lanesIndex?.challenge ?? {}
  const t = cTeams.value
  if (!t) return []
  const variants = rTables.value ? [rTables.value] : [2, 4]
  const merged = variants.flatMap(tb => idx[`${t}|${tb}`] || [])
  return Array.from(new Set(merged)).sort((a, b) => a - b)
})

// Proxies
const jLanesProxy = computed<number>({
  get: () => Number(paramMapByName.value['j_lanes']?.value || 0),
  set: (val) => updateByName('j_lanes', val)
})

// ---- Invariant keeper: keep a valid (tables, lanes) combo at all times ----
watchEffect(() => {
  const t = cTeams.value
  if (!t || !props.lanesIndex) return

  const variants = tableVariantsForTeams.value
  // If no variants for this team count, nothing to choose from
  if (variants.length === 0) return

  const currentTables = rTables.value

  // 1) If tables unset and exactly one variant exists -> snap to it
  if (currentTables === 0 && variants.length === 1) {
    updateByName('r_tables', variants[0])
    return
  }

  // 2) If current tables invalid -> move to first valid variant and set a valid lane
  if (currentTables !== 0 && !variants.includes(currentTables)) {
    const nextTables = variants[0]
    updateByName('r_tables', nextTables)
    const allowedForNext = (props.lanesIndex.challenge[`${t}|${nextTables}`] || []).slice().sort((a, b) => a - b)
    if (allowedForNext.length) updateByName('j_lanes', allowedForNext[0])
    return
  }

  // 3) Ensure current lane is valid for the (possibly merged) allowed set
  const curLane = Number(paramMapByName.value['j_lanes']?.value || 0)
  if (allowedJuryLanes.value.length && !allowedJuryLanes.value.includes(curLane)) {
    updateByName('j_lanes', allowedJuryLanes.value[0])
  }
})

// If allowed set changes (due to teams/tables), snap lanes if invalid
watch(allowedJuryLanes, (opts) => {
  const cur = Number(paramMapByName.value['j_lanes']?.value || 0)
  if (opts.length && !opts.includes(cur)) updateByName('j_lanes', opts[0])
})

// Helpers
const isLaneAllowed = (n: number) => allowedJuryLanes.value.includes(n)

// For display, you can cap to 1..7; lanesIndex usually dictates the set anyway
const lanePalette = computed(() => {
  // Show a consistent row (e.g. 1..7) and disable those not allowed:
  const max = Math.max(5, ...allowedJuryLanes.value)
  return Array.from({length: Math.min(7, max)}, (_, i) => i + 1)
})

const rTablesProxy = computed<number>({
  get: () => Number(paramMapByName.value['r_tables']?.value || 0),
  set: (val) => updateByName('r_tables', val)
})

// Key helpers for challenge (teams|tables)
const cKey = computed(() => {
  const t = cTeams.value
  const tb = rTables.value || 0
  return t ? `${t}|${tb}` : ''
})

// Is a lane recommended for the current selection?
const isLaneRecommended = (lane: number) => {
  if (!props.lanesIndex || !cKey.value) return false
  // if tables not chosen yet (tb=0), recommendation is ambiguous; treat as false
  if (!rTables.value) return false
  const meta = props.lanesIndex.metaChallenge[cKey.value]
  return !!meta?.[lane]?.recommended
}

// Note for the current EXACT combo (only when tables are chosen)
const currentLaneNote = computed<string | undefined>(() => {
  if (!props.lanesIndex || !cKey.value || !rTables.value) return
  const meta = props.lanesIndex.metaChallenge[cKey.value]
  const lane = Number(paramMapByName.value['j_lanes']?.value || 0)
  return meta?.[lane]?.note
})

</script>

<template>
  <div class="p-4 border rounded shadow">
    <div class="flex items-center justify-between mb-2">
      <h2 class="text-lg font-semibold">Challenge Einstellungen</h2>
      <label class="relative inline-flex items-center cursor-pointer">
        <input
            type="checkbox"
            :checked="showChallenge"
            @change="emit('toggle-show', ($event.target as HTMLInputElement).checked)"
            class="sr-only peer"
        >
        <div class="w-11 h-6 bg-gray-300 rounded-full peer-checked:bg-blue-600 transition-colors"></div>
        <div
            class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow transform peer-checked:translate-x-full transition-transform"></div>
      </label>
    </div>

    <template v-if="showChallenge">
      <!-- Teams -->
      <div class="mb-3">
        <label class="text-sm font-medium">Teams (Challenge)</label>
        <input
            class="mt-1 w-32 border rounded px-2 py-1"
            type="number"
            :min="paramMapByName['c_teams']?.min"
            :max="paramMapByName['c_teams']?.max"
            :value="paramMapByName['c_teams']?.value"
            @input="updateByName('c_teams', Number(($event.target as HTMLInputElement).value || 0))"
        />
      </div>

      <!-- Robot-Game tables (styled like n-spurig) -->
      <div class="mb-3">
        <div class="text-sm font-medium mb-1">Robot-Game-Tische</div>
        <RadioGroup v-model="rTablesProxy" class="flex flex-wrap gap-2">
          <RadioGroupOption
              v-for="tb in [2,4]"
              :key="'tables_' + tb"
              :value="tb"
              :disabled="tableVariantsForTeams.length && !tableVariantsForTeams.includes(tb)"
              v-slot="{ checked, disabled }"
          >
            <button
                type="button"
                class="px-3 py-1.5 rounded-md border text-sm transition
                     focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                :class="[
                checked ? 'ring-1 ring-gray-500' : '',
                disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400'
              ]"
                :aria-disabled="disabled"
                @click="!disabled && updateByName('r_tables', tb)"
            >
              {{ tb }} Tische
            </button>
          </RadioGroupOption>
        </RadioGroup>
      </div>

      <!-- Jury lanes -->
      <div class="mb-1">
        <div class="text-sm font-medium mb-1">
          Jury-Spuren
          <span v-if="paramMapByName['c_teams']?.value" class="text-xs text-gray-500">
      ({{ paramMapByName['c_teams']?.value }} Teams)
    </span>
        </div>

        <RadioGroup v-model="jLanesProxy" class="flex flex-wrap gap-2">
          <RadioGroupOption
              v-for="n in lanePalette"
              :key="'j_lane_' + n"
              :value="n"
              :disabled="!isLaneAllowed(n)"
              v-slot="{ checked, disabled }"
          >
            <button
                type="button"
                class="relative px-3 py-1.5 rounded-md border text-sm transition
                 focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                :class="[
            checked ? 'ring-1 ring-gray-500 border-gray-500' : '',
            disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400',
            // highlight recommended
            (!disabled && isLaneRecommended(n)) ? 'after:absolute after:-top-2 ' +
             'after:-right-2 after:text-[10px] after:px-1.5 after:py-0.5 after:bg-emerald-100 ' +
              'after:text-emerald-700 after/rounded after:content-[\'Empfohlen\']' : ''
            ]"
                :aria-disabled="disabled"
            >
              {{ n }}-spurig
            </button>
          </RadioGroupOption>
        </RadioGroup>

        <p v-if="cTeams && allowedJuryLanes.length === 0" class="text-xs text-gray-500 mt-1">
          Keine gültigen Spurenzahlen für die aktuelle Teamanzahl.
        </p>

        <!-- Show the note for the current exact combo (only if tables are chosen) -->
        <div v-if="currentLaneNote" class="mt-2 text-xs text-gray-700 bg-gray-50 border border-gray-200 rounded p-2">
          {{ currentLaneNote }}
        </div>
      </div>


    </template>
  </div>
</template>