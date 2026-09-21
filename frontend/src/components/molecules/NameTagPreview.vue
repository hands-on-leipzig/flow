<script setup lang="ts">
import {computed} from 'vue'
import {useEventStore} from '@/stores/event'
import {eventPrograms} from '@/utils/eventPrograms'
import {programLogoSrc, seasonLogoSrc} from '@/utils/images'

defineOptions({name: 'NameTagPreview'})

const props = defineProps<{
  organizerUrl?: string | null
}>()

const eventStore = useEventStore()
const seasonName = computed(() => {
  const event = eventStore.selectedEvent as {season_rel?: {name?: string}; seasonRel?: {name?: string}} | null
  return event?.season_rel?.name || event?.seasonRel?.name || null
})
const programRef = computed(() => eventPrograms(eventStore.selectedEvent)[0] ?? null)
const seasonSrc = computed(() => seasonLogoSrc(seasonName.value, 'v'))
const programSrc = computed(() => programLogoSrc(programRef.value, 'h'))
</script>

<template>
  <div class="logo-nametag" aria-label="Namensaufkleber-Vorschau">
    <div class="logo-nametag__text">
      <div class="logo-nametag__person">Max Mustermann</div>
      <div class="logo-nametag__team">Team Beispiel</div>
    </div>
    <div class="logo-nametag__logos">
      <img
          :src="programSrc"
          alt="Programm"
          class="logo-nametag__logo"
      />
      <img
          :src="seasonSrc"
          alt="Saison"
          class="logo-nametag__logo"
      />
      <img
          v-if="props.organizerUrl"
          :src="props.organizerUrl"
          alt="Veranstalter"
          class="logo-nametag__logo"
      />
    </div>
  </div>
</template>

<style scoped>
.logo-nametag {
  width: 15rem;
  height: 9.375rem;
  margin: 0 auto;
  padding: 0.7rem 0.75rem 0.55rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 55%, transparent);
  border-radius: var(--radius);
  background: #fff;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
}

.logo-nametag__person {
  font-size: 1.05rem;
  font-weight: 700;
  line-height: 1.2;
  color: #111;
}

.logo-nametag__team {
  margin-top: 0.2rem;
  font-size: 0.8rem;
  line-height: 1.25;
  color: #333;
}

.logo-nametag__logos {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 0.35rem;
  min-height: 2.1rem;
}

.logo-nametag__logo {
  max-width: 3.1rem;
  max-height: 2.1rem;
  width: auto;
  height: auto;
  object-fit: contain;
}
</style>
