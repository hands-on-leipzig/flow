<script lang="ts" setup>
import { computed } from 'vue'

const props = defineProps<{
  planTeams: number
  registeredTeams: number
  capacity: number
  minTeams: number
  maxTeams: number
  onUpdate: (value: number) => void
  inputClass?: string
}>()

const plannedAmountNotMatching = computed(() => {
  // Show warning if plan doesn't match registered teams
  if (props.planTeams !== props.registeredTeams) {
    return true
  }
  // Show warning if program is turned off (planTeams === 0) but there are registered teams
  if (props.planTeams === 0 && props.registeredTeams > 0) {
    return true
  }
  return false
})

const getPlanCardClass = computed(() => {
  return plannedAmountNotMatching.value
    ? 'border-orange-300'
    : 'border-gray-200'
})

const getInputClass = computed(() => {
  return plannedAmountNotMatching.value
    ? 'border-orange-400 focus:border-orange-500 focus:ring-orange-500'
    : 'border-gray-300 focus:border-gray-500 focus:ring-gray-500'
})
</script>

<template>
  <div class="mb-3">
    <!-- Three-card layout -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      
      <!-- Card 1: Plan für (Editable) -->
      <div 
        class="border rounded-lg p-3 bg-white transition-colors"
        :class="getPlanCardClass"
      >
        <div class="flex items-center gap-2 mb-2">
          <span class="text-sm font-medium text-gray-700">Plan für</span>
        </div>
        <div class="relative">
          <input
            type="number"
            :value="planTeams"
            :min="minTeams"
            :max="maxTeams"
            readonly
            class="w-full text-xl font-semibold text-center border rounded py-1.5 pl-14 pr-12 focus:outline-none focus:ring-2 transition-all bg-white appearance-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none cursor-default"
            :class="getInputClass"
            @keydown.prevent
          />
          <span v-if="plannedAmountNotMatching" class="absolute left-8 top-1/2 -translate-y-1/2 w-2 h-2 bg-red-500 rounded-full"></span>
          <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-gray-500 pointer-events-none">Teams</span>
          <!-- Custom up/down arrows -->
          <div class="absolute left-1.5 top-0 bottom-0 flex flex-col justify-center gap-px">
            <button
              type="button"
              class="w-5 h-3.5 flex items-center justify-center text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
              @click="onUpdate(Math.min(maxTeams, planTeams + 1))"
              :disabled="planTeams >= maxTeams"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
              </svg>
            </button>
            <button
              type="button"
              class="w-5 h-3.5 flex items-center justify-center text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
              @click="onUpdate(Math.max(minTeams, planTeams - 1))"
              :disabled="planTeams <= minTeams"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
          </div>
        </div>
      </div>
      
      <!-- Card 2: Angemeldet (Registered) -->
      <div class="border border-gray-200 rounded-lg p-3 bg-white">
        <div class="flex items-center gap-2 mb-2">
          <span class="text-sm font-medium text-gray-700">Angemeldet</span>
        </div>
        <div class="w-full text-xl font-semibold text-center border border-gray-300 rounded px-2 py-1.5 bg-gray-50 text-gray-700 relative">
          <span v-if="registeredTeams > 0">{{ registeredTeams }}</span>
          <span v-else class="text-gray-400">0</span>
          <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-gray-500">Teams</span>
        </div>
      </div>
      
      <!-- Card 3: Kapazität (Capacity) -->
      <div class="border border-gray-200 rounded-lg p-3 bg-white">
        <div class="flex items-center gap-2 mb-2">
          <span class="text-sm font-medium text-gray-700">Kapazität</span>
        </div>
        <div class="w-full text-xl font-semibold text-center border border-gray-300 rounded px-2 py-1.5 bg-gray-50 text-gray-700 relative">
          <span v-if="capacity > 0">{{ capacity }}</span>
          <span v-else class="text-gray-400">0</span>
          <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-gray-500">Teams</span>
        </div>
      </div>
    </div>
  </div>
</template>

