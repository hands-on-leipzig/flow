<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import {showGlassToast} from '@/composables/useGlassToast'
import {useEventStore} from '@/stores/event'

defineOptions({name: 'AccessManagement'})

type Partner = {
  id: number
  name: string
  region?: string | null
  source?: string | null
}

type AccessUser = {
  user_id: number
  name: string | null
  email: string | null
  last_login: string | null
  source: string
  granted_by_name: string | null
  is_self: boolean
}

type EventRow = {
  id: number
  name: string
  date: string
  level: string | null
  season_id?: number | null
  season_name?: string | null
  season_year?: number | null
  regional_partner_id: number
  regional_partner_name: string | null
}

type SeasonGroup = {
  season: {id: number | null; name: string | null; year: number | null}
  events: EventRow[]
}

type SearchUser = {
  id: number
  name: string | null
  email: string | null
  display_name: string
}

const eventStore = useEventStore()
const loading = ref(true)
const error = ref('')
const myPartners = ref<Partner[]>([])
const eventsBySeason = ref<SeasonGroup[]>([])
const events = ref<EventRow[]>([])

const shareEventId = ref<number | null>(null)
const accessUsers = ref<AccessUser[]>([])
const usersLoading = ref(false)

const grantQuery = ref('')
const grantResults = ref<SearchUser[]>([])
const grantSelected = ref<SearchUser | null>(null)
const grantBusy = ref(false)
const showGrantDropdown = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | undefined

const shareEvent = computed(() =>
    events.value.find((e) => e.id === shareEventId.value) ?? null
)

const eventCount = computed(() => events.value.length)

async function loadOverview() {
  loading.value = true
  error.value = ''
  try {
    const params: Record<string, number> = {}
    if (eventStore.selectedEvent?.id) {
      params.event = eventStore.selectedEvent.id
    }
    const {data} = await axios.get('/user/access', {params})
    myPartners.value = data.my_partners || []
    eventsBySeason.value = data.events_by_season || []
    events.value = data.events || []

    const preferred = data.selected_event_id
        || eventStore.selectedEvent?.id
        || events.value[0]?.id
        || null
    shareEventId.value = preferred && events.value.some((e) => e.id === preferred)
        ? preferred
        : (events.value[0]?.id ?? null)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Zugangsdaten konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

async function loadEventUsers() {
  if (!shareEventId.value) {
    accessUsers.value = []
    return
  }
  usersLoading.value = true
  try {
    const {data} = await axios.get(`/user/access/events/${shareEventId.value}/users`)
    accessUsers.value = data.users || []
  } catch (e: any) {
    accessUsers.value = []
    showGlassToast(e?.response?.data?.error || 'Accounts konnten nicht geladen werden.', 'error')
  } finally {
    usersLoading.value = false
  }
}

function onSearchInput() {
  grantSelected.value = null
  if (searchTimer) clearTimeout(searchTimer)
  const q = grantQuery.value.trim()
  if (q.length < 2) {
    grantResults.value = []
    showGrantDropdown.value = false
    return
  }
  searchTimer = setTimeout(async () => {
    try {
      const {data} = await axios.get('/user/access/users', {params: {q}})
      grantResults.value = data.users || []
      showGrantDropdown.value = grantResults.value.length > 0
    } catch {
      grantResults.value = []
      showGrantDropdown.value = false
    }
  }, 220)
}

function selectGrantUser(user: SearchUser) {
  grantSelected.value = user
  grantQuery.value = user.display_name
  showGrantDropdown.value = false
}

async function grantAccess() {
  if (!shareEventId.value || !grantSelected.value) {
    showGlassToast('Bitte Veranstaltung und Account wählen.', 'info')
    return
  }
  grantBusy.value = true
  try {
    const {data} = await axios.post('/user/access/grants', {
      user_id: grantSelected.value.id,
      event_id: shareEventId.value,
    })
    accessUsers.value = data.users || []
    grantQuery.value = ''
    grantSelected.value = null
    grantResults.value = []
    showGlassToast('Zugriff gewährt.', 'success')
  } catch (e: any) {
    showGlassToast(e?.response?.data?.error || 'Zugriff konnte nicht gewährt werden.', 'error')
  } finally {
    grantBusy.value = false
  }
}

async function revokeAccess(row: AccessUser) {
  if (row.source === 'draht' || row.is_self || !shareEventId.value) return
  try {
    const {data} = await axios.delete('/user/access/grants', {
      data: {
        user_id: row.user_id,
        event_id: shareEventId.value,
      },
    })
    accessUsers.value = data.users || []
    showGlassToast('Zugriff entfernt.', 'success')
  } catch (e: any) {
    showGlassToast(e?.response?.data?.error || 'Zugriff konnte nicht entfernt werden.', 'error')
  }
}

function sourceLabel(source: string) {
  return source === 'manual' ? 'Einladung' : 'Draht'
}

watch(shareEventId, (id, prev) => {
  if (id && id !== prev && !loading.value) void loadEventUsers()
})

onMounted(async () => {
  await loadOverview()
  await loadEventUsers()
})
</script>

<template>
  <div class="space-y-6 max-w-4xl">
    <div>
      <h1 class="text-2xl font-bold tracking-tight">Zugangsverwaltung</h1>
      <p class="mt-1 text-sm text-[var(--color-text-muted)]">
        Deine Regionalpartner, die Veranstaltungen dazu — und wen du einladen kannst.
      </p>
    </div>

    <div v-if="loading" class="glass-card liquid-surface-inner p-6 text-[var(--color-text-muted)]">
      Lade Zugangsdaten…
    </div>

    <div v-else-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">
      {{ error }}
    </div>

    <template v-else>
      <!-- 1. My partners -->
      <section class="glass-card liquid-surface-inner p-5 space-y-3">
        <div class="flex items-baseline justify-between gap-2">
          <h2 class="font-bold text-lg">Meine Regionalpartner</h2>
          <span class="text-xs text-[var(--color-text-muted)]">{{ myPartners.length }}</span>
        </div>
        <p class="text-sm text-[var(--color-text-muted)]">
          Dein Account ist diesen Regionalpartnern zugeordnet.
        </p>
        <p v-if="!myPartners.length" class="text-sm text-[var(--color-text-muted)]">
          Noch keine Zuordnung. Typischerweise kommt sie aus Draht (Kontaktperson).
        </p>
        <ul v-else class="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <li
              v-for="p in myPartners"
              :key="p.id"
              class="rounded-lg border border-[var(--color-border)] px-3 py-2.5 text-sm"
          >
            <div class="flex items-center justify-between gap-2">
              <span class="font-semibold truncate">{{ p.name }}</span>
              <span class="glass-chip !px-2 !py-0.5 !text-xs shrink-0">{{ sourceLabel(p.source || 'draht') }}</span>
            </div>
            <p v-if="p.region" class="text-xs text-[var(--color-text-muted)] mt-0.5">{{ p.region }}</p>
          </li>
        </ul>
      </section>

      <!-- 2. My events -->
      <section class="glass-card liquid-surface-inner p-5 space-y-4">
        <div class="flex items-baseline justify-between gap-2">
          <div>
            <h2 class="font-bold text-lg">Meine Veranstaltungen</h2>
            <p class="text-sm text-[var(--color-text-muted)]">
              Darüber hast du Zugang — inkl. früherer Saisons.
            </p>
          </div>
          <span class="text-xs font-semibold text-[var(--color-text-muted)]">{{ eventCount }}</span>
        </div>

        <p v-if="!eventsBySeason.length" class="text-sm text-[var(--color-text-muted)]">
          Keine Veranstaltungen zu deinen Regionalpartnern.
        </p>

        <div v-else class="space-y-5">
          <div v-for="group in eventsBySeason" :key="group.season.id ?? group.season.name">
            <h3 class="text-sm font-bold text-[var(--color-accent)] mb-2">
              {{ group.season.name || 'Saison' }}
              <span v-if="group.season.year" class="text-[var(--color-text-muted)] font-semibold">
                · {{ group.season.year }}
              </span>
            </h3>
            <ul class="space-y-1.5">
              <li
                  v-for="ev in group.events"
                  :key="ev.id"
                  class="flex flex-wrap items-baseline justify-between gap-2 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm"
              >
                <div class="min-w-0">
                  <p class="font-semibold truncate">{{ ev.name }}</p>
                  <p class="text-xs text-[var(--color-text-muted)]">
                    {{ dayjs(ev.date).format('DD.MM.YYYY') }}
                    <span v-if="ev.level"> · {{ ev.level }}</span>
                    <span v-if="ev.regional_partner_name"> · {{ ev.regional_partner_name }}</span>
                  </p>
                </div>
                <button
                    type="button"
                    class="text-xs font-semibold text-[var(--color-accent)] hover:underline shrink-0"
                    @click="shareEventId = ev.id"
                >
                  Zugriff teilen
                </button>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- 3. Share access for a specific event -->
      <section class="glass-card liquid-surface-inner p-5 space-y-4">
        <div>
          <h2 class="font-bold text-lg">Zugriff teilen</h2>
          <p class="text-sm text-[var(--color-text-muted)]">
            Wähle eine Veranstaltung und lade einen anderen FLOW-Account ein.
          </p>
        </div>

        <div v-if="!events.length" class="text-sm text-[var(--color-text-muted)]">
          Ohne eigene Veranstaltungen kannst du hier niemanden einladen.
        </div>

        <template v-else>
          <label class="block text-sm max-w-xl">
            <span class="block text-xs font-semibold text-[var(--color-text-subtle)] mb-1">Veranstaltung</span>
            <select
                v-model.number="shareEventId"
                class="w-full rounded-lg border border-[var(--color-border)] bg-white px-3 py-2"
            >
              <optgroup
                  v-for="group in eventsBySeason"
                  :key="group.season.id ?? group.season.name"
                  :label="`${group.season.name || 'Saison'}${group.season.year ? ` (${group.season.year})` : ''}`"
              >
                <option
                    v-for="ev in group.events"
                    :key="ev.id"
                    :value="ev.id"
                >
                  {{ ev.name }} — {{ dayjs(ev.date).format('DD.MM.YYYY') }}
                </option>
              </optgroup>
            </select>
          </label>

          <p v-if="shareEvent" class="text-xs text-[var(--color-text-muted)]">
            Eingeladene Personen können diese Veranstaltung in FLOW öffnen
            <span v-if="shareEvent.regional_partner_name">
              (Regionalpartner {{ shareEvent.regional_partner_name }})
            </span>.
          </p>

          <div class="rounded-xl border border-[var(--color-border)] bg-[color-mix(in_srgb,var(--color-bg-muted)_40%,#fff)] p-3 space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-subtle)]">
              Account einladen
            </p>
            <p class="text-xs text-[var(--color-text-muted)]">
              Die Person muss sich mindestens einmal bei FLOW angemeldet haben.
            </p>
            <div class="flex flex-col sm:flex-row gap-2">
              <div class="relative flex-1">
                <input
                    v-model="grantQuery"
                    type="search"
                    class="w-full rounded-lg border border-[var(--color-border)] bg-white px-3 py-2 text-sm"
                    placeholder="Name oder E-Mail suchen…"
                    autocomplete="off"
                    @input="onSearchInput"
                    @focus="showGrantDropdown = grantResults.length > 0"
                />
                <ul
                    v-if="showGrantDropdown"
                    class="absolute z-10 mt-1 max-h-48 w-full overflow-auto rounded-lg border border-[var(--color-border)] bg-white shadow-lg"
                >
                  <li v-for="u in grantResults" :key="u.id">
                    <button
                        type="button"
                        class="w-full px-3 py-2 text-left text-sm hover:bg-[var(--color-bg-muted)]"
                        @click="selectGrantUser(u)"
                    >
                      {{ u.display_name }}
                    </button>
                  </li>
                </ul>
              </div>
              <button
                  type="button"
                  class="glass-btn-accent !px-4 !py-2 !text-sm shrink-0"
                  :disabled="grantBusy || !grantSelected || !shareEventId"
                  @click="grantAccess"
              >
                {{ grantBusy ? '…' : 'Einladen' }}
              </button>
            </div>
          </div>

          <div>
            <h3 class="text-sm font-bold mb-2">
              Wer hat Zugriff
              <span v-if="shareEvent" class="font-medium text-[var(--color-text-muted)]">
                · {{ shareEvent.name }}
              </span>
            </h3>
            <div v-if="usersLoading" class="text-sm text-[var(--color-text-muted)]">Lade…</div>
            <p v-else-if="!accessUsers.length" class="text-sm text-[var(--color-text-muted)]">
              Noch keine weiteren Accounts.
            </p>
            <ul v-else class="divide-y divide-[var(--color-border)]">
              <li
                  v-for="row in accessUsers"
                  :key="row.user_id"
                  class="flex flex-wrap items-center justify-between gap-3 py-3"
              >
                <div class="min-w-0">
                  <p class="font-semibold truncate">
                    {{ row.name || 'Ohne Namen' }}
                    <span v-if="row.is_self" class="text-xs font-medium text-[var(--color-accent)]">(du)</span>
                  </p>
                  <p class="text-sm text-[var(--color-text-muted)] truncate">{{ row.email || '—' }}</p>
                  <p class="text-xs text-[var(--color-text-subtle)] mt-0.5">
                    {{ sourceLabel(row.source) }}
                    <template v-if="row.source === 'manual' && row.granted_by_name">
                      · von {{ row.granted_by_name }}
                    </template>
                  </p>
                </div>
                <button
                    v-if="row.source === 'manual' && !row.is_self"
                    type="button"
                    class="glass-btn-secondary !px-3 !py-1.5 !text-sm text-red-700"
                    @click="revokeAccess(row)"
                >
                  Entfernen
                </button>
                <span
                    v-else-if="row.source === 'draht'"
                    class="glass-chip !px-2 !py-0.5 !text-xs"
                >Draht</span>
              </li>
            </ul>
          </div>
        </template>
      </section>
    </template>
  </div>
</template>
