<script setup lang="ts">
/**
 * One program's teams page — mirrors Helfer:innenliste vol-page chrome.
 */
import {computed, onMounted, ref, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import TeamList from '@/components/molecules/TeamList.vue'
import TeamsRegistrationStats from '@/components/teams/TeamsRegistrationStats.vue'
import TeamsMultiTeamCoaches from '@/components/teams/TeamsMultiTeamCoaches.vue'
import TeamsEmailOutreach from '@/components/teams/TeamsEmailOutreach.vue'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import LoaderText from '@/components/atoms/LoaderText.vue'
import {findProgram, firstTeamsPath} from '@/utils/eventPrograms'
import {flowFilename} from '@/utils/flowFilename'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'TeamsProgram'})

const route = useRoute()
const router = useRouter()
const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)

const program = computed(() => String(route.params.program || ''))

const remoteTeams = ref<any[]>([])
const remoteCapacity = ref(0)
const loading = ref(true)
const exportBusy = ref(false)

const attachedProgram = computed(() => findProgram(event.value, program.value))

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
      return
    }
    if (!current) {
      remoteTeams.value = []
      remoteCapacity.value = 0
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
  } finally {
    loading.value = false
  }
}

async function downloadExcel() {
  if (!eventId.value || exportBusy.value) return
  exportBusy.value = true
  try {
    const response = await axios.get(`/events/${eventId.value}/teams/people/export`, {
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = response.headers['x-filename']
      || flowFilename('Teams', 'xlsx', event.value?.date)
    link.click()
    window.URL.revokeObjectURL(url)
  } catch {
    showGlassToast('Export fehlgeschlagen', 'error')
  } finally {
    exportBusy.value = false
  }
}

onMounted(() => {
  void loadRemoteTeams()
})

watch(program, () => {
  void loadRemoteTeams()
})

watch(
  () => event.value?.id,
  (id, prev) => {
    if (id && id !== prev) void loadRemoteTeams()
  }
)
</script>

<template>
  <div class="vol-page teams-program">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Teams</h1>
        <p class="vol-page__sub">Teams und Anmeldung für diese Veranstaltung</p>
      </div>
      <div class="vol-page__actions">
        <button
            type="button"
            class="glass-btn-secondary vol-upload-trigger"
            :class="{'vol-upload-trigger--active': exportBusy}"
            :disabled="!eventId || exportBusy"
            @click="downloadExcel"
        >
          <i class="bi bi-download" aria-hidden="true"/>
          {{ exportBusy ? 'Export…' : 'Download' }}
        </button>
        <TeamsEmailOutreach :current-program="program"/>
      </div>
    </header>

    <div v-if="loading" class="teams-program__loading">
      <LoaderFlow/>
      <LoaderText/>
    </div>

    <div v-else-if="attachedProgram" class="teams-program__body">
      <section class="teams-program__main glass-card liquid-surface-inner vol-tile">
        <TeamList
            :key="program"
            :program="program"
            :remote-teams="remoteTeams"
            :remote-capacity="remoteCapacity"
        />
      </section>

      <aside class="teams-program__stats">
        <TeamsRegistrationStats/>
        <TeamsMultiTeamCoaches/>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.teams-program__body {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
  align-items: start;
  min-width: 0;
}

@media (min-width: 960px) {
  .teams-program__body {
    grid-template-columns: minmax(0, 3fr) minmax(0, 1fr);
  }

  .teams-program__stats {
    position: sticky;
    top: 0.25rem;
  }
}

.teams-program__main,
.teams-program__stats {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.teams-program__loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  min-height: 12rem;
  color: var(--color-text-muted);
}
</style>
