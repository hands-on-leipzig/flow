<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, ref, watch} from 'vue'

export type SupportedPlanRow = {
  teams?: number | null
  lanes?: number | null
  tables?: number | null
  note?: string | null
  alert_level?: number | null
}

const FIELD_COLUMNS = [2, 4] as const
const MAX_JURY_COLUMNS = 5
const GAP_PX = 4

const props = withDefaults(
    defineProps<{
      plans: SupportedPlanRow[]
      /** Drives Explore-specific labels/columns. */
      program?: 'explore' | 'challenge' | 'future8'
    }>(),
    {
      program: 'challenge',
    }
)

const isExplore = computed(() => props.program === 'explore')
const showFields = computed(() => !isExplore.value)
const juryGroupLabel = computed(() =>
    isExplore.value ? 'Gutachter:innengruppen' : 'Jurygruppen'
)
const fieldColumns = computed(() => (showFields.value ? [...FIELD_COLUMNS] : []))

const open = ref(false)
const triggerRef = ref<HTMLElement | null>(null)
const panelRef = ref<HTMLElement | null>(null)
const panelStyle = ref<Record<string, string>>({})

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

function alertRowClass(row: SupportedPlanRow): string {
  switch (Number(row.alert_level || 0)) {
    case 1:
      return 'supported-plans__row--ok'
    case 2:
      return 'supported-plans__row--warn'
    case 3:
      return 'supported-plans__row--error'
    default:
      return ''
  }
}

function updatePanelPosition() {
  const trigger = triggerRef.value
  if (!trigger) return
  const rect = trigger.getBoundingClientRect()
  const accent = getComputedStyle(trigger).getPropertyValue('--program-accent').trim()
  panelStyle.value = {
    left: `${Math.round(rect.right + GAP_PX)}px`,
    bottom: `${Math.round(window.innerHeight - rect.top + GAP_PX)}px`,
    ...(accent ? {'--program-accent': accent} : {}),
  }
}

function closeDialog() {
  open.value = false
}

async function openDialog() {
  open.value = true
  await nextTick()
  updatePanelPosition()
}

function toggle() {
  if (open.value) closeDialog()
  else void openDialog()
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    e.stopPropagation()
    closeDialog()
  }
}

function onDocPointerDown(e: PointerEvent) {
  if (!open.value) return
  const target = e.target as Node
  if (triggerRef.value?.contains(target)) return
  if (panelRef.value?.contains(target)) return
  closeDialog()
}

function onReposition() {
  if (!open.value) return
  updatePanelPosition()
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocPointerDown, true)
    document.addEventListener('keydown', onKeydown, true)
    window.addEventListener('resize', onReposition)
    window.addEventListener('scroll', onReposition, true)
  } else {
    document.removeEventListener('pointerdown', onDocPointerDown, true)
    document.removeEventListener('keydown', onKeydown, true)
    window.removeEventListener('resize', onReposition)
    window.removeEventListener('scroll', onReposition, true)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocPointerDown, true)
  document.removeEventListener('keydown', onKeydown, true)
  window.removeEventListener('resize', onReposition)
  window.removeEventListener('scroll', onReposition, true)
})
</script>

<template>
  <div class="supported-plans">
    <button
        ref="triggerRef"
        type="button"
        class="supported-plans__trigger"
        title="Unterstützte Pläne"
        aria-label="Unterstützte Pläne"
        :aria-expanded="open"
        @click.stop="toggle"
    >
      <i class="bi bi-table" aria-hidden="true"/>
    </button>

    <Teleport to="body">
      <div
          v-if="open"
          ref="panelRef"
          class="supported-plans__panel liquid-surface-inner"
          role="dialog"
          aria-label="Unterstützte Pläne"
          :style="panelStyle"
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
                  {{ juryGroupLabel }}
                </th>
                <th
                    v-if="fieldColumns.length"
                    :colspan="fieldColumns.length"
                    class="supported-plans__group-head"
                >
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
                    v-for="n in fieldColumns"
                    :key="'field_h_' + n"
                    class="supported-plans__num-head"
                >
                  {{ n }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                  v-for="(row, idx) in sortedPlans"
                  :key="idx"
                  :class="alertRowClass(row)"
              >
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
                    v-for="n in fieldColumns"
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
    </Teleport>
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
</style>

<!-- Panel is teleported to body; keep its styles unscoped. -->
<style>
.supported-plans__panel {
  --sp-head-h: 1.55rem;
  --sp-row-h: 1.7rem;
  position: fixed;
  z-index: 10050;
  width: max-content;
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
  width: max-content;
  max-height: calc((2 * var(--sp-head-h)) + (10 * var(--sp-row-h)));
  overflow: auto;
}

.supported-plans__table {
  width: max-content;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.8rem;
  line-height: 1.3;
}

.supported-plans__table th,
.supported-plans__table td {
  padding: 0 0.35rem;
  vertical-align: middle;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 70%, transparent);
  white-space: nowrap;
  box-sizing: border-box;
}

.supported-plans__table thead th {
  position: sticky;
  z-index: 2;
  height: var(--sp-head-h);
  background: #fff;
  font-weight: 600;
  color: var(--color-text-muted);
}

.supported-plans__table thead tr:first-child th {
  top: 0;
}

.supported-plans__table thead tr:last-child th {
  top: var(--sp-head-h);
}

.supported-plans__table tbody td {
  height: var(--sp-row-h);
}

.supported-plans__teams-head {
  text-align: left;
  vertical-align: middle;
  top: 0;
  height: calc(2 * var(--sp-head-h));
  z-index: 3;
}

.supported-plans__group-head {
  text-align: center;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 55%, transparent);
}

.supported-plans__num-head {
  text-align: center;
  font-variant-numeric: tabular-nums;
  min-width: 1.6rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 70%, transparent);
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

/* Same palette as program-note in Explore/Challenge/Future8 settings */
.supported-plans__row--ok td {
  color: #166534;
  background: color-mix(in srgb, #22c55e 12%, transparent);
}

.supported-plans__row--warn td {
  color: #9a3412;
  background: color-mix(in srgb, #f59e0b 14%, transparent);
}

.supported-plans__row--error td {
  color: #991b1b;
  background: color-mix(in srgb, #ef4444 12%, transparent);
}

.supported-plans__row--ok .supported-plans__mark,
.supported-plans__row--warn .supported-plans__mark,
.supported-plans__row--error .supported-plans__mark {
  color: inherit;
}
</style>
