<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import RobotGameRoundsPanel from '@/components/molecules/RobotGameRoundsPanel.vue'
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import {eventPrograms} from '@/utils/eventPrograms'

defineOptions({name: 'EventDayControl'})

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const publicationLevel = ref<number | null>(null)
const loadingPublicationLevel = ref(false)

const isHighestPublicationLevel = computed(() => publicationLevel.value === 4)

async function fetchPublicationLevel() {
  if (!event.value?.id) return
  loadingPublicationLevel.value = true
  try {
    const {data} = await axios.get(`/publish/level/${event.value.id}`)
    publicationLevel.value = data?.level ?? 1
  } catch (error) {
    console.error('Error fetching publication level:', error)
    publicationLevel.value = null
  } finally {
    loadingPublicationLevel.value = false
  }
}

watch(() => event.value?.id, fetchPublicationLevel, {immediate: true})
</script>

<template>
  <div class="space-y-4 sm:space-y-6">
    <section class="glass-surface-lg">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h1 class="text-xl sm:text-2xl font-bold text-[var(--color-text)] truncate">{{ event?.name || 'am Tag' }}</h1>
            <i
                v-if="!loadingPublicationLevel && isHighestPublicationLevel"
                class="bi bi-check-circle-fill text-green-600 text-base flex-shrink-0"
                title="Plan veröffentlicht"
                aria-label="Plan veröffentlicht"
            ></i>
          </div>
          <div
              v-if="!loadingPublicationLevel && !isHighestPublicationLevel"
              class="glass-alert-warning mt-2 !mb-0 inline-flex items-center gap-1.5 !text-xs"
          >
            <i class="bi bi-exclamation-circle-fill text-[var(--color-accent)]" aria-hidden="true"></i>
            <span>Plan nicht vollständig veröffentlicht.</span>
          </div>
        </div>
        <div class="flex items-center gap-2" v-if="event">
          <img
              v-for="program in eventPrograms(event)"
              :key="program.first_program"
              :src="programLogoSrc(program.first_program)"
              :alt="programLogoAlt(program.name || program.first_program)"
              class="w-8 h-8 flex-shrink-0"
          />
        </div>
      </div>
    </section>

    <RobotGameRoundsPanel/>

    <section class="glass-surface-lg">
      <h2 class="glass-card__heading">Weitere Live-Tools</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Hier werden später weitere mobile Funktionen für den Veranstaltungstag ergänzt werden.
      </p>
    </section>
  </div>
</template>
