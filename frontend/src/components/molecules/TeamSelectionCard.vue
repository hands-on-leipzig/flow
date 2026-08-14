<script lang="ts" setup>
import {computed} from 'vue'

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
  if (props.planTeams !== props.registeredTeams) return true
  if (props.planTeams === 0 && props.registeredTeams > 0) return true
  return false
})
</script>

<template>
  <div class="team-metrics" role="group" aria-label="Teamzahlen">
    <div class="team-metrics__cell team-metrics__cell--plan">
      <span class="team-metrics__label">Plan für</span>
      <div class="team-metrics__value-row">
        <span
            v-if="plannedAmountNotMatching"
            class="team-metrics__warn"
            title="Weicht von der Anmeldung ab"
            aria-label="Weicht von der Anmeldung ab"
        />
        <div class="team-metrics__stepper">
          <button
              type="button"
              class="team-metrics__step"
              :disabled="planTeams >= maxTeams"
              aria-label="Teamanzahl erhöhen"
              @click="onUpdate(Math.min(maxTeams, planTeams + 1))"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
            </svg>
          </button>
          <button
              type="button"
              class="team-metrics__step"
              :disabled="planTeams <= minTeams"
              aria-label="Teamanzahl verringern"
              @click="onUpdate(Math.max(minTeams, planTeams - 1))"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
          </button>
        </div>
        <span
            class="team-metrics__number"
            :class="planTeams > 0 ? 'is-set' : 'is-empty'"
        >{{ planTeams }}</span>
        <span class="team-metrics__unit">Teams</span>
      </div>
    </div>

    <div class="team-metrics__cell">
      <span class="team-metrics__label">Angemeldet</span>
      <div class="team-metrics__value-row">
        <span
            class="team-metrics__number"
            :class="registeredTeams > 0 ? 'is-set' : 'is-empty'"
        >{{ registeredTeams }}</span>
        <span class="team-metrics__unit">Teams</span>
      </div>
    </div>

    <div class="team-metrics__cell">
      <span class="team-metrics__label">Kapazität</span>
      <div class="team-metrics__value-row">
        <span
            class="team-metrics__number"
            :class="capacity > 0 ? 'is-set' : 'is-empty'"
        >{{ capacity }}</span>
        <span class="team-metrics__unit">Teams</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.team-metrics {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 38%, transparent);
  border-radius: 12px;
  overflow: hidden;
  background: color-mix(in srgb, var(--color-bg-muted, #f4f6f8) 55%, #fff);
}

@media (min-width: 640px) {
  .team-metrics {
    grid-template-columns: 1.15fr 1fr 1fr;
  }
}

.team-metrics__cell {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  padding: 0.85rem 1rem;
  min-width: 0;
}

@media (min-width: 640px) {
  .team-metrics__cell + .team-metrics__cell {
    border-left: 1px solid color-mix(in srgb, var(--color-border-strong) 28%, transparent);
  }
}

@media (max-width: 639px) {
  .team-metrics__cell + .team-metrics__cell {
    border-top: 1px solid color-mix(in srgb, var(--color-border-strong) 28%, transparent);
  }
}

.team-metrics__cell--plan {
  background: color-mix(in srgb, #fff 70%, transparent);
}

.team-metrics__label {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.team-metrics__value-row {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  min-height: 2rem;
}

.team-metrics__stepper {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.team-metrics__step {
  display: grid;
  place-items: center;
  width: 1.4rem;
  height: 0.95rem;
  padding: 0;
  border: none;
  border-radius: 4px;
  color: var(--color-text-muted);
  background: transparent;
  cursor: pointer;
  transition: color 0.12s ease, background 0.12s ease;
}

.team-metrics__step:hover:not(:disabled) {
  color: var(--color-text);
  background: var(--color-bg-hover);
}

.team-metrics__step:disabled {
  opacity: 0.28;
  cursor: not-allowed;
}

.team-metrics__number {
  font-size: 1.65rem;
  font-weight: 750;
  letter-spacing: -0.03em;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.team-metrics__number.is-set {
  color: var(--color-text);
}

.team-metrics__number.is-empty {
  color: var(--color-text-subtle);
}

.team-metrics__unit {
  font-size: 0.82rem;
  font-weight: 550;
  color: var(--color-text-muted);
  padding-top: 0.35rem;
}

.team-metrics__warn {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: #ef4444;
  flex-shrink: 0;
  box-shadow: 0 0 0 3px color-mix(in srgb, #ef4444 18%, transparent);
}
</style>
