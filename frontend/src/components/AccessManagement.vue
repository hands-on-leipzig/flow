<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import {showGlassToast} from '@/composables/useGlassToast'
import {useEventStore} from '@/stores/event'
import NoticePane from '@/components/molecules/NoticePane.vue'

defineOptions({name: 'AccessManagement'})

type Permissions = {
  can_flow: boolean
  can_scor?: boolean
  can_scor_referee?: boolean
  can_scor_jury?: boolean
  can_scor_admin?: boolean
}

type Partner = Permissions & {
  id: number
  name: string
  region?: string | null
  source?: string | null
}

type AccessUser = Permissions & {
  user_id: number
  name: string | null
  email: string | null
  last_login: string | null
  source: string
  granted_by_name: string | null
  is_self: boolean
}

type Invitation = Permissions & {
  id: number
  email: string
  invited_at: string | null
  expires_at: string | null
  invited_by_name: string | null
}

type AccessLogEntry = {
  id: number
  created_at: string | null
  event_name: string | null
  action: string
  actor_name: string | null
  target_name: string | null
  target_email: string | null
  can_flow: boolean | null
  can_scor: boolean | null
}

type AccessPayload = {
  users?: AccessUser[]
  invitations?: Invitation[]
  log?: AccessLogEntry[]
  can_manage?: boolean
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

const eventStore = useEventStore()
const loading = ref(true)
const error = ref('')
const myPartners = ref<Partner[]>([])
const eventsBySeason = ref<SeasonGroup[]>([])
const events = ref<EventRow[]>([])

const shareEventId = ref<number | null>(null)
const accessUsers = ref<AccessUser[]>([])
const invitations = ref<Invitation[]>([])
const accessLog = ref<AccessLogEntry[]>([])
const canManage = ref(false)
const usersLoading = ref(false)

const inviteEmail = ref('')
const inviteFlow = ref(true)
const inviteReferee = ref(false)
const inviteJury = ref(false)
const inviteAdmin = ref(false)
const grantBusy = ref(false)

const canInvite = computed(() =>
    !!shareEventId.value
    && inviteEmail.value.includes('@')
    && (inviteFlow.value || inviteReferee.value || inviteJury.value || inviteAdmin.value)
)

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

function applyAccess(data: AccessPayload) {
  accessUsers.value = data.users || []
  invitations.value = data.invitations || []
  accessLog.value = data.log || []
  canManage.value = !!data.can_manage
}

async function loadEventUsers() {
  if (!shareEventId.value) {
    applyAccess({})
    return
  }
  usersLoading.value = true
  try {
    const {data} = await axios.get(`/user/access/events/${shareEventId.value}/users`)
    applyAccess(data)
  } catch (e: any) {
    applyAccess({})
    showGlassToast(e?.response?.data?.error || 'Accounts konnten nicht geladen werden.', 'error')
  } finally {
    usersLoading.value = false
  }
}

async function inviteByEmail() {
  if (!canInvite.value) {
    showGlassToast('Bitte E-Mail-Adresse und mindestens eine Berechtigung wählen.', 'info')
    return
  }
  grantBusy.value = true
  try {
    const {data} = await axios.post('/user/access/grants', {
      event_id: shareEventId.value,
      email: inviteEmail.value.trim(),
      can_flow: inviteFlow.value,
      can_scor_referee: inviteReferee.value,
      can_scor_jury: inviteJury.value,
      can_scor_admin: inviteAdmin.value,
    })
    applyAccess(data)
    inviteEmail.value = ''
    showGlassToast(
        data.mail_sent === false
            ? 'Einladung gespeichert. Die E-Mail konnte nicht gesendet werden.'
            : 'Einladung gespeichert.',
        data.mail_sent === false ? 'error' : 'success',
    )
  } catch (e: any) {
    showGlassToast(e?.response?.data?.error || 'Einladung fehlgeschlagen', 'error')
  } finally {
    grantBusy.value = false
  }
}

type Target = {user_id: number} | {invitation_id: number}

async function setPermission(target: Target, current: Permissions, key: keyof Permissions, value: boolean) {
  const next = {...current, [key]: value}
  const hasScor = Boolean(next.can_scor_referee || next.can_scor_jury || next.can_scor_admin)
  if (!next.can_flow && !hasScor) {
    showGlassToast('Mindestens eine Berechtigung muss bleiben.', 'info')
    await loadEventUsers()
    return
  }
  try {
    const {data} = await axios.patch('/user/access/grants', {
      ...target,
      event_id: shareEventId.value,
      ...next,
    })
    applyAccess(data)
  } catch (e: any) {
    showGlassToast(e?.response?.data?.error || 'Speichern fehlgeschlagen', 'error')
    await loadEventUsers()
  }
}

async function revoke(target: Target, message: string) {
  if (!shareEventId.value) return
  try {
    const {data} = await axios.delete('/user/access/grants', {
      data: {...target, event_id: shareEventId.value},
    })
    applyAccess(data)
    showGlassToast(message, 'success')
  } catch (e: any) {
    showGlassToast(e?.response?.data?.error || 'Entfernen fehlgeschlagen', 'error')
  }
}

function canEditRow(row: AccessUser) {
  return canManage.value && row.source === 'manual' && !row.is_self
}

function sourceLabel(source: string) {
  return source === 'manual' ? 'Einladung' : 'Draht'
}

function permissionLabels(p: {can_flow?: boolean | null; can_scor?: boolean | null}) {
  return [
    p.can_flow && 'FLOW',
    p.can_scor_referee && 'Schiri',
    p.can_scor_jury && 'Jury',
    p.can_scor_admin && 'Veranstaltung-Admin',
  ].filter(Boolean) as string[]
}

const actionLabels: Record<string, string> = {
  invited: 'Einladung',
  invitation_updated: 'Einladung geändert',
  invitation_withdrawn: 'Einladung zurückgezogen',
  claimed: 'Angenommen',
  expired: 'Abgelaufen',
  permissions_updated: 'Rechte geändert',
  revoked: 'Zugriff entfernt',
}

function actionLabel(action: string) {
  return actionLabels[action] || action
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
        Deine Regionalpartner, die Veranstaltungen dazu — und wen du pro Veranstaltung einladen kannst.
      </p>
    </div>

    <NoticePane/>

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
            <div class="mt-1.5 flex gap-1">
              <span
                  v-for="label in permissionLabels(p)"
                  :key="label"
                  class="glass-chip !px-2 !py-0.5 !text-xs"
              >{{ label }}</span>
            </div>
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
            Wähle eine Veranstaltung und lade eine Person per E-Mail ein. Der Zugang gilt nur für diese Veranstaltung.
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
            Eingeladene Personen können nur diese Veranstaltung öffnen. Der Zugang gilt nicht für andere Veranstaltungen des Regionalpartners.
          </p>

          <div
              v-if="canManage"
              class="rounded-xl border border-[var(--color-border)] bg-[color-mix(in_srgb,var(--color-bg-muted)_40%,#fff)] p-3 space-y-2"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-subtle)]">
              Person einladen
            </p>
            <p class="text-xs text-[var(--color-text-muted)]">
              Die Person braucht ein Hands-on-Login mit bestätigter E-Mail-Adresse.
              Die Einladung gilt 30 Tage und nur für die gewählte Veranstaltung.
              Eine E-Mail geht an die eingeladene Adresse.
            </p>
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
              <input
                  v-model="inviteEmail"
                  type="email"
                  class="flex-1 rounded-lg border border-[var(--color-border)] bg-white px-3 py-2 text-sm"
                  placeholder="name@beispiel.de"
                  autocomplete="off"
                  @keydown.enter.prevent="inviteByEmail"
              />
              <label class="inline-flex items-center gap-1.5 text-sm">
                <input v-model="inviteFlow" type="checkbox" class="accent-gray-600"/>
                FLOW
              </label>
              <label class="inline-flex items-center gap-1.5 text-sm">
                <input v-model="inviteReferee" type="checkbox" class="accent-gray-600"/>
                Schiri
              </label>
              <label class="inline-flex items-center gap-1.5 text-sm">
                <input v-model="inviteJury" type="checkbox" class="accent-gray-600"/>
                Jury
              </label>
              <label class="inline-flex items-center gap-1.5 text-sm">
                <input v-model="inviteAdmin" type="checkbox" class="accent-gray-600"/>
                Veranstaltung-Admin
              </label>
              <button
                  type="button"
                  class="glass-btn-accent !px-4 !py-2 !text-sm shrink-0"
                  :disabled="grantBusy || !canInvite"
                  @click="inviteByEmail"
              >
                {{ grantBusy ? '…' : 'Einladen' }}
              </button>
            </div>
          </div>
          <p v-else class="text-xs text-[var(--color-text-muted)]">
            Einladen und Berechtigungen ändern können nur Kontaktpersonen aus Draht.
          </p>

          <div>
            <h3 class="text-sm font-bold mb-2">
              Wer hat Zugriff
              <span v-if="shareEvent" class="font-medium text-[var(--color-text-muted)]">
                · {{ shareEvent.name }}
              </span>
            </h3>
            <div v-if="usersLoading" class="text-sm text-[var(--color-text-muted)]">Lade…</div>
            <p v-else-if="!accessUsers.length && !invitations.length" class="text-sm text-[var(--color-text-muted)]">
              Noch keine weiteren Accounts.
            </p>
            <ul v-else class="divide-y divide-[var(--color-border)]">
              <li
                  v-for="row in accessUsers"
                  :key="`u-${row.user_id}`"
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
                <div class="flex items-center gap-3">
                  <template v-if="canEditRow(row)">
                    <label class="inline-flex items-center gap-1.5 text-sm">
                      <input
                          type="checkbox"
                          class="accent-gray-600"
                          :checked="row.can_flow"
                          @change="setPermission({user_id: row.user_id}, row, 'can_flow', ($event.target as HTMLInputElement).checked)"
                      />
                      FLOW
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-sm">
                      <input
                          type="checkbox"
                          class="accent-gray-600"
                          :checked="row.can_scor_referee"
                          @change="setPermission({user_id: row.user_id}, row, 'can_scor_referee', ($event.target as HTMLInputElement).checked)"
                      />
                      Schiri
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-sm">
                      <input
                          type="checkbox"
                          class="accent-gray-600"
                          :checked="row.can_scor_jury"
                          @change="setPermission({user_id: row.user_id}, row, 'can_scor_jury', ($event.target as HTMLInputElement).checked)"
                      />
                      Jury
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-sm">
                      <input
                          type="checkbox"
                          class="accent-gray-600"
                          :checked="row.can_scor_admin"
                          @change="setPermission({user_id: row.user_id}, row, 'can_scor_admin', ($event.target as HTMLInputElement).checked)"
                      />
                      Veranstaltung-Admin
                    </label>
                    <button
                        type="button"
                        class="glass-btn-secondary !px-3 !py-1.5 !text-sm text-red-700"
                        @click="revoke({user_id: row.user_id}, 'Zugriff entfernt.')"
                    >
                      Entfernen
                    </button>
                  </template>
                  <template v-else>
                    <span
                        v-for="label in permissionLabels(row)"
                        :key="label"
                        class="glass-chip !px-2 !py-0.5 !text-xs"
                    >{{ label }}</span>
                  </template>
                </div>
              </li>
              <li
                  v-for="inv in invitations"
                  :key="`i-${inv.id}`"
                  class="flex flex-wrap items-center justify-between gap-3 py-3"
              >
                <div class="min-w-0">
                  <p class="font-semibold truncate">{{ inv.email }}</p>
                  <p class="text-xs text-[var(--color-text-subtle)] mt-0.5">
                    Eingeladen, wartet auf Anmeldung
                    <template v-if="inv.expires_at"> bis {{ dayjs(inv.expires_at).format('DD.MM.YYYY') }}</template>
                    <template v-if="inv.invited_by_name"> · von {{ inv.invited_by_name }}</template>
                  </p>
                </div>
                <div class="flex items-center gap-3">
                  <label class="inline-flex items-center gap-1.5 text-sm">
                    <input
                        type="checkbox"
                        class="accent-gray-600"
                        :checked="inv.can_flow"
                        @change="setPermission({invitation_id: inv.id}, inv, 'can_flow', ($event.target as HTMLInputElement).checked)"
                    />
                    FLOW
                  </label>
                  <label class="inline-flex items-center gap-1.5 text-sm">
                    <input
                        type="checkbox"
                        class="accent-gray-600"
                        :checked="inv.can_scor_referee"
                        @change="setPermission({invitation_id: inv.id}, inv, 'can_scor_referee', ($event.target as HTMLInputElement).checked)"
                    />
                    Schiri
                  </label>
                  <label class="inline-flex items-center gap-1.5 text-sm">
                    <input
                        type="checkbox"
                        class="accent-gray-600"
                        :checked="inv.can_scor_jury"
                        @change="setPermission({invitation_id: inv.id}, inv, 'can_scor_jury', ($event.target as HTMLInputElement).checked)"
                    />
                    Jury
                  </label>
                  <label class="inline-flex items-center gap-1.5 text-sm">
                    <input
                        type="checkbox"
                        class="accent-gray-600"
                        :checked="inv.can_scor_admin"
                        @change="setPermission({invitation_id: inv.id}, inv, 'can_scor_admin', ($event.target as HTMLInputElement).checked)"
                    />
                    Veranstaltung-Admin
                  </label>
                  <button
                      type="button"
                      class="glass-btn-secondary !px-3 !py-1.5 !text-sm text-red-700"
                      @click="revoke({invitation_id: inv.id}, 'Einladung zurückgezogen.')"
                  >
                    Zurückziehen
                  </button>
                </div>
              </li>
            </ul>
          </div>

          <div v-if="canManage">
            <h3 class="text-sm font-bold mb-2">Protokoll</h3>
            <p v-if="!accessLog.length" class="text-sm text-[var(--color-text-muted)]">
              Noch keine Einträge für diese Veranstaltung.
            </p>
            <ul v-else class="divide-y divide-[var(--color-border)]">
              <li v-for="entry in accessLog" :key="entry.id" class="py-2 text-sm">
                <p>
                  <span class="font-semibold">{{ actionLabel(entry.action) }}</span>
                  <span class="text-[var(--color-text-muted)]">
                    · {{ entry.target_name || entry.target_email || '—' }}
                  </span>
                </p>
                <p class="text-xs text-[var(--color-text-subtle)]">
                  {{ entry.created_at ? dayjs(entry.created_at).format('DD.MM.YYYY HH:mm') : '' }}
                  <template v-if="entry.actor_name"> · {{ entry.actor_name }}</template>
                  <template v-if="permissionLabels(entry).length"> · {{ permissionLabels(entry).join(', ') }}</template>
                </p>
              </li>
            </ul>
          </div>
        </template>
      </section>
    </template>
  </div>
</template>
