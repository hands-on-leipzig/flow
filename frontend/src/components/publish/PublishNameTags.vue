<script setup lang="ts">
/**
 * Ausgabe → Namensschilder: Namensaufkleber für Teams und Volunteers.
 */
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import NameTagsPrint from '@/components/molecules/NameTagsPrint.vue'
import NameTagPreview from '@/components/molecules/NameTagPreview.vue'
import NoticePane from '@/components/molecules/NoticePane.vue'
import {useEventStore} from '@/stores/event'

defineOptions({name: 'PublishNameTags'})

type EventLogo = {
  id: number
  title?: string | null
  url: string
  sort_order?: number
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const logos = ref<EventLogo[]>([])
const selectedLogoId = ref<number | null>(null)

const selectedLogo = computed(() =>
  logos.value.find((logo) => logo.id === selectedLogoId.value) ?? null,
)

async function loadLogos() {
  if (!eventId.value) {
    logos.value = []
    selectedLogoId.value = null
    return
  }
  try {
    const {data} = await axios.get<EventLogo[]>(`/events/${eventId.value}/logos`)
    logos.value = Array.isArray(data) ? data : []
    if (selectedLogoId.value != null && !logos.value.some((logo) => logo.id === selectedLogoId.value)) {
      selectedLogoId.value = null
    }
  } catch {
    logos.value = []
    selectedLogoId.value = null
  }
}

function toggleLogo(id: number) {
  selectedLogoId.value = selectedLogoId.value === id ? null : id
}

watch(eventId, () => {
  void loadLogos()
}, {immediate: true})
</script>

<template>
  <div class="vol-page namensschilder-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Namensschilder</h1>
        <p class="vol-page__sub">Erstellen von PDFs zum Druck von Aufklebern</p>
      </div>
    </header>

    <NoticePane/>

    <p class="text-sm text-[var(--color-text-muted)] mb-3">
      Die PDF-Dateien sind passend zum
      <a
          href="https://www.avery-zweckform.com/vorlage-l4785"
          target="_blank"
          rel="noopener noreferrer"
          class="text-[var(--color-accent)] underline hover:opacity-80"
      >Format Avery L4785</a>
      formatiert.
      Mit „Überspringen“ können die ersten Aufkleber auf dem ersten Blatt übersprungen werden, um teilweise bereits verwendete Blätter weiter zu nutzen.
    </p>

    <div class="namensschilder-panes">
      <NameTagsPrint :logo-id="selectedLogoId"/>
      <section class="glass-card liquid-surface-inner namensschilder-logo">
        <NameTagPreview :organizer-url="selectedLogo?.url ?? null"/>
        <p class="text-sm text-[var(--color-text-muted)]">Drittes Logo auswählen</p>
        <p v-if="logos.length === 0" class="text-sm text-[var(--color-text-muted)]">
          Kein aktives Veranstalter-Logo. PDF enthält Programm- und Saisonlogo.
        </p>
        <div v-else class="namensschilder-logo__thumbs">
          <button
              v-for="logo in logos"
              :key="logo.id"
              type="button"
              class="namensschilder-logo__thumb"
              :class="{'namensschilder-logo__thumb--on': selectedLogoId === logo.id}"
              :aria-pressed="selectedLogoId === logo.id"
              @click="toggleLogo(logo.id)"
          >
            <img :src="logo.url" :alt="logo.title || 'Veranstalter-Logo'"/>
          </button>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.namensschilder-panes {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 1rem 1.25rem;
  align-items: start;
  min-width: 0;
}

.namensschilder-panes > :first-child {
  flex: 1 1 16rem;
  min-width: 0;
}

.namensschilder-logo {
  flex: 0 1 18rem;
  min-width: 16rem;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding: 1rem 1.05rem;
}

.namensschilder-logo__thumbs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.namensschilder-logo__thumb {
  width: 4.5rem;
  height: 3.25rem;
  padding: 0.25rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  border-radius: var(--radius);
  background: #fff;
  cursor: pointer;
}

.namensschilder-logo__thumb--on {
  outline: 2px solid var(--color-accent);
  outline-offset: 1px;
}

.namensschilder-logo__thumb img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
</style>
