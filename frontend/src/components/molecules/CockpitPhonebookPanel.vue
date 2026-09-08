<script setup lang="ts">
import {onBeforeUnmount, ref, watch} from 'vue'
import type {AxiosInstance} from 'axios'
import PersonListHit from '@/components/molecules/PersonListHit.vue'

type PhonebookContact = {
  id: string
  kind: 'coach' | 'volunteer'
  name: string
  subtitle: string | null
  logo_stem?: string | null
  scope_kind?: string | null
  mobile: string | null
  status: string | null
  checked_in_at: string | null
}

const props = defineProps<{
  slug: string
  http: AxiosInstance
}>()

const query = ref('')
const contacts = ref<PhonebookContact[]>([])
const loading = ref(false)
const error = ref('')
let debounceTimer: ReturnType<typeof setTimeout> | null = null

function statusLabel(hit: PhonebookContact) {
  if (hit.status === 'no_show') return 'No-Show'
  if (hit.status === 'checked_in') return 'Da'
  return 'Offen'
}

function hitScopeIcon(hit: PhonebookContact) {
  if (hit.scope_kind === 'cross') return 'bi-intersect'
  if (hit.scope_kind === 'local') return 'bi-star'
  return ''
}

async function runSearch() {
  const q = query.value.trim()
  error.value = ''
  if (q.length < 2) {
    contacts.value = []
    loading.value = false
    return
  }
  loading.value = true
  try {
    const {data} = await props.http.get(`/cockpit/${props.slug}/phonebook`, {params: {q}})
    contacts.value = Array.isArray(data.contacts) ? data.contacts : []
  } catch (e: any) {
    contacts.value = []
    error.value = e?.response?.data?.error || 'Suche fehlgeschlagen.'
  } finally {
    loading.value = false
  }
}

function onQueryInput() {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    void runSearch()
  }, 250)
}

watch(
  () => props.slug,
  () => {
    query.value = ''
    contacts.value = []
    error.value = ''
  },
)

onBeforeUnmount(() => {
  if (debounceTimer) clearTimeout(debounceTimer)
})
</script>

<template>
  <div class="cp-phonebook">
    <input
        v-model="query"
        type="search"
        class="glass-input cp-phonebook__input"
        placeholder="Suche nach Name, Team, Rolle…"
        autocomplete="off"
        aria-label="Suche"
        @input="onQueryInput"
    >

    <p v-if="query.trim().length > 0 && query.trim().length < 2" class="cp-phonebook__hint">
      Mindestens 2 Zeichen.
    </p>
    <p v-else-if="loading" class="cp-phonebook__hint">Suche…</p>
    <p v-else-if="error" class="glass-alert-error !mb-0">{{ error }}</p>
    <p v-else-if="query.trim().length >= 2 && !contacts.length" class="cp-phonebook__hint">
      Keine Treffer.
    </p>

    <ul v-if="contacts.length" class="ci-list">
      <li v-for="hit in contacts" :key="hit.id">
        <PersonListHit
            :label="hit.name"
            :subtitle="hit.subtitle"
            :logo-stem="hit.logo_stem"
            :scope-icon="hitScopeIcon(hit)"
            :status="hit.status"
            :status-title="statusLabel(hit)"
            :mobile="hit.mobile"
            show-call
        />
      </li>
    </ul>
  </div>
</template>

<style scoped>
.cp-phonebook {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.cp-phonebook__input {
  width: 100%;
}

.cp-phonebook__hint {
  margin: 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
}

.ci-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
</style>
