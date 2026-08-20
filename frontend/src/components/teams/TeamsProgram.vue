<script setup lang="ts">
/**
 * One program’s teams page: list (2/3) + export / tools (1/3).
 */
import {computed, onMounted, ref, watch} from 'vue'
import {useRoute} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import TeamList from '@/components/molecules/TeamList.vue'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import LoaderText from '@/components/atoms/LoaderText.vue'
import {getProgramTheme} from '@/utils/programTheme'
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import {findProgram} from '@/utils/eventPrograms'

defineOptions({name: 'TeamsProgram'})

const route = useRoute()
const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const event = computed(() => eventStore.selectedEvent)

const program = computed(() => String(route.params.program || 'explore'))

const theme = computed(() => getProgramTheme(program.value))
const remoteTeams = ref<any[]>([])
const loading = ref(true)

const attachedProgram = computed(() => findProgram(event.value, program.value))
const isSupported = computed(() => !!attachedProgram.value)

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
    if (!event.value?.id || !current) {
      remoteTeams.value = []
      return
    }
    const drahtData = await planCache.getDrahtData(event.value.id)
    const programs = Array.isArray(drahtData.programs) ? drahtData.programs : []
    const match = programs.find((p: any) =>
      Number(p.first_program) === Number(current.first_program)
      || String(p.name || '').toUpperCase() === String(current.name || '').toUpperCase()
    )
    remoteTeams.value = normalizeTeams(match?.teams)
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

    <template v-else-if="!isSupported">
      <div class="teams-program__split">
        <section class="teams-program__main glass-card liquid-surface-inner">
          <div class="flex items-start gap-3 mb-4">
            <img
                :src="programLogoSrc(theme.logoKey || program)"
                :alt="programLogoAlt(theme.logoKey || program)"
                class="w-10 h-10 flex-shrink-0"
            />
            <div>
              <h1 class="text-lg font-semibold">
                <span class="italic">FIRST</span> LEGO League {{ theme.shortName }}
              </h1>
              <p class="text-sm text-[var(--color-text-muted)] mt-0.5">
                Teamverwaltung für {{ theme.shortName }} folgt, sobald die Anmeldung angebunden ist.
              </p>
            </div>
          </div>
          <div class="rounded-xl border border-dashed border-[var(--color-border)] bg-[var(--color-bg-muted)]/40 px-4 py-10 text-center text-sm text-[var(--color-text-muted)]">
            Noch keine Teams für {{ theme.shortName }}.
          </div>
        </section>
        <aside class="teams-program__aside glass-card liquid-surface-inner">
          <h2 class="text-sm font-semibold mb-2">Export & Funktionen</h2>
          <p class="text-sm text-[var(--color-text-muted)]">
            Downloads und weitere Aktionen stehen bereit, sobald Teams für dieses Programm verfügbar sind.
          </p>
        </aside>
      </div>
    </template>

    <TeamList
        v-else
        :key="program"
        :program="program"
        :remote-teams="remoteTeams"
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

.teams-program__split {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
  height: 100%;
  min-height: 0;
}

@media (min-width: 960px) {
  .teams-program__split {
    grid-template-columns: minmax(0, 2fr) minmax(16rem, 1fr);
    align-items: start;
  }
}

.teams-program__main,
.teams-program__aside {
  min-width: 0;
}
</style>
