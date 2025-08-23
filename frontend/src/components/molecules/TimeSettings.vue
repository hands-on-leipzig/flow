<script setup lang="ts">
import {computed} from 'vue'
import ParameterField from '@/components/molecules/ParameterField.vue'

const props = defineProps<{
  parameters: any[]
  visibilityMap: Record<string, boolean>
  disabledMap: Record<string, boolean>
}>()

const emit = defineEmits<{
  (e: 'update-param', param: any): void
}>()

// Build quick lookup by name
const byName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

// Current explore mode (1..4)
const eMode = computed(() => Number(byName.value['e_mode']?.value || 0))
const isIntegrated = computed(() => eMode.value === 1 || eMode.value === 2) // with Challenge
const isSplit = computed(() => eMode.value === 4)                     // both AM/PM
const isExploreOne = computed(() => eMode.value === 3)                     // one explore block

// Helper: safely get a param by name
function getParam(name: string) {
  return byName.value[name] ?? null
}

// Cell renderer
function cellParam(prefix: 'g' | 'c' | 'e1' | 'e2', key: 'start_opening' | 'duration_opening' | 'duration_awards') {
  const name = `${prefix}_${key}`
  return getParam(name)
}

const e1Teams = computed(() => Number(byName.value['e1_teams']?.value || 0))
const e2Teams = computed(() => Number(byName.value['e2_teams']?.value || 0))
const independentSide = computed<'am' | 'pm'>(() => (e1Teams.value > 0 ? 'am' : 'pm'))

// Row activation / greying
const rows = computed(() => {
  const gemeinsamActive = isIntegrated.value   // g_* only when integrated
  const challengeActive = true                 // c_* always editable

  let exploreAMActive = false
  let explorePMActive = false

  if (isSplit.value) {
    // mode 4: both AM+PM active
    exploreAMActive = true
    explorePMActive = true
  } else if (isExploreOne.value) {
    // mode 3: activate the side that actually has teams
    exploreAMActive = independentSide.value === 'am'
    explorePMActive = independentSide.value === 'pm'
  } else if (isIntegrated.value) {
    // modes 1/2: explore rows are greyed; only "Gemeinsam" is active
    exploreAMActive = false
    explorePMActive = false
  }

  return {
    gemeinsamActive,
    challengeActive,
    exploreAMActive,
    explorePMActive
  }
})

// Forward updates
function updateParam(p: any) {
  emit('update-param', p)
}
</script>

<template>
  <div class="p-4 border rounded shadow">
    <h2 class="text-lg font-semibold mb-2">Zeiten</h2>

    <div class="grid grid-cols-[1.1fr,1fr,1fr,1fr] border border-gray-300 text-sm">
      <!-- Header -->
      <div class="border-b border-gray-300 font-semibold p-2"></div>
      <div class="border-b border-l border-gray-300 font-semibold p-2">Eröffnung<br/>Start</div>
      <div class="border-b border-l border-gray-300 font-semibold p-2">Eröffnung<br/>Dauer</div>
      <div class="border-b border-l border-gray-300 font-semibold p-2">Preisv.<br/>Dauer</div>


      <!-- Challenge (c_*) -->
      <div class="p-2 border-t border-gray-300">Challenge</div>

      <div class="p-2 border-t border-l border-gray-300">
        <ParameterField v-if="cellParam('c','start_opening') && visibilityMap[cellParam('c','start_opening').id]"
                        :param="cellParam('c','start_opening')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('c','start_opening').id]"
                        @update="updateParam"/>
      </div>

      <div class="p-2 border-t border-l border-gray-300">
        <ParameterField v-if="cellParam('c','duration_opening') && visibilityMap[cellParam('c','duration_opening').id]"
                        :param="cellParam('c','duration_opening')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('c','duration_opening').id]"
                        @update="updateParam"/>
      </div>

      <div class="p-2 border-t border-l border-gray-300">
        <ParameterField v-if="cellParam('c','duration_awards') && visibilityMap[cellParam('c','duration_awards').id]"
                        :param="cellParam('c','duration_awards')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('c','duration_awards').id]"
                        @update="updateParam"/>
      </div>

      <!-- Gemeinsame (g_*) -->
      <div
          class="p-2 border-t border-gray-300"
          :class="{ 'text-gray-400': !rows.gemeinsamActive }"
      >Gemeinsam
      </div>

      <div class="p-2 border-t border-l border-gray-300"
           :class="{ 'opacity-50 pointer-events-none': !rows.gemeinsamActive }">
        <ParameterField v-if="cellParam('g','start_opening') && visibilityMap[cellParam('g','start_opening').id]"
                        :param="cellParam('g','start_opening')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('g','start_opening').id]"
                        @update="updateParam"/>
      </div>

      <div class="p-2 border-t border-l border-gray-300"
           :class="{ 'opacity-50 pointer-events-none': !rows.gemeinsamActive }">
        <ParameterField v-if="cellParam('g','duration_opening') && visibilityMap[cellParam('g','duration_opening').id]"
                        :param="cellParam('g','duration_opening')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('g','duration_opening').id]"
                        @update="updateParam"/>
      </div>

      <div class="p-2 border-t border-l border-gray-300"
           :class="{ 'opacity-50 pointer-events-none': !rows.gemeinsamActive }">
        <ParameterField v-if="cellParam('g','duration_awards') && visibilityMap[cellParam('g','duration_awards').id]"
                        :param="cellParam('g','duration_awards')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('g','duration_awards').id]"
                        @update="updateParam"/>
      </div>


      <!-- Explore Vormittag (e1_*) -->
      <div
          class="p-2 border-t border-gray-300"
          :class="{ 'text-gray-400': !rows.exploreAMActive }"
      >
        <div>Explore</div>
        <div class="text-xs text-gray-500 -mt-1">Vormittag</div>
      </div>

      <div class="p-2 border-t border-l border-gray-300"
           :class="{ 'opacity-50 pointer-events-none': !rows.exploreAMActive }">
        <ParameterField v-if="cellParam('e1','start_opening') && visibilityMap[cellParam('e1','start_opening').id]"
                        :param="cellParam('e1','start_opening')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('e1','start_opening').id]"
                        @update="updateParam"/>
      </div>

      <div class="p-2 border-t border-l border-gray-300"
           :class="{ 'opacity-50 pointer-events-none': !rows.exploreAMActive }">
        <ParameterField
            v-if="cellParam('e1','duration_opening') && visibilityMap[cellParam('e1','duration_opening').id]"
            :param="cellParam('e1','duration_opening')"
            :horizontal="false"
            :disabled="disabledMap[cellParam('e1','duration_opening').id]"
            @update="updateParam"/>
      </div>

      <div class="p-2 border-t border-l border-gray-300"
           :class="{ 'opacity-50 pointer-events-none': !rows.exploreAMActive }">
        <ParameterField v-if="cellParam('e1','duration_awards') && visibilityMap[cellParam('e1','duration_awards').id]"
                        :param="cellParam('e1','duration_awards')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('e1','duration_awards').id]"
                        @update="updateParam"/>
      </div>

      <!-- Explore Nachmittag (e2_*) -->
      <div
          class="p-2 border-t border-gray-300"
          :class="{ 'text-gray-400': !rows.explorePMActive }"
      >
        <div>Explore</div>
        <div class="text-xs text-gray-500 -mt-1">Nachmittag</div>
      </div>

      <div class="p-2 border-t border-l border-gray-300"
           :class="{ 'opacity-50 pointer-events-none': !rows.explorePMActive }">
        <ParameterField v-if="cellParam('e2','start_opening') && visibilityMap[cellParam('e2','start_opening').id]"
                        :param="cellParam('e2','start_opening')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('e2','start_opening').id]"
                        @update="updateParam"/>
      </div>

      <div class="p-2 border-t border-l border-gray-300"
           :class="{ 'opacity-50 pointer-events-none': !rows.explorePMActive }">
        <ParameterField
            v-if="cellParam('e2','duration_opening') && visibilityMap[cellParam('e2','duration_opening').id]"
            :param="cellParam('e2','duration_opening')"
            :horizontal="false"
            :disabled="disabledMap[cellParam('e2','duration_opening').id]"
            @update="updateParam"/>
      </div>

      <div class="p-2 border-t border-l border-gray-300"
           :class="{ 'opacity-50 pointer-events-none': !rows.explorePMActive }">
        <ParameterField v-if="cellParam('e2','duration_awards') && visibilityMap[cellParam('e2','duration_awards').id]"
                        :param="cellParam('e2','duration_awards')"
                        :horizontal="false"
                        :disabled="disabledMap[cellParam('e2','duration_awards').id]"
                        @update="updateParam"/>
      </div>
    </div>
  </div>
</template>
