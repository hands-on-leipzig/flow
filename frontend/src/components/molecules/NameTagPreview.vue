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
const programSrc = computed(() => programLogoSrc(programRef.value, 'hs'))
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

<style>
@font-face {
  font-family: 'Noto Sans';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('../../../../backend/resources/fonts/noto/NotoSans-Regular.ttf') format('truetype');
}

@font-face {
  font-family: 'Noto Sans';
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url('../../../../backend/resources/fonts/noto/NotoSans-Bold.ttf') format('truetype');
}
</style>

<style scoped>
/* Avery L4785 cell: 80mm × 50mm, 5mm padding, Noto 18/12 pt, logos ≤ 20×15 mm. */
.logo-nametag {
  box-sizing: border-box;
  width: 80mm;
  height: 50mm;
  margin: 0 auto;
  padding: 5mm;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 55%, transparent);
  border-radius: 0;
  background: #fff;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
  font-family: 'Noto Sans', Helvetica, Arial, sans-serif;
}

.logo-nametag__person {
  font-size: 18pt;
  font-weight: 700;
  line-height: 8mm;
  color: #000;
}

.logo-nametag__team {
  margin-top: 0;
  font-size: 12pt;
  font-weight: 400;
  line-height: 6mm;
  color: #333;
}

.logo-nametag__logos {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 0;
  min-height: 15mm;
}

.logo-nametag__logo {
  max-width: 20mm;
  max-height: 15mm;
  width: auto;
  height: auto;
  object-fit: contain;
  object-position: bottom;
}
</style>
