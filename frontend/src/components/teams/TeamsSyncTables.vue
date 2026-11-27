<script setup lang="ts">
import {computed} from 'vue'
import draggable from 'vuedraggable'
import TeamRow from '@/components/teams/TeamRow.vue'
import {visibleDrahtTeams} from '@/utils/teamSync'
import { formatPlanTeamNo, MISSING_TEAM_NAME } from '@/utils/planTeamLabel'

const props = defineProps({
  program: {type: String, required: true},
  teamList: {type: Array, required: true},
  remoteTeams: {type: Array, default: () => []},
  planCapacity: {type: Number, default: 0},
  teamsBeyondCapacity: {type: Boolean, default: false},
  hasTwoExploreGroups: {type: Boolean, default: false},
  e1Teams: {type: Number, default: 0},
  showJury: {type: Boolean, default: false},
  getCoachCount: {type: Function, required: true},
  getMemberCount: {type: Function, required: true},
  getCoachNames: {type: Function, required: true},
  getTeamBorderStyle: {type: Function, required: true},
  getTeamGroup: {type: Function, required: true},
})

const emit = defineEmits([
  'update:teamList',
  'sort',
  'update-noshow',
])

const visibleRemote = computed(() => visibleDrahtTeams(props.remoteTeams as Record<string, unknown>[]))

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
              :show-jury="showJury"
              :border-style="getTeamBorderStyle(team)"
              :has-morning-border="getTeamGroup(team) === 'morning'"
              :has-afternoon-border="getTeamGroup(team) === 'afternoon'"
              :coach-count="getCoachCount(team)"
              :member-count="getMemberCount(team)"
              :coach-names="getCoachNames(team)"
              @update-noshow="emit('update-noshow', $event)"
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
          <span class="text-sm font-semibold tabular-nums">{{ formatPlanTeamNo(placeholder.index + 1) }}</span>
          <span v-if="showJury" class="text-sm tabular-nums">–</span>
          <span class="text-sm tabular-nums">–</span>
          <span class="text-sm font-medium">{{ MISSING_TEAM_NAME }}</span>
          <span class="hidden md:inline"/>
          <span class="hidden md:inline"/>
          <div class="team-row__people-zone team-row__people-zone--placeholder">
            <span class="text-sm text-center">–</span>
            <span class="text-sm text-center">–</span>
            <span class="text-sm text-center font-semibold">–</span>
          </div>
          <span/>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.teams-sync-tables :deep(.team-row__grid) {
  display: grid;
  grid-template-columns:
    1.5rem
    2.75rem
    3.5rem
    minmax(5rem, 1.2fr)
    minmax(4rem, 1fr)
    minmax(4rem, 1fr)
    3.25rem
    3.25rem
    3.25rem
    4.5rem;
  gap: 0.35rem 0.5rem;
  align-items: center;
}

.teams-sync-tables--jury :deep(.team-row__grid) {
  grid-template-columns:
    1.5rem
    2.75rem
    2.25rem
    3.5rem
    minmax(5rem, 1.2fr)
    minmax(4rem, 1fr)
    minmax(4rem, 1fr)
    3.25rem
    3.25rem
    3.25rem
    4.5rem;
}

.teams-sync-tables :deep(.team-row__people-zone--placeholder) {
  grid-column: span 3;
  display: grid;
  grid-template-columns: subgrid;
  align-items: center;
  pointer-events: none;
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
