<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import {RadioGroup, RadioGroupOption} from "@headlessui/vue"

const props = defineProps<{
  parameters: any[],
  showChallenge: boolean
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

// ------------------ Challenge lanes (DB-backed) ------------------
const allLaneOptions = [1, 2, 3, 4, 5, 6, 7]
const allowedJuryLanes = ref<number[]>([])

async function loadAllowedJuryLanes() {
  const total = Number(paramMapByName.value['c_teams']?.value || 0)
  if (!total) {
    allowedJuryLanes.value = []
    return
  }
  try {
    const {data} = await axios.get('/parameter/jury-lanes/options', {params: {teams: total}})
    allowedJuryLanes.value = Array.isArray(data?.options) ? data.options : []
  } catch {
    allowedJuryLanes.value = allLaneOptions.slice()
  }
}

watch(() => paramMapByName.value['c_teams']?.value, loadAllowedJuryLanes)
watch(props.parameters, loadAllowedJuryLanes, {immediate: true})

watch(allowedJuryLanes, (opts) => {
  const cur = Number(paramMapByName.value['j_lanes']?.value || 0)
  if (opts.length && !opts.includes(cur)) updateByName('j_lanes', opts[0])
})

const jLanesProxy = computed<number>({
  get: () => Number(paramMapByName.value['j_lanes']?.value || 0),
  set: (val) => updateByName('j_lanes', val)
})

const isLaneAllowed = (n: number) => allowedJuryLanes.value.includes(n)
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
            class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow transform peer-checked:translate-x-full transition-transform"
        ></div>
      </label>
    </div>

    <template v-if="showChallenge">
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

      <div class="mb-3">
        <div class="text-sm font-medium mb-1">Robot-Game-Tische</div>
        <div class="flex gap-4">
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input
                type="radio"
                name="c_tables"
                :checked="Number(paramMapByName['c_tables']?.value) === 2"
                @change="updateByName('c_tables', 2)"
            />
            <span>2 Tische</span>
          </label>
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input
                type="radio"
                name="c_tables"
                :checked="Number(paramMapByName['c_tables']?.value) === 4"
                @change="updateByName('c_tables', 4)"
            />
            <span>4 Tische</span>
          </label>
        </div>
      </div>

      <div class="mb-1">
        <div class="text-sm font-medium mb-1">
          Jury-Spuren
          <span v-if="paramMapByName['c_teams']?.value" class="text-xs text-gray-500">
            ({{ paramMapByName['c_teams']?.value }} Teams)
          </span>
        </div>

        <RadioGroup v-model="jLanesProxy" class="flex flex-wrap gap-2">
          <RadioGroupOption
              v-for="n in allLaneOptions"
              :key="'j_lane_' + n"
              :value="n"
              :disabled="!isLaneAllowed(n)"
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

        <p v-if="allowedJuryLanes.length === 0" class="text-xs text-gray-500 mt-1">
          Keine gültigen Spurenzahlen für die aktuelle Teamanzahl.
        </p>
      </div>
    </template>
  </div>
</template>
