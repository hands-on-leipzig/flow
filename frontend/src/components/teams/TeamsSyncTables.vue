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
  totalCoaches: {type: Number, default: 0},
  totalMembers: {type: Number, default: 0},
  peopleData: {type: Object, default: () => ({})},
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

const pendingRows = computed(() =>
  merged.value.filter((row) => row.status === 'new' && row.draht),
)

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
    <div
        class="teams-sync-tables__counts team-row__grid mb-2 text-sm tabular-nums text-[var(--color-text-muted)] hidden md:grid"
        :class="showJury ? 'team-row--with-jury' : ''"
    >
      <span/>
      <span/>
      <span v-if="showJury"/>
      <span/>
      <span/>
      <span/>
      <span class="text-center font-medium text-[var(--color-text)]">
        {{ totalCoaches }}
        <i class="bi bi-person-badge ml-0.5" aria-hidden="true"/>
      </span>
      <span class="text-center font-medium text-[var(--color-text)]">
        {{ totalMembers }}
        <i class="bi bi-person-fill ml-0.5" aria-hidden="true"/>
      </span>
      <span/>
      <span/>
    </div>

    <ul v-if="pendingRows.length" class="teams-sync-tables__pending list-none p-0 m-0 mb-2">
      <TeamRow
          v-for="(row, idx) in pendingRows"
          :key="`pending-${row.draht?.id ?? row.number ?? idx}`"
          variant="pending"
          :team="row.draht"
          :index="idx"
          :show-jury="showJury"
          :format-birthday="formatBirthday"
      />
    </ul>

    <div v-if="showSyncButton" class="mb-3 flex justify-center">
      <button
          type="button"
          class="glass-btn-primary px-4 py-2 text-sm font-medium disabled:opacity-50"
          :disabled="syncing"
          @click="emit('sync')"
      >
        {{ syncLabel }}
      </button>
    </div>

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
              class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide my-2 pl-1"
              style="color: #93c5fd;"
          >
            Nachmittag
          </div>
          <TeamRow
              :team="team"
              :index="index"
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
          <span/>
          <span/>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.teams-sync-tables__counts {
  display: grid;
  grid-template-columns:
    1.5rem
    2.75rem
    3.5rem
    minmax(5rem, 1.4fr)
    minmax(4rem, 1fr)
    3.25rem
    3.25rem
    4.5rem
    1.5rem;
  gap: 0.35rem 0.5rem;
  align-items: center;
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
    4.5rem
    1.5rem;
  gap: 0.35rem 0.5rem;
  align-items: center;
}

.teams-sync-tables--jury :deep(.team-row__grid),
.teams-sync-tables--jury .teams-sync-tables__counts {
  grid-template-columns:
    1.5rem
    2.75rem
    2.25rem
    3.5rem
    minmax(5rem, 1.4fr)
    minmax(4rem, 1fr)
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
