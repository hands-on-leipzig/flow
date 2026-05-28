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

</script>

<template>
  <div class="mb-3">
    <!-- Three-card layout -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
      
      <!-- Card 1: Plan für (Editable) -->
      <div 
        class="glass-chip liquid-surface-inner p-3 sm:p-4 transition-colors"
      >
        <div class="mb-2 sm:mb-3">
          <span class="text-xs sm:text-sm font-semibold text-[var(--color-text-muted)]">Plan für</span>
        </div>
        <div class="relative">
          <div class="w-full text-xl sm:text-2xl font-bold bg-white relative flex items-center justify-center gap-1.5 sm:gap-2">
            <span v-if="plannedAmountNotMatching" class="w-2 h-2 bg-red-500 rounded-full"></span>
            <!-- Custom up/down arrows -->
            <div class="flex flex-col gap-0.5">
              <button
                type="button"
                class="w-6 h-3.5 flex items-center justify-center text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-bg-hover)] rounded transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                @click="onUpdate(Math.min(maxTeams, planTeams + 1))"
                :disabled="planTeams >= maxTeams"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
                </svg>
              </button>
              <button
                type="button"
                class="w-6 h-3.5 flex items-center justify-center text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-bg-hover)] rounded transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                @click="onUpdate(Math.max(minTeams, planTeams - 1))"
                :disabled="planTeams <= minTeams"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
              </button>
            </div>
            <span v-if="planTeams > 0">{{ planTeams }}</span>
            <span v-else class="text-[var(--color-text-subtle)]">0</span>
            <span class="text-xs font-medium text-[var(--color-text-subtle)]">Teams</span>
          </div>
        </div>
      </div>
      
      <!-- Card 2: Angemeldet (Registered) -->
      <div class="glass-chip liquid-surface-inner p-3 sm:p-4">
        <div class="mb-2 sm:mb-3">
          <span class="text-xs sm:text-sm font-semibold text-[var(--color-text-muted)]">Angemeldet</span>
        </div>
        <div class="w-full text-xl sm:text-2xl font-bold bg-white text-[var(--color-text)] flex items-center justify-center gap-1.5 sm:gap-2">
          <span v-if="registeredTeams > 0">{{ registeredTeams }}</span>
          <span v-else class="text-[var(--color-text-subtle)]">0</span>
          <span class="text-xs font-medium text-[var(--color-text-subtle)]">Teams</span>
        </div>
      </div>
      
      <!-- Card 3: Kapazität (Capacity) -->
      <div class="glass-chip liquid-surface-inner p-3 sm:p-4">
        <div class="mb-2 sm:mb-3">
          <span class="text-xs sm:text-sm font-semibold text-[var(--color-text-muted)]">Kapazität</span>
        </div>
        <div class="w-full text-xl sm:text-2xl font-bold bg-white text-[var(--color-text)] flex items-center justify-center gap-1.5 sm:gap-2">
          <span v-if="capacity > 0">{{ capacity }}</span>
          <span v-else class="text-[var(--color-text-subtle)]">0</span>
          <span class="text-xs font-medium text-[var(--color-text-subtle)]">Teams</span>
        </div>
      </div>
    </div>
  </div>
</template>

