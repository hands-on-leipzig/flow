<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {drahtIdFor, eventPrograms, programSlug} from '@/utils/eventPrograms'

type ProgramStats = {
  slug: string
  coaches: number
  members: number
}

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const programs = computed(() => eventPrograms(event.value))
const showTotalColumn = computed(() => programs.value.length > 1)

const stats = ref<ProgramStats[]>([])
const loading = ref(false)

async function loadStats() {
  if (!event.value?.id) {
    stats.value = []
    return
  }

  loading.value = true
  try {
    const rows: ProgramStats[] = []
    for (const prog of programs.value) {
      const slug = programSlug(prog.name)
      const drahtId = drahtIdFor(event.value, slug)
      if (!drahtId) {
        rows.push({slug, coaches: 0, members: 0})
        continue
      }
      try {
        const {data} = await axios.get(`/draht/people/${drahtId}`)
        rows.push({
          slug,
          coaches: Number(data?.total_coaches || 0),
          members: Number(data?.total_players || 0),
        })
      } catch {
        rows.push({slug, coaches: 0, members: 0})
      }
    }
    stats.value = rows
  } finally {
    loading.value = false
  }
}

const totalCoaches = computed(() => stats.value.reduce((sum, row) => sum + row.coaches, 0))
const totalMembers = computed(() => stats.value.reduce((sum, row) => sum + row.members, 0))
const grandTotal = computed(() => totalCoaches.value + totalMembers.value)

function statForSlug(slug: string, field: 'coaches' | 'members'): number {
  return stats.value.find((row) => row.slug === slug)?.[field] ?? 0
}

function totalForSlug(slug: string): number {
  return statForSlug(slug, 'coaches') + statForSlug(slug, 'members')
}

onMounted(() => {
  void loadStats()
})

watch(() => event.value?.id, () => {
  void loadStats()
})
</script>

<template>
  <div class="teams-registration-stats">
    <h3 class="text-xs font-semibold tracking-wide text-[var(--color-text-muted)] mb-2">
      Aktuelle Zahlen aus der Anmeldung
    </h3>

    <div v-if="loading" class="text-sm text-[var(--color-text-muted)]">Laden…</div>

    <div v-else class="teams-registration-stats__table-wrap overflow-x-auto">
      <table class="teams-registration-stats__table w-full text-sm border-collapse">
        <thead>
          <tr>
            <th class="text-left font-medium text-[var(--color-text-muted)] py-1 pr-2"/>
            <th
                v-for="prog in programs"
                :key="programSlug(prog.name)"
                class="text-center font-medium py-1 px-1"
            >
              <ProgramLogo :program="prog" size="sm" class="inline-block"/>
            </th>
            <th
                v-if="showTotalColumn"
                class="text-center font-medium text-[var(--color-text-muted)] py-1 px-1"
            >
              Gesamt
            </th>
          </tr>
        </thead>
        <tbody class="text-[var(--color-text)]">
          <tr class="border-t border-[var(--color-border)]">
            <td class="py-1.5 pr-2 text-[var(--color-text-muted)]">Coaches</td>
            <td
                v-for="prog in programs"
                :key="`c-${programSlug(prog.name)}`"
                class="text-center tabular-nums py-1.5 px-1 font-medium"
            >
              {{ statForSlug(programSlug(prog.name), 'coaches') }}
            </td>
            <td v-if="showTotalColumn" class="text-center tabular-nums py-1.5 px-1 font-semibold">
              {{ totalCoaches }}
            </td>
          </tr>
          <tr class="border-t border-[var(--color-border)]">
            <td class="py-1.5 pr-2 text-[var(--color-text-muted)]">Teammitglieder</td>
            <td
                v-for="prog in programs"
                :key="`m-${programSlug(prog.name)}`"
                class="text-center tabular-nums py-1.5 px-1 font-medium"
            >
              {{ statForSlug(programSlug(prog.name), 'members') }}
            </td>
            <td v-if="showTotalColumn" class="text-center tabular-nums py-1.5 px-1 font-semibold">
              {{ totalMembers }}
            </td>
          </tr>
          <tr class="border-t border-[var(--color-border)]">
            <td class="py-1.5 pr-2 text-[var(--color-text-muted)] font-medium">Gesamt</td>
            <td
                v-for="prog in programs"
                :key="`t-${programSlug(prog.name)}`"
                class="text-center tabular-nums py-1.5 px-1 font-semibold"
            >
              {{ totalForSlug(programSlug(prog.name)) }}
            </td>
            <td v-if="showTotalColumn" class="text-center tabular-nums py-1.5 px-1 font-bold">
              {{ grandTotal }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.teams-registration-stats {
  border-radius: var(--radius-lg);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-tile-bg);
  padding: 0.85rem 1rem;
  box-shadow:
    0 6px 14px rgba(15, 23, 42, 0.06),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
  height: fit-content;
}

.teams-registration-stats__table {
  font-size: 0.8125rem;
}

.teams-registration-stats__table th,
.teams-registration-stats__table td {
  padding-left: 0.25rem;
  padding-right: 0.25rem;
}
</style>
