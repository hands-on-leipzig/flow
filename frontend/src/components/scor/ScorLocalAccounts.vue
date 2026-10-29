<script setup lang="ts">
import {onMounted, ref, watch} from 'vue'
import axios from 'axios'

const props = defineProps<{eventId: number | null}>()

type Account = {
  id: number
  username: string
  can_scor_referee: boolean
  can_scor_jury: boolean
  can_scor_admin: boolean
}

const accounts = ref<Account[]>([])
const username = ref('')
const referee = ref(true)
const jury = ref(false)
const admin = ref(false)
const busy = ref(false)
const notice = ref('')
const password = ref('')

async function load() {
  if (!props.eventId) {
    accounts.value = []
    return
  }
  try {
    const {data} = await axios.get(`/scor/events/${props.eventId}/local-accounts`)
    accounts.value = data.accounts || []
  } catch {
    accounts.value = []
  }
}

async function create() {
  if (!props.eventId) return
  busy.value = true
  notice.value = ''
  password.value = ''
  try {
    const {data} = await axios.post(`/scor/events/${props.eventId}/local-accounts`, {
      username: username.value,
      can_scor_referee: referee.value,
      can_scor_jury: jury.value,
      can_scor_admin: admin.value,
    })
    password.value = data.password
    notice.value = `${data.username} ist angelegt. Das Passwort gilt nur für SCOR und wird nur jetzt angezeigt.`
    username.value = ''
    await load()
  } catch (e: any) {
    notice.value = e?.response?.data?.error || 'Anlegen fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

async function reset(account: Account) {
  if (!props.eventId) return
  busy.value = true
  notice.value = ''
  password.value = ''
  try {
    const {data} = await axios.post(`/scor/events/${props.eventId}/local-accounts/${account.id}/password`)
    password.value = data.password
    notice.value = `Neues Passwort für ${data.username}. Es wird nur jetzt angezeigt.`
  } catch (e: any) {
    notice.value = e?.response?.data?.error || 'Passwort fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

function rights(account: Account) {
  return [
    account.can_scor_referee && 'Schiri',
    account.can_scor_jury && 'Jury',
    account.can_scor_admin && 'Veranstaltung-Admin',
  ].filter(Boolean).join(', ')
}

watch(() => props.eventId, () => {
  void load()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="space-y-3">
    <h3 class="text-sm font-bold">Lokale SCOR-Konten</h3>
    <p class="text-xs text-[var(--color-text-muted)]">
      Namen wie Schiri 1. Das Konto bleibt in SCOR und gilt nicht für HERO.
    </p>
    <div class="flex flex-wrap items-center gap-2">
      <input v-model="username" class="glass-input !py-2 !text-sm" placeholder="Schiri 1" @keydown.enter.prevent="create"/>
      <label class="inline-flex items-center gap-1.5 text-sm"><input v-model="referee" type="checkbox"/> Schiri</label>
      <label class="inline-flex items-center gap-1.5 text-sm"><input v-model="jury" type="checkbox"/> Jury</label>
      <label class="inline-flex items-center gap-1.5 text-sm"><input v-model="admin" type="checkbox"/> Veranstaltung-Admin</label>
      <button type="button" class="glass-btn-accent !px-3 !py-2 !text-sm" :disabled="busy" @click="create">Konto anlegen</button>
    </div>
    <p v-if="notice" class="text-sm">{{ notice }}</p>
    <p v-if="password" class="text-sm font-semibold">Passwort: {{ password }}</p>
    <ul class="divide-y divide-[var(--color-border)]">
      <li v-for="account in accounts" :key="account.id" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
        <span><strong>{{ account.username }}</strong> <span class="text-[var(--color-text-muted)]">{{ rights(account) }}</span></span>
        <button type="button" class="glass-btn-secondary !px-3 !py-1.5 !text-sm" :disabled="busy" @click="reset(account)">Neues Passwort</button>
      </li>
    </ul>
  </div>
</template>
