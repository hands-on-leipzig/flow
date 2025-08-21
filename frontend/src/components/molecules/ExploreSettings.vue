<script setup lang="ts">
import {computed, UnwrapRef, watch} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import SplitBar from '@/components/atoms/SplitBar.vue'
import type {LanesIndex} from '@/utils/lanesIndex'

const props = defineProps<{
  parameters: any[]
  showExplore: boolean
  lanesIndex?: LanesIndex | UnwrapRef<LanesIndex> | null
}>()

const emit = defineEmits<{
  (e: 'update-param', param: any): void
  (e: 'update-by-name', name: string, value: any): void
}>()

const paramMapByName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

function updateByName(name: string, value: any) {
  emit('update-by-name', name, value)
}

/** Core derived state **/
const eMode = computed<number>({
  get: () => Number(paramMapByName.value['e_mode']?.value || 0),
  set: (v) => updateByName('e_mode', v)
})
const eTeams = computed(() => Number(paramMapByName.value['e_teams']?.value || 0))
const e1Teams = computed(() => Number(paramMapByName.value['e1_teams']?.value || 0))
const e2Teams = computed(() => Number(paramMapByName.value['e2_teams']?.value || 0))

const isIntegrated = computed(() => eMode.value === 1 || eMode.value === 2)
const isIndependent = computed(() => eMode.value === 3 || eMode.value === 4)
const isSplit = computed(() => eMode.value === 4)
const independentSide = computed<'am' | 'pm'>(() => e1Teams.value > 0 ? 'am' : 'pm')

/** Fancy mode changes **/
function setMode(mode: 1 | 2 | 3 | 4) {
  eMode.value = mode
  const total = eTeams.value
  if (mode === 4) initHalfSplitIfNeeded()
  if (mode === 3) {
    // default single block in the morning
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
  }
}

function setMode3Side(side: 'am' | 'pm') {
  if (eMode.value !== 3) eMode.value = 3
  const total = eTeams.value
  if (side === 'am') {
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
  } else {
    updateByName('e1_teams', 0)
    updateByName('e2_teams', total)
  }
}

function initHalfSplitIfNeeded() {
  const total = eTeams.value
  if (!total) return
  const sum = e1Teams.value + e2Teams.value
  if (sum !== total || (e1Teams.value === 0 && e2Teams.value === 0)) {
    const half = Math.floor(total / 2)
    updateByName('e1_teams', half)
    updateByName('e2_teams', total - half)
  }
}

/** Keep user split when total changes **/
watch(() => paramMapByName.value['e_teams']?.value, (newTotalRaw) => {
  const total = Number(newTotalRaw || 0)
  const e1P = paramMapByName.value['e1_teams']
  const e2P = paramMapByName.value['e2_teams']
  if (!total || !e1P || !e2P) return
  let e1 = Math.min(Number(e1P.value || 0), total)
  let e2 = total - e1
  updateByName('e1_teams', e1)
  updateByName('e2_teams', e2)
})

/** Lanes – use lanesIndex.explore[teams] **/
const allLaneOptions = [1, 2, 3, 4, 5, 6, 7]

/* Integrated (modes 1/2) use e1_lanes, keyed by total e_teams */
const allowedExploreLanesIntegrated = computed<number[]>(() => {
  if (!props.lanesIndex || !eTeams.value) return []
  const key = `${eTeams.value}`
  return props.lanesIndex.explore[key] ?? []
})
const e1LanesProxy = computed<number>({
  get: () => Number(paramMapByName.value['e1_lanes']?.value || 0),
  set: (val) => updateByName('e1_lanes', val)
})
watch(allowedExploreLanesIntegrated, (opts) => {
  if (!opts.length) return
  const cur = Number(paramMapByName.value['e1_lanes']?.value || 0)
  if (!opts.includes(cur)) updateByName('e1_lanes', opts[0])
})
const isExploreLaneAllowedIntegrated = (n: number) =>
    allowedExploreLanesIntegrated.value.includes(n)

/* Independent AM uses e1_lanes keyed by e1_teams; PM uses e2_lanes keyed by e2_teams */
const allowedExploreLanesAM = computed<number[]>(() => {
  if (!props.lanesIndex || !e1Teams.value) return []
  const key = `${e1Teams.value}`
  return props.lanesIndex.explore[key] ?? []
})
const allowedExploreLanesPM = computed<number[]>(() => {
  if (!props.lanesIndex || !e2Teams.value) return []
  const key = `${e2Teams.value}`
  return props.lanesIndex.explore[key] ?? []
})

const eLanesAMProxy = computed<number>({
  get: () => Number(paramMapByName.value['e1_lanes']?.value || 0),
  set: (val) => updateByName('e1_lanes', val)
})
const eLanesPMProxy = computed<number>({
  get: () => Number(paramMapByName.value['e2_lanes']?.value || 0),
  set: (val) => updateByName('e2_lanes', val)
})

watch(allowedExploreLanesAM, (opts) => {
  if (!opts.length) return
  const cur = Number(paramMapByName.value['e1_lanes']?.value || 0)
  if (!opts.includes(cur)) updateByName('e1_lanes', opts[0])
})
watch(allowedExploreLanesPM, (opts) => {
  if (!opts.length) return
  const cur = Number(paramMapByName.value['e2_lanes']?.value || 0)
  if (!opts.includes(cur)) updateByName('e2_lanes', opts[0])
})

const isExploreLaneAllowedAM = (n: number) => allowedExploreLanesAM.value.includes(n)
const isExploreLaneAllowedPM = (n: number) => allowedExploreLanesPM.value.includes(n)

/** SplitBar updates **/
const onUpdateE1 = (val: number) => updateByName('e1_teams', val)
const onUpdateE2 = (val: number) => updateByName('e2_teams', val)
</script>

<template>
  <div class="p-4 border rounded shadow">
    <div class="flex items-center justify-between mb-2">
      <h2 class="text-lg font-semibold">Explore Einstellungen</h2>
      <label class="relative inline-flex items-center cursor-pointer">
        <input
            type="checkbox"
            :checked="showExplore"
            @change="emit('toggle-show', ($event.target as HTMLInputElement).checked)"
            class="sr-only peer"
        >
        <div class="w-11 h-6 bg-gray-300 rounded-full peer-checked:bg-blue-600 transition-colors"></div>
        <div
            class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow transform peer-checked:translate-x-full transition-transform"></div>
      </label>
    </div>

    <div class="mb-3">
      <label class="text-sm font-medium">Anzahl Teams</label>
      <input
          class="mt-1 w-32 border rounded px-2 py-1"
          type="number"
          :min="paramMapByName['e_teams']?.min"
          :max="paramMapByName['e_teams']?.max"
          :value="paramMapByName['e_teams']?.value"
          @input="updateByName('e_teams', Number(($event.target as HTMLInputElement).value || 0))"
      />
    </div>

    <!-- Fancy e_mode selector -->
    <RadioGroup v-model="eMode" class="grid grid-cols-1 gap-2 mb-4">
      <RadioGroupOption :value="1" v-slot="{ checked }">
        <div class="rounded-lg border px-3 py-2 cursor-pointer transition hover:border-gray-400"
             :class="checked ? 'ring-2 ring-gray-500 border-gray-500' : 'border-gray-300'">
          <div class="text-sm font-medium">Mit Challenge integriert – <span class="font-semibold">Vormittag</span></div>
          <div class="text-xs text-gray-500">Explore läuft zusammen mit Challenge am Vormittag.</div>
        </div>
      </RadioGroupOption>

      <RadioGroupOption :value="2" v-slot="{ checked }">
        <div class="rounded-lg border px-3 py-2 cursor-pointer transition hover:border-gray-400"
             :class="checked ? 'ring-2 ring-gray-500 border-gray-500' : 'border-gray-300'">
          <div class="text-sm font-medium">Mit Challenge integriert – <span class="font-semibold">Nachmittag</span>
          </div>
          <div class="text-xs text-gray-500">Explore läuft zusammen mit Challenge am Nachmittag.</div>
        </div>
      </RadioGroupOption>

      <RadioGroupOption :value="3" v-slot="{ checked }">
        <div class="rounded-lg border px-3 py-2 cursor-pointer transition hover:border-gray-400 space-y-2"
             :class="checked ? 'ring-2 ring-gray-500 border-gray-500' : 'border-gray-300'">
          <div class="text-sm font-medium">
            Von Challenge unabhängig – <span class="font-semibold">ein Zeitblock</span>
          </div>
          <div class="flex gap-2">
            <button
                type="button"
                class="px-3 py-1.5 rounded-md border text-sm transition
                     focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                :class="independentSide === 'am' ? 'ring-1 ring-gray-500' : 'hover:border-gray-400'"
                @click.stop="setMode3Side('am')"
                @mousedown.stop
            >
              Vormittag
            </button>
            <button
                type="button"
                class="px-3 py-1.5 rounded-md border text-sm transition
                     focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                :class="independentSide === 'pm' ? 'ring-1 ring-gray-500' : 'hover:border-gray-400'"
                @click.stop="setMode3Side('pm')"
                @mousedown.stop
            >
              Nachmittag
            </button>
          </div>
        </div>
      </RadioGroupOption>

      <RadioGroupOption :value="4" v-slot="{ checked }">
        <div class="rounded-lg border px-3 py-2 cursor-pointer transition hover:border-gray-400"
             :class="checked ? 'ring-2 ring-gray-500 border-gray-500' : 'border-gray-300'">
          <div class="text-sm font-medium">Von Challenge unabhängig – <span class="font-semibold">beide (geteilt)</span>
          </div>
          <div class="text-xs text-gray-500">Teams werden zwischen Vor- und Nachmittag aufgeteilt.</div>
        </div>
      </RadioGroupOption>
    </RadioGroup>

    <!-- INTEGRATED (1/2): centered lane selector bound to e1_lanes (allowed by total e_teams) -->
    <div v-if="isIntegrated" class="mt-4 flex justify-center">
      <RadioGroup v-model="e1LanesProxy" class="flex flex-wrap gap-2">
        <RadioGroupOption
            v-for="n in allLaneOptions"
            :key="'e_lane_int_' + n"
            :value="n"
            :disabled="!isExploreLaneAllowedIntegrated(n)"
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
          >
            {{ n }}-spurig
          </button>
        </RadioGroupOption>
      </RadioGroup>
    </div>

    <!-- SPLIT slider only for mode 4 -->
    <SplitBar
        v-if="isSplit && paramMapByName['e_teams']?.value"
        :e1="Number(paramMapByName['e1_teams']?.value || 0)"
        :e2="Number(paramMapByName['e2_teams']?.value || 0)"
        :total="Number(paramMapByName['e_teams']?.value || 0)"
        @update:e1="(v:number) => updateByName('e1_teams', v)"
        @update:e2="(v:number) => updateByName('e2_teams', v)"
        class="mt-3"
    />

    <!-- Two columns when independent (3 or 4); grey out side if inactive in mode 3 -->
    <div v-if="isIndependent" class="mt-4 grid grid-cols-2 gap-8 text-gray-800">
      <!-- AM -->
      <div :class="(eMode === 3 && independentSide !== 'am') ? 'opacity-40 pointer-events-none' : ''">
        <div class="text-sm font-medium mb-1">
          Vormittag <span class="text-xs text-gray-500">(Teams: {{ e1Teams }})</span>
        </div>
        <RadioGroup v-model="eLanesAMProxy" class="flex flex-wrap gap-2">
          <RadioGroupOption
              v-for="n in allLaneOptions"
              :key="'e_lane_am_' + n"
              :value="n"
              :disabled="!isExploreLaneAllowedAM(n)"
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
            >
              {{ n }}-spurig
            </button>
          </RadioGroupOption>
        </RadioGroup>
      </div>

      <!-- PM -->
      <div :class="(eMode === 3 && independentSide !== 'pm') ? 'opacity-40 pointer-events-none' : ''">
        <div class="text-sm font-medium mb-1">
          Nachmittag <span class="text-xs text-gray-500">(Teams: {{ e2Teams }})</span>
        </div>
        <RadioGroup v-model="eLanesPMProxy" class="flex flex-wrap gap-2">
          <RadioGroupOption
              v-for="n in allLaneOptions"
              :key="'e_lane_pm_' + n"
              :value="n"
              :disabled="!isExploreLaneAllowedPM(n)"
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
            >
              {{ n }}-spurig
            </button>
          </RadioGroupOption>
        </RadioGroup>
      </div>
    </div>
  </div>
</template>
