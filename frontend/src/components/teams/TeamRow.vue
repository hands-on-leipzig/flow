<script setup lang="ts">
import {computed} from 'vue'
import IconDraggable from '@/components/icons/IconDraggable.vue'
import {formatJuryCell, juryCellAriaLabel} from '@/utils/teamJury'

const props = defineProps({
  team: {type: Object, required: true},
  index: {type: Number, required: true},
  program: {type: String, default: ''},
  variant: {type: String, default: 'main'},
  planCapacity: {type: Number, default: 0},
  teamsBeyondCapacity: {type: Boolean, default: false},
  missingInDraht: {type: Boolean, default: false},
  showJury: {type: Boolean, default: false},
  borderStyle: {type: String, default: ''},
  hasMorningBorder: {type: Boolean, default: false},
  hasAfternoonBorder: {type: Boolean, default: false},
  coachCount: {type: Number, default: null},
  memberCount: {type: Number, default: null},
  coachNames: {type: Array, default: () => []},
  expanded: {type: Boolean, default: false},
  peopleData: {type: Object, default: null},
  formatBirthday: {type: Function, required: true},
  syncChangeLabel: {type: String, default: ''},
})

const emit = defineEmits(['toggle', 'update-noshow', 'copy'])

const isPending = () => props.variant === 'pending'
const beyondCapacity = () => props.teamsBeyondCapacity && props.index >= props.planCapacity

const isTagged = computed(() => isPending() || props.missingInDraht)
const showChangeLabel = computed(() => isPending())

const juryDisplay = computed(() => {
  if (isPending()) return '–'
  const lane = props.team.jury_lane as number | null | undefined
  return formatJuryCell(props.program, lane)
})

const juryAriaLabel = computed(() => {
  if (isPending()) return undefined
  const lane = props.team.jury_lane as number | null | undefined
  return juryCellAriaLabel(props.program, lane)
})

const peopleTotal = computed(() => {
  if (props.coachCount == null && props.memberCount == null) return null
  return (props.coachCount ?? 0) + (props.memberCount ?? 0)
})

function pendingTeamNumber(team: Record<string, unknown>): string {
  const num = team.number ?? team.ref
  if (num == null || num === '') return '–'
  return String(num).padStart(4, '0')
}

function onCopy(text: string, label: string) {
  emit('copy', text, label)
}
</script>

<template>
  <li
      :class="[
        'team-row rounded-xl px-3 py-2 md:py-2.5 mb-1.5 border transition-opacity',
        showJury ? 'team-row--with-jury' : '',
        showChangeLabel ? 'team-row--has-change-label' : '',
        isTagged ? 'team-row--tagged' : '',
        isPending()
          ? 'text-[var(--color-text-muted)]'
          : [
              'cursor-pointer',
              !isTagged && beyondCapacity()
                ? 'bg-amber-50 text-amber-950 border-amber-200'
                : !isTagged ? 'bg-white/90 text-[var(--color-text)] border-[var(--color-border)]' : '',
              team.noshow ? 'opacity-55' : 'opacity-100',
              !isTagged && !beyondCapacity() && hasMorningBorder ? 'border-l-[6px]' : '',
              !isTagged && !beyondCapacity() && hasAfternoonBorder ? 'border-l-[6px]' : '',
            ],
      ]"
      :style="!isPending() && !beyondCapacity() && !isTagged ? borderStyle : ''"
      @click="!isPending() && emit('toggle')"
  >
    <div class="team-row__grid">
      <template v-if="!showChangeLabel">
        <span
            class="team-row__drag drag-handle cursor-move text-[var(--color-text-muted)] self-center"
            @click.stop
        >
          <IconDraggable/>
        </span>

        <span
            v-if="!beyondCapacity() || index < planCapacity"
            class="team-row__plan text-sm font-semibold tabular-nums"
            :class="beyondCapacity() ? 'text-amber-950' : 'text-[var(--color-text)]'"
        >
          T{{ String(index + 1).padStart(2, '0') }}
        </span>
        <span v-else class="team-row__plan text-sm font-semibold tabular-nums text-amber-950">–</span>
      </template>
      <span
          v-else
          class="team-row__change-label text-xs font-semibold tracking-wide"
      >
        {{ syncChangeLabel }}
      </span>

      <span
          v-if="showJury"
          class="team-row__jury text-sm tabular-nums text-[var(--color-text-subtle)]"
          :aria-label="juryAriaLabel"
          :title="juryAriaLabel"
      >
        {{ juryDisplay }}
      </span>

      <span
          class="team-row__draht-id text-sm tabular-nums font-medium"
          :class="[
            beyondCapacity() ? 'text-amber-900' : 'text-[var(--color-text-muted)]',
            missingInDraht ? 'line-through decoration-[var(--color-text-muted)]' : '',
          ]"
      >
        <template v-if="isPending()">{{ pendingTeamNumber(team) }}</template>
        <template v-else>
          {{ team.team_number_hot ? String(team.team_number_hot).padStart(4, '0') : '–' }}
        </template>
      </span>

      <span
          class="team-row__name text-sm font-medium truncate min-w-0"
          :class="[
            beyondCapacity() ? 'text-amber-950' : 'text-[var(--color-text)]',
            missingInDraht ? 'line-through decoration-[var(--color-text-muted)]' : '',
          ]"
      >
        {{ isPending() ? (team.name || '–') : team.name }}
      </span>

      <span
          v-if="coachNames.length"
          class="team-row__coaches text-sm text-[var(--color-text-muted)] truncate min-w-0"
          :class="missingInDraht ? 'line-through decoration-[var(--color-text-muted)]' : ''"
          :title="coachNames.join(', ')"
      >
        <i class="bi bi-person-badge shrink-0 opacity-80 mr-1" aria-hidden="true"/>
        {{ coachNames.join(', ') }}
      </span>
      <span v-else class="team-row__coaches text-sm text-[var(--color-text-subtle)]">–</span>

      <span
          class="team-row__coach-count text-sm tabular-nums text-center text-[var(--color-text-muted)]"
          :aria-label="coachCount != null ? `${coachCount} Coaches` : undefined"
      >
        <template v-if="!isPending() || coachCount != null">
          {{ coachCount ?? '–' }}
          <i class="bi bi-person-badge ml-0.5" aria-hidden="true"/>
        </template>
      </span>

      <span
          class="team-row__member-count text-sm tabular-nums text-center text-[var(--color-text-muted)]"
          :aria-label="memberCount != null ? `${memberCount} Teammitglieder` : undefined"
      >
        <template v-if="!isPending() || memberCount != null">
          {{ memberCount ?? '–' }}
          <i class="bi bi-person-fill ml-0.5" aria-hidden="true"/>
        </template>
      </span>

      <span
          class="team-row__people-total text-sm tabular-nums text-center font-semibold text-[var(--color-text)]"
          :aria-label="peopleTotal != null ? `${peopleTotal} Personen gesamt` : undefined"
      >
        <template v-if="!isPending() || peopleTotal != null">
          {{ peopleTotal ?? '–' }}
        </template>
      </span>

      <template v-if="!isPending()">
        <label
            v-if="!beyondCapacity()"
            class="flex items-center gap-1 text-xs text-[var(--color-text-muted)] cursor-pointer"
            @click.stop
        >
          <input
              v-model="team.noshow"
              class="w-4 h-4 text-blue-600 border-[var(--color-border)] rounded focus:ring-blue-500"
              type="checkbox"
              @change="emit('update-noshow', team)"
          />
          <span>No-show</span>
        </label>
        <span v-else class="text-xs text-amber-800">No-show</span>
      </template>
      <span v-else/>

      <span v-if="!isPending()" class="team-row__expand text-[var(--color-text-muted)] text-sm justify-self-end">
        {{ expanded ? '▼' : '▶' }}
      </span>
      <span v-else class="team-row__expand"/>
    </div>

    <div
        v-if="!isPending() && expanded && peopleData"
        class="ml-8 mb-2 mt-2 bg-[var(--color-bg-muted)] rounded p-3"
        @click.stop
    >
      <div v-if="peopleData.coaches?.length" class="mb-3">
        <div class="text-xs font-semibold text-[var(--color-text-muted)] mb-1">
          Coaches ({{ peopleData.num_coaches || 0 }}):
        </div>
        <div class="space-y-1">
          <div
              v-for="(coach, coachIndex) in peopleData.coaches"
              :key="coachIndex"
              class="text-sm text-[var(--color-text-muted)]"
          >
            <template v-if="typeof coach === 'object' && coach !== null">
              <div class="flex flex-col">
                <span class="font-medium">
                  {{ coach.firstname || 'Unbekannt' }} {{ coach.name || 'Unbekannt' }}
                </span>
                <div v-if="coach.email || coach.phone" class="text-xs text-[var(--color-text-subtle)] ml-2 flex flex-wrap gap-2">
                  <button
                      v-if="coach.email"
                      type="button"
                      class="hover:underline"
                      @click="onCopy(coach.email, 'E-Mail')"
                  >
                    {{ coach.email }}
                  </button>
                  <button
                      v-if="coach.phone"
                      type="button"
                      class="hover:underline"
                      @click="onCopy(coach.phone, 'Telefon')"
                  >
                    {{ coach.phone }}
                  </button>
                </div>
              </div>
            </template>
            <template v-else>{{ coach || 'Unbekannt' }}</template>
          </div>
        </div>
      </div>
      <div v-else class="text-sm text-[var(--color-text-subtle)] italic mb-3">Keine Coaches gefunden</div>

      <div v-if="peopleData.players?.length">
        <div class="text-xs font-semibold text-[var(--color-text-muted)] mb-1">
          Mitglieder ({{ peopleData.num_players || 0 }}):
        </div>
        <div class="space-y-1">
          <div
              v-for="(player, playerIndex) in peopleData.players"
              :key="playerIndex"
              class="text-sm text-[var(--color-text-muted)]"
          >
            <span v-if="player.name || player.firstname">
              {{ player.firstname || '' }} {{ player.name || '' }}
              <span class="text-[var(--color-text-subtle)]">
                ({{ player.gender || 'N/A' }}, {{ formatBirthday(player.birthday) }})
              </span>
            </span>
            <span v-else class="italic">Unbekanntes Mitglied</span>
          </div>
        </div>
      </div>
      <div v-else class="text-sm text-[var(--color-text-subtle)] italic">Keine Mitglieder gefunden</div>
    </div>
  </li>
</template>

<style scoped>
.team-row__grid {
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

.team-row--with-jury .team-row__grid {
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

.team-row--tagged {
  border-color: color-mix(in srgb, #b45309 45%, var(--color-border));
  background: color-mix(in srgb, #b45309 10%, var(--color-bg-muted));
  color: var(--color-text);
}

.team-row--has-change-label .team-row__change-label {
  grid-column: 1 / span 2;
  align-self: stretch;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: -0.5rem 0 -0.5rem -0.75rem;
  padding: 0.5rem 0.35rem;
  border-radius: calc(var(--radius-lg) - 1px) 0 0 calc(var(--radius-lg) - 1px);
  border-right: 1px solid color-mix(in srgb, #b45309 25%, var(--color-border));
  background: color-mix(in srgb, #b45309 14%, var(--color-bg-muted));
  color: var(--color-text);
  font-weight: 500;
  white-space: nowrap;
}

@media (min-width: 768px) {
  .team-row--has-change-label .team-row__change-label {
    margin-top: -0.625rem;
    margin-bottom: -0.625rem;
  }
}

@media (max-width: 767px) {
  .team-row__grid {
    grid-template-columns: 3rem minmax(0, 1fr);
    gap: 0.25rem 0.5rem;
  }

  .team-row__grid > * {
    display: none;
  }

  .team-row__draht-id {
    display: block;
    grid-column: 1;
    grid-row: 1 / span 2;
    align-self: start;
  }

  .team-row__name {
    display: block;
    grid-column: 2;
    grid-row: 1;
  }

  .team-row__coaches {
    display: block;
    grid-column: 2;
    grid-row: 2;
    font-size: 0.75rem;
  }

  .team-row--has-change-label .team-row__change-label {
    display: flex;
    grid-column: 1;
    grid-row: 1 / span 2;
    align-self: stretch;
    margin: -0.5rem 0 -0.5rem -0.75rem;
    padding: 0.35rem 0.25rem;
    font-size: 0.65rem;
    border-radius: calc(var(--radius-lg) - 1px) 0 0 calc(var(--radius-lg) - 1px);
  }

  .team-row--has-change-label .team-row__draht-id {
    grid-column: 1;
    grid-row: 2;
  }

  .team-row--has-change-label .team-row__name {
    grid-column: 2;
    grid-row: 1;
  }

  .team-row--has-change-label .team-row__coaches {
    grid-column: 2;
    grid-row: 2;
  }
}
</style>
