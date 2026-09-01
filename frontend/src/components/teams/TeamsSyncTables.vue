<script setup lang="ts">
import {computed} from 'vue'
import draggable from 'vuedraggable'
import TeamRow from '@/components/teams/TeamRow.vue'
import {
  hasSyncWork,
  mergeTeams,
  syncButtonLabel,
  visibleDrahtTeams,
  type TeamSyncEntry,
} from '@/utils/teamSync'

const props = defineProps({
  program: {type: String, required: true},
  teamList: {type: Array, required: true},
  remoteTeams: {type: Array, default: () => []},
  planCapacity: {type: Number, default: 0},
  teamsBeyondCapacity: {type: Boolean, default: false},
  hasTwoExploreGroups: {type: Boolean, default: false},
  e1Teams: {type: Number, default: 0},
  showJury: {type: Boolean, default: false},
  syncing: {type: Boolean, default: false},
  getCoachCount: {type: Function, required: true},
  getMemberCount: {type: Function, required: true},
  getCoachNames: {type: Function, required: true},
  getTeamPeopleData: {type: Function, required: true},
  isTeamExpanded: {type: Function, required: true},
  getTeamBorderStyle: {type: Function, required: true},
  getTeamGroup: {type: Function, required: true},
  formatBirthday: {type: Function, required: true},
})

const emit = defineEmits([
  'update:teamList',
  'sort',
  'update-noshow',
  'toggle',
  'copy',
  'sync',
])

const visibleRemote = computed(() => visibleDrahtTeams(props.remoteTeams as Record<string, unknown>[]))

const merged = computed<TeamSyncEntry[]>(() =>
  mergeTeams(props.teamList as Record<string, unknown>[], visibleRemote.value),
)

const upperRows = computed(() =>
  merged.value.filter(
    (row) =>
      row.draht
      && (row.status === 'new' || row.status === 'conflict'),
  ),
)

function upperRowLabel(row: TeamSyncEntry): string {
  return row.status === 'conflict' ? 'Umbenannt' : 'Neu'
}

const missingLocalIds = computed(() => {
  const ids = new Set<number>()
  for (const row of merged.value) {
    if (row.status === 'missing' && row.local?.id != null) {
      ids.add(Number(row.local.id))
    }
  }
  return ids
})

const syncLabel = computed(() => syncButtonLabel(merged.value))
const showSyncButton = computed(() => hasSyncWork(merged.value))

const placeholderRows = computed(() => {
  const capacity = props.planCapacity
  const enrolled = visibleRemote.value.length
  const currentTeams = props.teamList.length
  if (capacity > enrolled) {
    const count = Math.max(0, capacity - currentTeams)
    return Array.from({length: count}, (_, idx) => ({
      id: `empty-${currentTeams + idx}`,
      index: currentTeams + idx,
    }))
  }
  return []
})

const afternoonStartIndex = computed(() => {
  if (!props.hasTwoExploreGroups || props.e1Teams <= 0) return -1
  for (let i = 0; i < props.teamList.length; i++) {
    const team = props.teamList[i] as {team_number_plan?: number}
    if ((team.team_number_plan || 0) > props.e1Teams) return i
  }
  return -1
})

function onDragEnd() {
  emit('sort')
}

function updateTeamList(value: unknown[]) {
  emit('update:teamList', value)
}
</script>

<template>
  <div class="teams-sync-tables" :class="{'teams-sync-tables--jury': showJury}">
    <p
        v-if="showSyncButton"
        class="teams-sync-tables__notice"
    >
      Die Daten in FLOW weichen von denen der Anmeldung ab.
    </p>

    <template v-if="showSyncButton">
      <h3 class="teams-sync-tables__heading">Änderungen der Anmeldung</h3>

      <ul v-if="upperRows.length" class="teams-sync-tables__pending list-none p-0 m-0 mb-2">
        <TeamRow
            v-for="(row, idx) in upperRows"
            :key="`upper-${row.status}-${row.draht?.id ?? row.number ?? idx}`"
            variant="pending"
            :team="row.draht"
            :index="idx"
            :program="program"
            :show-jury="showJury"
            :sync-change-label="upperRowLabel(row)"
            :coach-count="getCoachCount(row.draht)"
            :member-count="getMemberCount(row.draht)"
            :coach-names="getCoachNames(row.draht)"
            :format-birthday="formatBirthday"
        />
      </ul>

      <div class="teams-sync-tables__sync-wrap">
        <button
            type="button"
            class="teams-sync-tables__sync-btn glass-btn-accent"
            :disabled="syncing"
            @click="emit('sync')"
        >
          <i class="bi bi-arrow-repeat teams-sync-tables__sync-icon" aria-hidden="true"/>
          <span>{{ syncLabel }}</span>
        </button>
      </div>

      <h3 class="teams-sync-tables__heading teams-sync-tables__heading--flow">Daten in FLOW</h3>
    </template>

    <draggable
        :model-value="teamList"
        animation="150"
        chosen-class="drag-chosen"
        drag-class="drag-dragging"
        ghost-class="drag-ghost"
        handle=".drag-handle"
        item-key="id"
        tag="ul"
        class="teams-sync-tables__main list-none p-0 m-0"
        @update:model-value="updateTeamList"
        @end="onDragEnd"
    >
      <template #item="{element: team, index}">
        <div>
          <div
              v-if="hasTwoExploreGroups && index === afternoonStartIndex"
              class="text-xs font-semibold text-[var(--color-text-muted)] tracking-wide my-2 pl-1"
              style="color: #93c5fd;"
          >
            Nachmittag
          </div>
          <TeamRow
              :team="team"
              :index="index"
              :program="program"
              variant="main"
              :plan-capacity="planCapacity"
              :teams-beyond-capacity="teamsBeyondCapacity"
              :missing-in-draht="missingLocalIds.has(Number(team.id))"
              :show-jury="showJury"
              :border-style="getTeamBorderStyle(team)"
              :has-morning-border="getTeamGroup(team) === 'morning'"
              :has-afternoon-border="getTeamGroup(team) === 'afternoon'"
              :coach-count="getCoachCount(team)"
              :member-count="getMemberCount(team)"
              :coach-names="getCoachNames(team)"
              :expanded="isTeamExpanded(team)"
              :people-data="getTeamPeopleData(team)"
              :format-birthday="formatBirthday"
              @toggle="emit('toggle', team)"
              @update-noshow="emit('update-noshow', $event)"
              @copy="(text, label) => emit('copy', text, label)"
          />
        </div>
      </template>
    </draggable>

    <ul class="teams-sync-tables__placeholders list-none p-0 m-0">
      <li
          v-for="placeholder in placeholderRows"
          :key="placeholder.id"
          class="team-row team-row--placeholder rounded-xl px-3 py-2.5 mb-1.5 border bg-amber-50 border-amber-200 text-amber-950"
          :class="showJury ? 'team-row--with-jury' : ''"
      >
        <div class="team-row__grid">
          <span/>
          <span/>
          <span v-if="showJury" class="text-sm tabular-nums">–</span>
          <span class="text-sm tabular-nums">–</span>
          <span class="text-sm font-medium">Fehlendes Team</span>
          <span class="hidden md:inline"/>
          <span class="text-sm text-center">–</span>
          <span class="text-sm text-center">–</span>
          <span class="text-sm text-center font-semibold">–</span>
          <span/>
          <span/>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.teams-sync-tables__notice {
  margin: 0 0 0.75rem;
  padding: 0.75rem 1rem;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, #b45309 35%, var(--color-border));
  background: color-mix(in srgb, #b45309 12%, var(--color-bg-muted));
  color: var(--color-text);
  font-size: 0.875rem;
  font-weight: 500;
}

.teams-sync-tables__heading {
  margin: 0 0 0.5rem;
  font-size: 0.8rem;
  font-weight: 650;
  color: var(--color-text-muted);
}

.teams-sync-tables__heading--flow {
  margin-top: 0.25rem;
}

.teams-sync-tables__sync-wrap {
  display: flex;
  justify-content: center;
  margin: 0.75rem 0 1rem;
}

.teams-sync-tables__sync-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  width: 100%;
  max-width: 28rem;
  padding: 0.75rem 1.25rem;
  font-size: 0.95rem;
  font-weight: 700;
  letter-spacing: 0.01em;
}

.teams-sync-tables__sync-icon {
  font-size: 1.15rem;
  flex-shrink: 0;
}

.teams-sync-tables :deep(.team-row__grid) {
  display: grid;
  grid-template-columns:
    1.5rem
    2.75rem
    3.5rem
    minmax(5rem, 1.4fr)
    minmax(4rem, 1fr)
    3.25rem
    3.25rem
    3.25rem
    4.5rem
    1.5rem;
  gap: 0.35rem 0.5rem;
  align-items: center;
}

.teams-sync-tables--jury :deep(.team-row__grid) {
  grid-template-columns:
    1.5rem
    2.75rem
    2.25rem
    3.5rem
    minmax(5rem, 1.4fr)
    minmax(4rem, 1fr)
    3.25rem
    3.25rem
    3.25rem
    4.5rem
    1.5rem;
}

.drag-ghost {
  opacity: 0.4;
  transform: scale(0.98);
}

.drag-chosen {
  background-color: #fde68a;
  box-shadow: 0 0 0 2px #facc15;
}

.drag-dragging {
  cursor: grabbing;
}
</style>
