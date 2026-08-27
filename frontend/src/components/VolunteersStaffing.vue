<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'

type Person = {
  id: number
  first_name: string
  last_name: string
  nickname: string | null
  email: string
}

type Group = {
  id: number
  group_index: number
  surplus: boolean
  filled: number
  min: number
  best: number
  max: number
  under_min: boolean
  people: Person[]
}

type Role = {
  id: number
  m_role: number | null
  is_local: boolean
  label: string
  first_program: number | null
  min: number
  best: number
  max: number
  ui_description: string | null
  groups: Group[]
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roles = ref<Role[]>([])
const planId = ref<number | null>(null)
const staffingOk = ref(true)
const loading = ref(false)
const syncing = ref(false)
const error = ref('')
const toast = ref('')

const gapSummary = computed(() => {
  let underMin = 0
  let surplusPeople = 0
  for (const role of roles.value) {
    for (const g of role.groups) {
      if (g.surplus && g.filled > 0) surplusPeople++
      if (g.under_min) underMin++
    }
  }
  return {underMin, surplusPeople}
})

function programLabel(fp: number | null) {
  if (fp === 2) return 'Explore'
  if (fp === 3) return 'Challenge'
  if (fp === 8) return 'Future 8+'
  return 'Gesamt'
}

function displayName(p: Person) {
  if (p.nickname?.trim()) return `${p.first_name} „${p.nickname}“ ${p.last_name}`
  return `${p.first_name} ${p.last_name}`
}

function groupTitle(role: Role, group: Group) {
  if (role.groups.length <= 1 && !group.surplus) return role.label
  return `${role.label} ${group.group_index}`
}

async function load() {
  if (!eventId.value) return
  loading.value = true
  error.value = ''
  try {
    const {data} = await axios.get(`/events/${eventId.value}/staffing`)
    roles.value = data.roles ?? []
    planId.value = data.plan_id ?? null
    staffingOk.value = data.staffing_ok !== false
    await eventStore.refreshReadiness(eventId.value)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Laden fehlgeschlagen'
  } finally {
    loading.value = false
  }
}

async function syncFromPlan() {
  if (!eventId.value) return
  syncing.value = true
  error.value = ''
  try {
    const {data} = await axios.post(`/events/${eventId.value}/staffing/sync`)
    const s = data.stats || {}
    showToast(
      `Abgleich: ${s.roles ?? 0} Rollen, +${s.groups_created ?? 0} Gruppen`
        + (s.skipped?.length ? ` (${s.skipped.length} übersprungen)` : ''),
    )
    await load()
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Abgleich fehlgeschlagen'
  } finally {
    syncing.value = false
  }
}

function showToast(msg: string) {
  toast.value = msg
  setTimeout(() => {
    if (toast.value === msg) toast.value = ''
  }, 2800)
}

watch(eventId, () => load(), {immediate: true})
onMounted(() => load())
</script>

<template>
  <div class="vol-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Besetzung</h1>
        <p class="vol-page__sub">
          Struktur aus dem Plan (nach Generierung). Zuweisen folgt als Nächstes.
        </p>
      </div>
      <button
          type="button"
          class="glass-btn-accent"
          :disabled="syncing || !planId"
          @click="syncFromPlan"
      >
        {{ syncing ? 'Abgleichen…' : 'Mit Plan abgleichen' }}
      </button>
    </header>

    <div v-if="error" class="glass-alert-danger vol-page__alert">{{ error }}</div>
    <div v-if="toast" class="glass-alert-success vol-page__alert">{{ toast }}</div>

    <div v-if="!planId" class="glass-card vol-stub">
      <p>Kein Plan vorhanden — zuerst Ablauf erzeugen.</p>
    </div>

    <div v-else-if="loading" class="vol-muted">Laden…</div>

    <template v-else>
      <div class="vol-summary glass-card">
        <span v-if="staffingOk" class="vol-ok">Besetzung ok (min erreicht, kein Surplus mit Personen)</span>
        <span v-else class="vol-warn">
          Handlungsbedarf:
          <template v-if="gapSummary.underMin">{{ gapSummary.underMin }} unter Min</template>
          <template v-if="gapSummary.underMin && gapSummary.surplusPeople"> · </template>
          <template v-if="gapSummary.surplusPeople">{{ gapSummary.surplusPeople }} Surplus mit Personen</template>
        </span>
        <p class="vol-muted">
          Ohne staffable Rollen + Regeln in Main Tables bleibt die Liste leer.
          Nach Plangenerierung mit <code>STAFFING_SYNC_AFTER_GENERATE=true</code> automatisch,
          oder hier manuell abgleichen.
        </p>
      </div>

      <p v-if="!roles.length" class="vol-muted">
        Noch keine Besetzungsrollen. Staffable-Rollen in Main Tables markieren, Regeln anlegen, dann abgleichen.
      </p>

      <section v-for="role in roles" :key="role.id" class="glass-card vol-role">
        <header class="vol-role__head">
          <div>
            <h2 class="vol-role__title">{{ role.label }}</h2>
            <p class="vol-muted">
              {{ programLabel(role.first_program) }}
              · {{ role.is_local ? 'Lokal' : 'Katalog' }}
              · min {{ role.min }} / best {{ role.best }} / max {{ role.max }}
            </p>
            <p v-if="role.ui_description" class="vol-desc">{{ role.ui_description }}</p>
          </div>
        </header>

        <div class="vol-groups">
          <div
              v-for="group in role.groups"
              :key="group.id"
              class="vol-group liquid-surface-inner"
              :class="{
                'vol-group--surplus': group.surplus,
                'vol-group--under': group.under_min,
              }"
          >
            <div class="vol-group__head">
              <strong>{{ groupTitle(role, group) }}</strong>
              <span class="vol-muted">{{ group.filled }} / best {{ group.best }} (max {{ group.max }})</span>
              <span v-if="group.surplus" class="glass-chip">Surplus — leeren</span>
              <span v-else-if="group.under_min" class="glass-chip">unter Min</span>
            </div>
            <ul v-if="group.people.length" class="vol-people">
              <li v-for="p in group.people" :key="p.id">{{ displayName(p) }}</li>
            </ul>
            <p v-else class="vol-muted">Noch niemand zugewiesen</p>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.vol-page { display: flex; flex-direction: column; gap: 1rem; padding: 0.5rem 0 2rem; }
.vol-page__header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; }
.vol-page__title { font-size: 1.5rem; font-weight: 650; margin: 0; }
.vol-page__sub { margin: 0.25rem 0 0; opacity: 0.75; }
.vol-page__alert { padding: 0.75rem 1rem; border-radius: 0.75rem; }
.vol-stub, .vol-summary, .vol-role { padding: 1rem; }
.vol-muted { opacity: 0.7; font-size: 0.9rem; margin: 0.25rem 0 0; }
.vol-ok { color: #15803d; font-weight: 600; }
.vol-warn { color: #b91c1c; font-weight: 600; }
.vol-desc { margin: 0.5rem 0 0; font-size: 0.92rem; }
.vol-role__title { margin: 0; font-size: 1.15rem; }
.vol-groups { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.75rem; }
.vol-group { padding: 0.75rem; border-radius: 0.75rem; }
.vol-group--surplus { outline: 1px solid rgba(185, 28, 28, 0.45); }
.vol-group--under { outline: 1px solid rgba(202, 138, 4, 0.5); }
.vol-group__head { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.vol-people { margin: 0.4rem 0 0; padding-left: 1.1rem; }
</style>
