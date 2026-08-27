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

type RosterEntry = {
  person: Person
  has_assignment: boolean
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roles = ref<Role[]>([])
const roster = ref<RosterEntry[]>([])
const planId = ref<number | null>(null)
const staffingOk = ref(true)
const loading = ref(false)
const syncing = ref(false)
const error = ref('')
const toast = ref('')

const pickByGroup = ref<Record<number, number | ''>>({})

const localDraft = ref({
  label: '',
  min: 1,
  best: 1,
  max: 2,
})

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

/** People on roster not blocked for this target role. */
function candidatesFor(role: Role, group: Group): Person[] {
  if (group.surplus || group.filled >= group.max) return []
  const assignedIds = new Set(
    roles.value.flatMap((r) => r.groups.flatMap((g) => g.people.map((p) => p.id))),
  )
  const catalogAssigned = new Set(
    roles.value
      .filter((r) => !r.is_local)
      .flatMap((r) => r.groups.flatMap((g) => g.people.map((p) => p.id))),
  )

  return roster.value
    .map((e) => e.person)
    .filter((p) => {
      if (group.people.some((x) => x.id === p.id)) return false
      if (role.is_local) {
        return !catalogAssigned.has(p.id)
      }
      // catalog: must have no assignments at all
      return !assignedIds.has(p.id)
    })
}

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
    const [staffingRes, rosterRes] = await Promise.all([
      axios.get(`/events/${eventId.value}/staffing`),
      axios.get(`/events/${eventId.value}/volunteer-roster`),
    ])
    roles.value = staffingRes.data.roles ?? []
    planId.value = staffingRes.data.plan_id ?? null
    staffingOk.value = staffingRes.data.staffing_ok !== false
    roster.value = rosterRes.data.roster ?? []
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

async function assign(group: Group) {
  if (!eventId.value) return
  const personId = pickByGroup.value[group.id]
  if (!personId) return
  error.value = ''
  try {
    await axios.post(`/events/${eventId.value}/staffing/groups/${group.id}/assignments`, {
      volunteer_person: personId,
    })
    pickByGroup.value[group.id] = ''
    await load()
    showToast('Zugewiesen')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Zuweisen fehlgeschlagen'
  }
}

async function unassign(group: Group, person: Person) {
  if (!eventId.value) return
  error.value = ''
  try {
    await axios.delete(
      `/events/${eventId.value}/staffing/groups/${group.id}/assignments/${person.id}`,
    )
    await load()
    showToast('Entfernt')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Entfernen fehlgeschlagen'
  }
}

async function createLocalRole() {
  if (!eventId.value) return
  if (!localDraft.value.label.trim()) {
    error.value = 'Name der lokalen Rolle fehlt.'
    return
  }
  error.value = ''
  try {
    await axios.post(`/events/${eventId.value}/staffing/local-roles`, {
      label: localDraft.value.label.trim(),
      min: Number(localDraft.value.min),
      best: Number(localDraft.value.best),
      max: Number(localDraft.value.max),
    })
    localDraft.value = {label: '', min: 1, best: 1, max: 2}
    await load()
    showToast('Lokale Rolle angelegt')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Anlegen fehlgeschlagen'
  }
}

async function removeLocalRole(role: Role) {
  if (!eventId.value || !role.is_local) return
  if (!confirm(`Lokale Rolle „${role.label}“ löschen?`)) return
  error.value = ''
  try {
    await axios.delete(`/events/${eventId.value}/staffing/local-roles/${role.id}`)
    await load()
    showToast('Lokale Rolle gelöscht')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Löschen fehlgeschlagen'
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
          Personen aus der Anmeldung den Gruppen zuweisen. Surplus nur leeren.
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
        <span v-if="staffingOk" class="vol-ok">Besetzung ok</span>
        <span v-else class="vol-warn">
          Handlungsbedarf:
          <template v-if="gapSummary.underMin">{{ gapSummary.underMin }} unter Min</template>
          <template v-if="gapSummary.underMin && gapSummary.surplusPeople"> · </template>
          <template v-if="gapSummary.surplusPeople">{{ gapSummary.surplusPeople }} Surplus mit Personen</template>
        </span>
        <p v-if="!roster.length" class="vol-muted">
          Noch niemand auf der Anmeldung — unter Anmeldung Personen hinzufügen, dann hier zuweisen.
        </p>
      </div>

      <section class="glass-card vol-local">
        <h2 class="vol-role__title">Eigene Rolle</h2>
        <div class="vol-local__row">
          <input v-model="localDraft.label" class="glass-input" placeholder="z. B. Check-in" />
          <input v-model.number="localDraft.min" class="glass-input" type="number" min="1" title="min" />
          <input v-model.number="localDraft.best" class="glass-input" type="number" min="1" title="best" />
          <input v-model.number="localDraft.max" class="glass-input" type="number" min="1" title="max" />
          <button type="button" class="glass-btn-accent" @click="createLocalRole">Anlegen</button>
        </div>
        <p class="vol-muted">min / best / max — eine Gruppe (Bag).</p>
      </section>

      <p v-if="!roles.length" class="vol-muted">
        Noch keine Rollen. Staffable + Regeln in Main Tables, dann abgleichen — oder lokale Rolle anlegen.
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
          <button
              v-if="role.is_local"
              type="button"
              class="glass-btn-secondary"
              @click="removeLocalRole(role)"
          >
            Rolle löschen
          </button>
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
              <span v-if="group.surplus" class="glass-chip">Surplus — nur entfernen</span>
              <span v-else-if="group.under_min" class="glass-chip">unter Min</span>
            </div>

            <ul v-if="group.people.length" class="vol-people">
              <li v-for="p in group.people" :key="p.id" class="vol-people__row">
                <span>{{ displayName(p) }}</span>
                <button type="button" class="glass-btn-secondary" @click="unassign(group, p)">Entfernen</button>
              </li>
            </ul>
            <p v-else class="vol-muted">Noch niemand zugewiesen</p>

            <div v-if="!group.surplus && group.filled < group.max" class="vol-assign">
              <select v-model="pickByGroup[group.id]" class="glass-input">
                <option value="">— aus Anmeldung —</option>
                <option
                    v-for="p in candidatesFor(role, group)"
                    :key="p.id"
                    :value="p.id"
                >
                  {{ displayName(p) }}
                </option>
              </select>
              <button
                  type="button"
                  class="glass-btn-accent"
                  :disabled="!pickByGroup[group.id]"
                  @click="assign(group)"
              >
                Zuweisen
              </button>
            </div>
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
.vol-stub, .vol-summary, .vol-role, .vol-local { padding: 1rem; }
.vol-muted { opacity: 0.7; font-size: 0.9rem; margin: 0.25rem 0 0; }
.vol-ok { color: #15803d; font-weight: 600; }
.vol-warn { color: #b91c1c; font-weight: 600; }
.vol-desc { margin: 0.5rem 0 0; font-size: 0.92rem; }
.vol-role__head { display: flex; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
.vol-role__title { margin: 0; font-size: 1.15rem; }
.vol-local__row { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
.vol-local__row .glass-input { flex: 1; min-width: 4rem; }
.vol-groups { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.75rem; }
.vol-group { padding: 0.75rem; border-radius: 0.75rem; }
.vol-group--surplus { outline: 1px solid rgba(185, 28, 28, 0.45); }
.vol-group--under { outline: 1px solid rgba(202, 138, 4, 0.5); }
.vol-group__head { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.vol-people { margin: 0.4rem 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 0.35rem; }
.vol-people__row { display: flex; justify-content: space-between; gap: 0.5rem; align-items: center; }
.vol-assign { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.6rem; }
.vol-assign .glass-input { flex: 1; min-width: 10rem; }
</style>
