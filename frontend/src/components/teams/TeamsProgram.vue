<script setup lang="ts">
/**
 * Reihenfolge: one program's teams onto the plan slots, with a program switcher on top.
 */
import {computed, onActivated, onMounted, ref, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {useNoticeStore} from '@/stores/notice'
import {usePlanCacheStore} from '@/stores/planCache'
import TeamList from '@/components/molecules/TeamList.vue'
import TeamsRegistrationStats from '@/components/teams/TeamsRegistrationStats.vue'
import TeamsMultiTeamCoaches from '@/components/teams/TeamsMultiTeamCoaches.vue'
import ScreenHelpButton from '@/components/atoms/ScreenHelpButton.vue'
import NoticePane from '@/components/molecules/NoticePane.vue'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import PageTabs from '@hands-on/glass/page-tabs'
import {programLogoSrc} from '@/utils/images'
import {
  eventPrograms,
  findProgram,
  firstTeamsPath,
  programCompact,
  programDisplayName,
  programSlug,
  teamPathFor,
} from '@/utils/eventPrograms'

defineOptions({name: 'TeamsProgram'})

const route = useRoute()
const router = useRouter()
const eventStore = useEventStore()
const noticeStore = useNoticeStore()
const planCache = usePlanCacheStore()
const event = computed(() => eventStore.selectedEvent)

// Kept alive across Teamdaten: only follow the param while on a program route.
const program = ref(String(route.params.program || ''))
watch(() => route.params.program, (next) => {
  if (route.name === 'teams-program' && next) program.value = String(next)
})

const remoteTeams = ref<any[]>([])
const remoteCapacity = ref(0)
const loading = ref(true)
/** Program whose list is shown; switches once its registration data is loaded. */
const loadedProgram = ref('')
const planTeams = ref<any[]>([])
const coachConflicts = ref<Record<string, {status: 'red' | 'yellow'; title: string}>>({})
/** Bumped when the page comes back, e.g. after a sync on Teamdaten. */
const listVersion = ref(0)

const attachedProgram = computed(() => findProgram(event.value, program.value))

const programTabs = computed(() =>
  eventPrograms(event.value).map((row) => ({
    id: programSlug(row.name),
    label: programDisplayName(row),
    iconSrc: programLogoSrc(row),
    dot: !!noticeStore.dots.schedule_counts_by_program?.[programCompact(row.name)],
    dotTitle: 'Anzahl Teams im Ablauf passt nicht zur Anmeldung',
  })),
)

const activeSlug = computed(() =>
  attachedProgram.value ? programSlug(attachedProgram.value.name) : program.value,
)

function selectProgram(slug: string | number) {
  const target = eventPrograms(event.value).find((row) => programSlug(row.name) === String(slug))
  if (target && String(slug) !== activeSlug.value) void router.push(teamPathFor(target))
}

function isOnProgramTeamsRoute(): boolean {
  return route.name === 'teams-program'
}

function normalizeTeams(teams: any): any[] {
  if (!teams) return []
  const list = Array.isArray(teams) ? teams : Object.values(teams)
  return list.map((t: any) => ({
    number: t.ref ?? t.number ?? null,
    name: t.name || '',
    organization: t.organization || null,
    location: t.location || null,
    id: t.id || null,
  }))
}

async function loadRemoteTeams() {
  if (!isOnProgramTeamsRoute()) return
  loading.value = true
  try {
    if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
    if (!isOnProgramTeamsRoute()) return
    const current = findProgram(event.value, program.value)
    if (!event.value?.id) {
      remoteTeams.value = []
      remoteCapacity.value = 0
      loadedProgram.value = ''
      return
    }
    if (!current) {
      remoteTeams.value = []
      remoteCapacity.value = 0
      loadedProgram.value = ''
      const next = firstTeamsPath(event.value)
      // Never steal /plan/teams/data (Teamdaten) or other non-program teams routes.
      if (isOnProgramTeamsRoute() && route.path !== next) {
        await router.replace(next)
      }
      return
    }
    const drahtData = await planCache.getDrahtData(event.value.id)
    const programs = Array.isArray(drahtData.programs) ? drahtData.programs : []
    const match = programs.find((p: any) =>
      Number(p.first_program) === Number(current.first_program)
      || String(p.name || '').toUpperCase() === String(current.name || '').toUpperCase()
    )
    remoteTeams.value = normalizeTeams(match?.teams)
    remoteCapacity.value = Number(match?.capacity || 0)
    if (loadedProgram.value !== program.value) planTeams.value = []
    loadedProgram.value = program.value
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadRemoteTeams()
})

let activatedBefore = false
onActivated(() => {
  if (activatedBefore) {
    listVersion.value += 1
    void loadRemoteTeams()
  }
  activatedBefore = true
})

watch(program, () => {
  void loadRemoteTeams()
})

watch(
  () => event.value?.id,
  (id, prev) => {
    if (id && id !== prev) {
      loadedProgram.value = ''
      void loadRemoteTeams()
    }
  }
)
</script>

<template>
  <div class="vol-page vol-page--fill">
    <header class="vol-page__header">
      <div>
        <div class="vol-page__title-row">
          <h1 class="vol-page__title">Reihenfolge</h1>
          <ScreenHelpButton/>
        </div>
        <p class="vol-page__sub">Festlegen der Reihenfolge der Teams im Ablauf</p>
      </div>
    </header>

    <PageTabs
        v-if="programTabs.length > 1"
        aria-label="Programm"
        :tabs="programTabs"
        :model-value="activeSlug"
        @update:model-value="selectProgram"
    />

    <NoticePane/>

    <div
        v-if="loading && !loadedProgram"
        class="vol-staffing-body vol-staffing-body--loading"
    >
      <div class="teams-program__loading">
        <LoaderFlow/>
      </div>
    </div>

    <div v-else-if="loadedProgram" class="vol-staffing-body">
      <div class="vol-staffing-pane vol-staffing-pane--main">
        <section class="glass-card liquid-surface-inner vol-tile">
          <TeamList
              :key="`${loadedProgram}-${listVersion}`"
              :program="loadedProgram"
              :remote-teams="remoteTeams"
              :remote-capacity="remoteCapacity"
              :coach-conflicts="coachConflicts"
              @teams-changed="planTeams = $event"
          />
        </section>
      </div>

      <aside class="vol-staffing-pane vol-staffing-pane--side">
        <TeamsMultiTeamCoaches
            :program="loadedProgram"
            :live-teams="planTeams"
            @conflicts="coachConflicts = $event"
        />
        <TeamsRegistrationStats/>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.vol-staffing-body--loading {
  display: flex;
  align-items: center;
  justify-content: center;
}

.teams-program__loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  color: var(--color-text-muted);
}
</style>
