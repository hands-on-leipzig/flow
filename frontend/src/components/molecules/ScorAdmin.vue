<script setup lang="ts">
import {onMounted, ref} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'

defineOptions({name: 'ScorAdmin'})

type Permissions = {can_flow: boolean; can_scor: boolean}

type ScorEvent = {
  id: number
  name: string | null
  date: string | null
  regional_partner_name: string | null
}

type AccessUser = Permissions & {
  user_id: number
  name: string | null
  email: string | null
  source: string
  granted_by_name: string | null
  is_self: boolean
}

type Invitation = Permissions & {
  id: number
  email: string
  expires_at: string | null
  invited_by_name: string | null
}

type LogEntry = {
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

const query = ref('')
const events = ref<ScorEvent[]>([])
const eventsLoading = ref(false)
const selectedId = ref<number | null>(null)

const users = ref<AccessUser[]>([])
const invitations = ref<Invitation[]>([])
const detailLoading = ref(false)
const detailError = ref('')

const inviteEmail = ref('')
const inviteFlow = ref(true)
const inviteScor = ref(false)
const inviteBusy = ref(false)
const notice = ref('')

const log = ref<LogEntry[]>([])

const actionLabels: Record<string, string> = {
  invited: 'Einladung',
  invitation_updated: 'Einladung geändert',
  invitation_withdrawn: 'Einladung zurückgezogen',
  claimed: 'Angenommen',
  expired: 'Abgelaufen',
  permissions_updated: 'Rechte geändert',
  revoked: 'Zugriff entfernt',
}

let searchTimer: ReturnType<typeof setTimeout> | undefined

function actionLabel(action: string) {
  return actionLabels[action] || action
}

function permissionText(entry: {can_flow?: boolean | null; can_scor?: boolean | null}) {
  return [entry.can_flow && 'FLOW', entry.can_scor && 'SCOR'].filter(Boolean).join(', ')
}

async function loadEvents() {
  eventsLoading.value = true
  try {
    const {data} = await axios.get('/admin/scor/events', {params: {q: query.value.trim()}})
    events.value = data.events || []
  } catch {
    events.value = []
  } finally {
    eventsLoading.value = false
  }
}

function onSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void loadEvents(), 220)
}

async function loadDetail() {
  if (!selectedId.value) return
  detailLoading.value = true
  detailError.value = ''
  try {
    const {data} = await axios.get(`/user/access/events/${selectedId.value}/users`)
    users.value = data.users || []
    invitations.value = data.invitations || []
  } catch (e: any) {
    users.value = []
    invitations.value = []
    detailError.value = e?.response?.data?.error || 'Zugänge konnten nicht geladen werden.'
  } finally {
    detailLoading.value = false
  }
}

async function loadLog() {
  try {
    const {data} = await axios.get('/admin/scor/log')
    log.value = data.log || []
  } catch {
    log.value = []
  }
}

async function selectEvent(id: number) {
  selectedId.value = id
  notice.value = ''
  await loadDetail()
}

async function invite() {
  if (!selectedId.value || !inviteEmail.value.includes('@') || (!inviteFlow.value && !inviteScor.value)) {
    notice.value = 'Bitte E-Mail-Adresse und mindestens eine Berechtigung wählen.'
    return
  }
  inviteBusy.value = true
  notice.value = ''
  try {
    const {data} = await axios.post('/user/access/grants', {
      event_id: selectedId.value,
      email: inviteEmail.value.trim(),
      can_flow: inviteFlow.value,
      can_scor: inviteScor.value,
    })
    users.value = data.users || []
    invitations.value = data.invitations || []
    inviteEmail.value = ''
    notice.value = data.mail_sent === false
        ? 'Einladung gespeichert. Die E-Mail konnte nicht gesendet werden.'
        : 'Einladung gespeichert.'
    await loadLog()
  } catch (e: any) {
    notice.value = e?.response?.data?.error || 'Einladung fehlgeschlagen'
  } finally {
    inviteBusy.value = false
  }
}

async function setPermission(target: {user_id: number} | {invitation_id: number}, current: Permissions, key: keyof Permissions, value: boolean) {
  const next = {...current, [key]: value}
  if (!next.can_flow && !next.can_scor) {
    notice.value = 'Mindestens eine Berechtigung muss bleiben.'
    await loadDetail()
    return
  }
  try {
    const {data} = await axios.patch('/user/access/grants', {
      ...target,
      event_id: selectedId.value,
      ...next,
    })
    users.value = data.users || []
    invitations.value = data.invitations || []
    notice.value = ''
    await loadLog()
  } catch (e: any) {
    notice.value = e?.response?.data?.error || 'Speichern fehlgeschlagen'
    await loadDetail()
  }
}

async function revoke(target: {user_id: number} | {invitation_id: number}) {
  try {
    const {data} = await axios.delete('/user/access/grants', {
      data: {...target, event_id: selectedId.value},
    })
    users.value = data.users || []
    invitations.value = data.invitations || []
    notice.value = 'Zugriff entfernt.'
    await loadLog()
  } catch (e: any) {
    notice.value = e?.response?.data?.error || 'Entfernen fehlgeschlagen'
  }
}

onMounted(async () => {
  await Promise.all([loadEvents(), loadLog()])
})
</script>

<template>
  <div class="space-y-6 max-w-5xl">
    <div>
      <h2 class="text-xl font-bold">SCOR</h2>
      <p class="mt-1 text-sm text-gray-600">
        Zugänge pro Veranstaltung. FLOW ist die Planung, SCOR die Auswertung.
        Kontaktpersonen aus Draht haben beides für alle Veranstaltungen ihres Regionalpartners.
      </p>
    </div>

    <section class="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
      <label class="block text-sm">
        <span class="block text-xs font-semibold text-gray-500 mb-1">Veranstaltung</span>
        <input
            v-model="query"
            type="search"
            class="w-full max-w-xl rounded-lg border border-gray-300 px-3 py-2"
            placeholder="Name oder Regionalpartner…"
            @input="onSearch"
        />
      </label>
      <p v-if="eventsLoading" class="text-sm text-gray-500">Lade…</p>
      <ul v-else class="max-h-52 overflow-auto divide-y divide-gray-100">
        <li v-for="event in events" :key="event.id">
          <button
              type="button"
              class="w-full px-2 py-2 text-left text-sm hover:bg-gray-50"
              :class="selectedId === event.id ? 'bg-orange-50' : ''"
              @click="selectEvent(event.id)"
          >
            <span class="font-semibold">{{ event.name || 'Ohne Namen' }}</span>
            <span class="text-gray-500">
              <template v-if="event.date"> · {{ dayjs(event.date).format('DD.MM.YYYY') }}</template>
              <template v-if="event.regional_partner_name"> · {{ event.regional_partner_name }}</template>
            </span>
          </button>
        </li>
      </ul>
    </section>

    <section v-if="selectedId" class="rounded-lg border border-gray-200 bg-white p-4 space-y-4">
      <p v-if="detailError" class="text-sm text-red-700">{{ detailError }}</p>
      <p v-else-if="detailLoading" class="text-sm text-gray-500">Lade Zugänge…</p>
      <template v-else>
        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
          <input
              v-model="inviteEmail"
              type="email"
              class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm"
              placeholder="name@beispiel.de"
              @keydown.enter.prevent="invite"
          />
          <label class="inline-flex items-center gap-1.5 text-sm">
            <input v-model="inviteFlow" type="checkbox"/> FLOW
          </label>
          <label class="inline-flex items-center gap-1.5 text-sm">
            <input v-model="inviteScor" type="checkbox"/> SCOR
          </label>
          <button
              type="button"
              class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
              :disabled="inviteBusy"
              @click="invite"
          >
            Einladen
          </button>
        </div>
        <p v-if="notice" class="text-sm text-gray-700">{{ notice }}</p>
        <p class="text-xs text-gray-500">Die Einladung gilt 30 Tage und nur für diese Veranstaltung. Eine E-Mail geht an die Adresse.</p>

        <ul class="divide-y divide-gray-100">
          <li v-for="row in users" :key="`u-${row.user_id}`" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
            <div>
              <p class="font-semibold">{{ row.name || 'Ohne Namen' }} <span class="font-normal text-gray-500">{{ row.email }}</span></p>
              <p class="text-xs text-gray-500">{{ row.source === 'draht' ? 'Draht' : 'Einladung' }}</p>
            </div>
            <div v-if="row.source === 'manual' && !row.is_self" class="flex items-center gap-3">
              <label class="inline-flex items-center gap-1.5">
                <input type="checkbox" :checked="row.can_flow" @change="setPermission({user_id: row.user_id}, row, 'can_flow', ($event.target as HTMLInputElement).checked)"/>
                FLOW
              </label>
              <label class="inline-flex items-center gap-1.5">
                <input type="checkbox" :checked="row.can_scor" @change="setPermission({user_id: row.user_id}, row, 'can_scor', ($event.target as HTMLInputElement).checked)"/>
                SCOR
              </label>
              <button type="button" class="text-red-700" @click="revoke({user_id: row.user_id})">Entfernen</button>
            </div>
            <span v-else class="text-xs text-gray-500">{{ permissionText(row) }}</span>
          </li>
          <li v-for="inv in invitations" :key="`i-${inv.id}`" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
            <div>
              <p class="font-semibold">{{ inv.email }}</p>
              <p class="text-xs text-gray-500">
                Wartet auf Anmeldung
                <template v-if="inv.expires_at"> bis {{ dayjs(inv.expires_at).format('DD.MM.YYYY') }}</template>
              </p>
            </div>
            <div class="flex items-center gap-3">
              <label class="inline-flex items-center gap-1.5">
                <input type="checkbox" :checked="inv.can_flow" @change="setPermission({invitation_id: inv.id}, inv, 'can_flow', ($event.target as HTMLInputElement).checked)"/>
                FLOW
              </label>
              <label class="inline-flex items-center gap-1.5">
                <input type="checkbox" :checked="inv.can_scor" @change="setPermission({invitation_id: inv.id}, inv, 'can_scor', ($event.target as HTMLInputElement).checked)"/>
                SCOR
              </label>
              <button type="button" class="text-red-700" @click="revoke({invitation_id: inv.id})">Zurückziehen</button>
            </div>
          </li>
        </ul>
      </template>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-4">
      <h3 class="font-bold mb-2">Protokoll</h3>
      <p v-if="!log.length" class="text-sm text-gray-500">Noch keine Einträge.</p>
      <ul v-else class="divide-y divide-gray-100">
        <li v-for="entry in log" :key="entry.id" class="py-2 text-sm">
          <p>
            <span class="font-semibold">{{ actionLabel(entry.action) }}</span>
            · {{ entry.event_name || 'Veranstaltung' }}
            · {{ entry.target_name || entry.target_email || '—' }}
          </p>
          <p class="text-xs text-gray-500">
            {{ entry.created_at ? dayjs(entry.created_at).format('DD.MM.YYYY HH:mm') : '' }}
            <template v-if="entry.actor_name"> · {{ entry.actor_name }}</template>
            <template v-if="permissionText(entry)"> · {{ permissionText(entry) }}</template>
          </p>
        </li>
      </ul>
    </section>
  </div>
</template>
