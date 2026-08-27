<script setup lang="ts">
import {computed, onBeforeUnmount, ref, watch} from 'vue'

export type SupportedPlanRow = {
  teams?: number | null
  lanes?: number | null
  tables?: number | null
  note?: string | null
  alert_level?: number | null
}

const FIELD_COLUMNS = [2, 4] as const
const MAX_JURY_COLUMNS = 5

const props = defineProps<{
  plans: SupportedPlanRow[]
}>()

const open = ref(false)
const rootRef = ref<HTMLElement | null>(null)

const sortedPlans = computed(() => {
  return [...props.plans].sort((a, b) => {
    const teams = Number(a.teams || 0) - Number(b.teams || 0)
    if (teams !== 0) return teams
    const lanes = Number(a.lanes || 0) - Number(b.lanes || 0)
    if (lanes !== 0) return lanes
    return Number(a.tables || 0) - Number(b.tables || 0)
  })
})

const juryColumns = computed(() => {
  const maxInData = sortedPlans.value.reduce((max, row) => {
    const n = Number(row.lanes || 0)
    return n > max ? n : max
  }, 0)
  const n = Math.min(MAX_JURY_COLUMNS, maxInData)
  return Array.from({length: n}, (_, i) => i + 1)
})

function hasJury(row: SupportedPlanRow, n: number): boolean {
  return Number(row.lanes || 0) === n
}

function hasFields(row: SupportedPlanRow, n: number): boolean {
  return Number(row.tables || 0) === n
}

function closeDialog() {
  open.value = false
}

function toggle() {
  open.value = !open.value
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    e.stopPropagation()
    closeDialog()
  }
}

function onDocPointerDown(e: PointerEvent) {
  if (!open.value) return
  const root = rootRef.value
  if (root && !root.contains(e.target as Node)) closeDialog()
}

watch(open, (isOpen) => {
  if (isOpen) document.addEventListener('pointerdown', onDocPointerDown, true)
  else document.removeEventListener('pointerdown', onDocPointerDown, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocPointerDown, true)
})
</script>

<template>
  <div ref="rootRef" class="supported-plans">
    <button
        type="button"
        class="supported-plans__trigger"
        title="Unterstützte Pläne"
        aria-label="Unterstützte Pläne"
        :aria-expanded="open"
        @click.stop="toggle"
    >
      <i class="bi bi-table" aria-hidden="true"/>
    </button>

    <div
        v-if="open"
        class="supported-plans__panel liquid-surface-inner"
        role="dialog"
        aria-label="Unterstützte Pläne"
        @keydown="onKeydown"
    >
      <p class="supported-plans__title">Unterstützte Pläne</p>
      <p v-if="sortedPlans.length === 0" class="supported-plans__empty">
        Keine Einträge für dieses Programm.
      </p>
      <div v-else class="supported-plans__table-wrap">
        <table class="supported-plans__table">
          <thead>
            <tr>
              <th rowspan="2" class="supported-plans__teams-head">Teams</th>
              <th
                  v-if="juryColumns.length"
                  :colspan="juryColumns.length"
                  class="supported-plans__group-head"
              >
                Jurygruppen
              </th>
              <th :colspan="FIELD_COLUMNS.length" class="supported-plans__group-head">
                Spielfelder
              </th>
            </tr>
            <tr>
              <th
                  v-for="n in juryColumns"
                  :key="'jury_h_' + n"
                  class="supported-plans__num-head"
              >
                {{ n }}
              </th>
              <th
                  v-for="n in FIELD_COLUMNS"
                  :key="'field_h_' + n"
                  class="supported-plans__num-head"
              >
                {{ n }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, idx) in sortedPlans" :key="idx">
              <td class="supported-plans__teams">{{ row.teams ?? '—' }}</td>
              <td
                  v-for="n in juryColumns"
                  :key="'jury_' + idx + '_' + n"
                  class="supported-plans__mark"
              >
                <i
                    v-if="hasJury(row, n)"
                    class="bi bi-check-lg"
                    aria-label="ja"
                />
              </td>
              <td
                  v-for="n in FIELD_COLUMNS"
                  :key="'field_' + idx + '_' + n"
                  class="supported-plans__mark"
              >
                <i
                    v-if="hasFields(row, n)"
                    class="bi bi-check-lg"
                    aria-label="ja"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.supported-plans {
  position: relative;
  flex-shrink: 0;
}

.supported-plans__trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  margin: 0;
  padding: 0;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  border-radius: var(--radius);
  background: color-mix(in srgb, var(--color-bg) 80%, transparent);
  color: var(--color-text-muted);
  cursor: pointer;
  transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}

.supported-plans__trigger:hover {
  color: var(--color-text);
  border-color: color-mix(in srgb, var(--program-accent, var(--color-accent)) 45%, var(--color-border));
  background: color-mix(in srgb, var(--program-accent, var(--color-accent)) 8%, #fff);
}

.supported-plans__trigger:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--program-accent, var(--color-accent)) 45%, transparent);
  outline-offset: 2px;
}

.supported-plans__panel {
  position: absolute;
  bottom: calc(100% + 0.4rem);
  right: 0;
  z-index: 40;
  width: max-content;
  max-width: min(36rem, calc(100vw - 2rem));
  max-height: min(22rem, 50vh);
  display: flex;
  flex-direction: column;
  padding: 0.75rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: #fff;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
}

.supported-plans__title {
  margin: 0 0 0.55rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text);
  line-height: 1.3;
}

.supported-plans__empty {
  margin: 0;
  font-size: 0.8rem;
  color: var(--color-text-muted);
  line-height: 1.35;
}

.supported-plans__table-wrap {
  overflow: auto;
  min-height: 0;
}

.supported-plans__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
  line-height: 1.3;
}

.supported-plans__table th,
.supported-plans__table td {
  padding: 0.2rem 0.35rem;
  vertical-align: middle;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 70%, transparent);
  white-space: nowrap;
}

.supported-plans__table th {
  font-weight: 600;
  color: var(--color-text-muted);
}

.supported-plans__teams-head {
  text-align: left;
  vertical-align: bottom;
}

.supported-plans__group-head {
  text-align: center;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 55%, transparent);
}

.supported-plans__num-head {
  text-align: center;
  font-variant-numeric: tabular-nums;
  min-width: 1.6rem;
}

.supported-plans__teams {
  text-align: left;
  font-variant-numeric: tabular-nums;
  font-weight: 550;
}

.supported-plans__mark {
  text-align: center;
  color: color-mix(in srgb, var(--program-accent, var(--color-accent)) 70%, #166534);
  min-width: 1.6rem;
}

.supported-plans__mark .bi {
  font-size: 1rem;
  line-height: 1;
}
</style>
