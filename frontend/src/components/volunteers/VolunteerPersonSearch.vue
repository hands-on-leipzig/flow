<script setup lang="ts">
import {computed, ref} from 'vue'
import {RouterLink} from 'vue-router'
import {type VolunteerPersonRef, volunteerDisplayName, volunteerSearchHaystack} from '@/utils/volunteerPerson'

const volunteersPeopleRoute = {name: 'volunteers-people'} as const

const props = defineProps<{
  pool: VolunteerPersonRef[]
  onRoster: (personId: number) => boolean
  busyPersonId?: number | null
  placeholder?: string
}>()

const emit = defineEmits<{
  select: [person: VolunteerPersonRef]
}>()

const search = ref('')

const hasPeople = computed(() => props.pool.length > 0)

const inputPlaceholder = computed(() => props.placeholder ?? 'Personen zum Hinzufügen zur Liste suchen…')

const matches = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return []

  return props.pool
    .filter((person) => volunteerSearchHaystack(person).includes(q))
    .sort((a, b) => {
      const av = volunteerDisplayName(a).toLocaleLowerCase('de')
      const bv = volunteerDisplayName(b).toLocaleLowerCase('de')
      if (av < bv) return -1
      if (av > bv) return 1
      return a.id - b.id
    })
})

function onChipClick(person: VolunteerPersonRef) {
  if (props.onRoster(person.id) || props.busyPersonId === person.id) return
  emit('select', person)
}
</script>

<template>
  <section class="glass-card liquid-surface-inner vol-tile vol-search-tile">
    <div class="vol-person-search-field">
      <input
          v-model="search"
          type="search"
          class="glass-input glass-input--sm vol-search-tile__input"
          :placeholder="hasPeople ? inputPlaceholder : ''"
          :disabled="!hasPeople"
          autocomplete="off"
      />
      <RouterLink
          v-if="!hasPeople"
          :to="volunteersPeopleRoute"
          class="vol-person-search-empty-link"
      >
        Bitte Personen anlegen.
      </RouterLink>
    </div>
    <div v-if="search.trim()" class="vol-search-results">
      <p v-if="!matches.length" class="vol-muted">
        Keine Treffer in der Personenliste.
      </p>
      <div v-else class="vol-search-chips">
        <button
            v-for="person in matches"
            :key="person.id"
            type="button"
            class="glass-row-item vol-search-chip"
            :class="onRoster(person.id) ? 'vol-search-chip--on' : 'glass-row-item--interactive'"
            :disabled="busyPersonId === person.id"
            @click="onChipClick(person)"
        >
          <i
              class="bi vol-search-chip__icon"
              :class="onRoster(person.id) ? 'bi-clipboard-check-fill vol-search-chip__icon--roster' : 'bi-person-fill'"
              aria-hidden="true"
          />
          <span class="vol-search-chip__label">{{ volunteerDisplayName(person) }}</span>
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.vol-search-tile {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.vol-search-tile__input {
  width: 100%;
}

.vol-search-results {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.vol-search-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.vol-search-chip {
  font-size: 0.75rem;
  padding: 0.35rem 0.5rem;
  gap: 0.4rem;
}

.vol-search-chip--on {
  cursor: default;
}

.vol-search-chip__icon {
  color: var(--color-text-subtle);
}

.vol-search-chip__icon--roster {
  color: var(--color-accent);
}

.vol-search-chip__label {
  padding: 0;
}

.vol-search-chip:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
</style>
