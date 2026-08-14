<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import {useAuth} from '@/composables/useAuth'

defineOptions({name: 'Profile'})

const {isAdmin, userRoles} = useAuth()

type ProfilePayload = {
  user: {
    id: number
    name: string | null
    email: string | null
    nick: string | null
    dolibarr_id: number | null
    lang: string | null
    last_login: string | null
    roles: string[]
    is_admin: boolean
  }
  selected_event: {
    id: number
    name: string
    date: string
    season: string | null
    regional_partner: string | null
  } | null
  regional_partners: Array<{
    id: number
    name: string
    region: string | null
    source: string
    granted_at: string | null
  }>
}

const loading = ref(true)
const error = ref('')
const data = ref<ProfilePayload | null>(null)

const roleLabels = computed(() => {
  const roles = data.value?.user.roles?.length ? data.value.user.roles : userRoles.value
  return roles.filter((r) => r.startsWith('flow') || r.includes('admin') || r.includes('tester') || r.includes('regional'))
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const {data: payload} = await axios.get<ProfilePayload>('/user/me')
    data.value = payload
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Profil konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

function sourceLabel(source: string) {
  return source === 'manual' ? 'Manuell' : 'Draht'
}

onMounted(load)
</script>

<template>
  <div class="space-y-6 max-w-3xl">
    <div>
      <h1 class="text-2xl font-bold tracking-tight">Profil</h1>
      <p class="mt-1 text-sm text-[var(--color-text-muted)]">
        Deine Kontodaten in FLOW.
      </p>
    </div>

    <div v-if="loading" class="glass-card liquid-surface-inner p-6 text-[var(--color-text-muted)]">
      Lade Profil…
    </div>

    <div v-else-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">
      {{ error }}
    </div>

    <template v-else-if="data">
      <section class="glass-card liquid-surface-inner p-5 space-y-4">
        <div class="flex items-start gap-4">
          <div
              class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--color-accent)_16%,transparent)] text-[var(--color-accent)]"
          >
            <i class="bi bi-person-fill text-2xl" aria-hidden="true"/>
          </div>
          <div class="min-w-0">
            <h2 class="text-xl font-bold truncate">{{ data.user.name || 'Ohne Namen' }}</h2>
            <p class="text-sm text-[var(--color-text-muted)] truncate">{{ data.user.email || '—' }}</p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <span
                  v-if="data.user.is_admin || isAdmin"
                  class="glass-chip !px-2 !py-0.5 !text-xs font-semibold"
              >Admin</span>
              <span
                  v-for="role in roleLabels"
                  :key="role"
                  class="glass-chip !px-2 !py-0.5 !text-xs"
              >{{ role }}</span>
            </div>
          </div>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
          <div>
            <dt class="text-[var(--color-text-subtle)]">Anzeigename</dt>
            <dd class="font-medium">{{ data.user.name || '—' }}</dd>
          </div>
          <div>
            <dt class="text-[var(--color-text-subtle)]">E-Mail</dt>
            <dd class="font-medium break-all">{{ data.user.email || '—' }}</dd>
          </div>
          <div>
            <dt class="text-[var(--color-text-subtle)]">Kurzname</dt>
            <dd class="font-medium">{{ data.user.nick || '—' }}</dd>
          </div>
          <div>
            <dt class="text-[var(--color-text-subtle)]">Dolibarr-ID</dt>
            <dd class="font-medium tabular-nums">{{ data.user.dolibarr_id ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-[var(--color-text-subtle)]">Sprache</dt>
            <dd class="font-medium">{{ data.user.lang || '—' }}</dd>
          </div>
          <div>
            <dt class="text-[var(--color-text-subtle)]">Letzter Login</dt>
            <dd class="font-medium">
              {{ data.user.last_login ? dayjs(data.user.last_login).format('DD.MM.YYYY HH:mm') : '—' }}
            </dd>
          </div>
        </dl>
      </section>

      <section class="glass-card liquid-surface-inner p-5 space-y-3">
        <h3 class="font-bold">Aktuelle Auswahl</h3>
        <p v-if="!data.selected_event" class="text-sm text-[var(--color-text-muted)]">
          Keine Veranstaltung ausgewählt.
        </p>
        <div v-else class="text-sm space-y-1">
          <p class="font-semibold text-base">{{ data.selected_event.name }}</p>
          <p class="text-[var(--color-text-muted)]">
            {{ data.selected_event.season || '—' }}
            <span v-if="data.selected_event.regional_partner"> · {{ data.selected_event.regional_partner }}</span>
            <span v-if="data.selected_event.date">
              · {{ dayjs(data.selected_event.date).format('DD.MM.YYYY') }}
            </span>
          </p>
        </div>
      </section>

      <section class="glass-card liquid-surface-inner p-5 space-y-3">
        <div class="flex items-baseline justify-between gap-3">
          <h3 class="font-bold">Deine Regionalpartner</h3>
          <span class="text-xs text-[var(--color-text-muted)]">{{ data.regional_partners.length }}</span>
        </div>
        <p v-if="!data.regional_partners.length" class="text-sm text-[var(--color-text-muted)]">
          Dir ist noch kein Regionalpartner zugeordnet.
        </p>
        <ul v-else class="divide-y divide-[var(--color-border)]">
          <li
              v-for="rp in data.regional_partners"
              :key="rp.id"
              class="flex items-center justify-between gap-3 py-2.5 text-sm"
          >
            <div class="min-w-0">
              <p class="font-medium truncate">{{ rp.name }}</p>
              <p v-if="rp.region" class="text-[var(--color-text-muted)] truncate">{{ rp.region }}</p>
            </div>
            <span class="glass-chip !px-2 !py-0.5 !text-xs shrink-0">{{ sourceLabel(rp.source) }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
