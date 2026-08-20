<script setup lang="ts">
/**
 * One program’s teams page: list (2/3) + export / tools (1/3).
 */
import {computed, onMounted, ref, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import TeamList from '@/components/molecules/TeamList.vue'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import LoaderText from '@/components/atoms/LoaderText.vue'
import {findProgram, firstTeamsPath} from '@/utils/eventPrograms'

defineOptions({name: 'TeamsProgram'})

const route = useRoute()
const router = useRouter()
const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const event = computed(() => eventStore.selectedEvent)

const program = computed(() => String(route.params.program || ''))

const remoteTeams = ref<any[]>([])
const remoteCapacity = ref(0)
const loading = ref(true)

const attachedProgram = computed(() => findProgram(event.value, program.value))

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
  loading.value = true
  try {
    if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
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
      if (route.path !== next) {
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
  <div class="teams-program">
    <div v-if="loading" class="teams-program__loading">
      <LoaderFlow/>
      <LoaderText/>
    </div>

    <TeamList
        v-else-if="attachedProgram"
        :key="program"
        :program="program"
        :remote-teams="remoteTeams"
        :remote-capacity="remoteCapacity"
        split
    />
  </div>
</template>

<style scoped>
.teams-program {
  height: 100%;
  min-height: 0;
}

.teams-program__loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 20rem;
  color: var(--color-text-muted);
}
</style>
