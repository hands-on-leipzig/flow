<script setup lang="ts">
import {onBeforeUnmount, ref, watch} from 'vue'
import type {AxiosInstance} from 'axios'

type PhonebookContact = {
  id: string
  kind: 'coach' | 'volunteer'
  name: string
  subtitle: string | null
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

function telHref(mobile: string) {
  return `tel:${mobile.replace(/[^\d+]/g, '')}`
}

function statusLabel(hit: PhonebookContact) {
  if (hit.status === 'no_show') return 'No-Show'
  if (hit.status === 'checked_in') return 'Da'
  return 'Offen'
}

function statusIcon(status: string | null) {
  if (status === 'no_show') return 'bi-x-circle-fill'
  if (status === 'checked_in') return 'bi-check-circle-fill'
  return 'bi-circle'
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

    <ul v-if="contacts.length" class="cp-phonebook__list">
      <li v-for="hit in contacts" :key="hit.id">
        <div class="cp-phonebook__hit liquid-surface-inner">
          <div class="cp-phonebook__main">
            <div class="cp-phonebook__row">
              <span class="cp-phonebook__name">{{ hit.name }}</span>
            </div>
            <div v-if="hit.subtitle" class="cp-phonebook__sub">{{ hit.subtitle }}</div>
          </div>
          <a
              v-if="hit.mobile"
              class="cp-phonebook__call"
              :href="telHref(hit.mobile)"
              :title="`Anrufen ${hit.mobile}`"
              @click.stop
          >
            <i class="bi bi-telephone-fill" aria-hidden="true"/>
            <span class="sr-only">Anrufen</span>
          </a>
          <span class="cp-phonebook__trailing">
            <span
                class="cp-phonebook__status"
                :class="{
                  'cp-phonebook__status--in': hit.status === 'checked_in',
                  'cp-phonebook__status--no': hit.status === 'no_show',
                }"
                :title="statusLabel(hit)"
            >
              <i class="bi" :class="statusIcon(hit.status)" aria-hidden="true"/>
              <span class="sr-only">{{ statusLabel(hit) }}</span>
            </span>
          </span>
        </div>
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

.cp-phonebook__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.cp-phonebook__hit {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.75rem 0.85rem;
  border-radius: var(--radius);
}

.cp-phonebook__main {
  flex: 1;
  min-width: 0;
}

.cp-phonebook__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.cp-phonebook__name {
  font-weight: 700;
  font-size: 1rem;
  line-height: 1.3;
  color: var(--color-text);
  overflow-wrap: anywhere;
}

.cp-phonebook__trailing {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  flex-shrink: 0;
}

.cp-phonebook__status {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  color: var(--color-text-muted);
  font-size: 1.05rem;
}

.cp-phonebook__status--in {
  color: #16a34a;
}

.cp-phonebook__status--no {
  color: #dc2626;
}

.cp-phonebook__sub {
  margin-top: 0.2rem;
  font-size: 0.85rem;
  color: var(--color-text-muted);
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.cp-phonebook__call {
  flex-shrink: 0;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: var(--radius);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-tile-bg);
  color: var(--color-accent, var(--color-text));
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  font-size: 1.2rem;
}

.cp-phonebook__call:active {
  opacity: 0.85;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
