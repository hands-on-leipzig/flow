<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from 'vue'
import type {AxiosInstance} from 'axios'
import PersonListHit from '@/components/molecules/PersonListHit.vue'
import {programLogoSrc} from '@/utils/images'

defineOptions({name: 'CockpitOverviewPanel'})

type Leaf = {
  id: number
  kind: 'team' | 'volunteer'
  label: string
  subtitle: string | null
  logo_stem: string | null
  status: string | null
  checked_in_at: string | null
}

type HelperBucket = {
  label: string
  people: Leaf[]
}

type Scope = {
  kind: string
  program_id: number | null
  label: string
  logo_stem: string | null
  teams: Leaf[]
  helper_buckets: HelperBucket[]
}

type FilterMode = 'all' | 'present' | 'absent'

const REFRESH_MS = 20_000

const props = defineProps<{
  slug: string
  http: AxiosInstance
}>()

const filter = ref<FilterMode>('all')
const scopes = ref<Scope[]>([])
const loading = ref(false)
const error = ref('')

const FILTERS: {id: FilterMode, label: string}[] = [
  {id: 'all', label: 'Alle'},
  {id: 'present', label: 'Eingecheckt'},
  {id: 'absent', label: 'Fehlt'},
]

function matchesFilter(status: string | null): boolean {
  if (filter.value === 'all') return true
  if (filter.value === 'present') return status === 'checked_in'
  return status !== 'checked_in'
}

function statusTitle(status: string | null): string {
  if (status === 'no_show') return 'No-Show'
  if (status === 'checked_in') return 'Da'
  return 'Offen'
}

function scopeIcon(kind: string): string {
  if (kind === 'cross') return 'bi-intersect'
  if (kind === 'local') return 'bi-star'
  return ''
}

function filterLeaves(leaves: Leaf[]): Leaf[] {
  return leaves.filter((leaf) => matchesFilter(leaf.status))
}

const visibleScopes = computed(() => {
  const out: Array<{
    kind: string
    label: string
    logo_stem: string | null
    teams: Leaf[]
    helper_buckets: Array<{label: string, people: Leaf[]}>
  }> = []

  for (const scope of scopes.value) {
    const teams = filterLeaves(scope.teams)
    const helper_buckets = scope.helper_buckets
      .map((bucket) => ({
        label: bucket.label,
        people: filterLeaves(bucket.people),
      }))
      .filter((bucket) => bucket.people.length > 0)

    if (teams.length === 0 && helper_buckets.length === 0) continue

    out.push({
      kind: scope.kind,
      label: scope.label,
      logo_stem: scope.logo_stem,
      teams,
      helper_buckets,
    })
  }

  return out
})

const isEmpty = computed(() => !loading.value && !error.value && visibleScopes.value.length === 0)

async function loadState(silent = false) {
  if (!silent) {
    loading.value = true
    error.value = ''
  }
  try {
    const {data} = await props.http.get(`/cockpit/${props.slug}/overview`)
    scopes.value = Array.isArray(data?.scopes) ? data.scopes : []
  } catch (e: any) {
    if (!silent) {
      error.value = e?.response?.data?.error || 'Überblick konnte nicht geladen werden.'
      scopes.value = []
    }
  } finally {
    loading.value = false
  }
}

let refreshTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  void loadState()
  refreshTimer = setInterval(() => {
    void loadState(true)
  }, REFRESH_MS)
})

onUnmounted(() => {
  if (refreshTimer) clearInterval(refreshTimer)
})
</script>

<template>
  <div class="cp-overview">
    <div class="cp-overview__filters" role="group" aria-label="Filter">
      <button
          v-for="item in FILTERS"
          :key="item.id"
          type="button"
          class="glass-choice cp-overview__filter"
          :class="{'glass-choice--active': filter === item.id}"
          @click="filter = item.id"
      >
        {{ item.label }}
      </button>
    </div>

    <p v-if="loading" class="cp-overview__hint">Lade…</p>
    <p v-else-if="error" class="glass-alert-error !mb-0">{{ error }}</p>
    <p v-else-if="isEmpty" class="cp-overview__hint">Keine Einträge für diesen Filter.</p>

    <section
        v-for="(scope, si) in visibleScopes"
        :key="`${scope.kind}-${scope.label}-${si}`"
        class="cp-overview__scope"
    >
      <header class="cp-overview__scope-head">
        <img
            v-if="scope.logo_stem"
            class="cp-overview__scope-logo"
            :src="programLogoSrc({logo_stem: scope.logo_stem})"
            alt=""
            aria-hidden="true"
        />
        <i
            v-else-if="scopeIcon(scope.kind)"
            class="bi cp-overview__scope-icon"
            :class="scopeIcon(scope.kind)"
            aria-hidden="true"
        />
        <h2 class="cp-overview__scope-title">{{ scope.label }}</h2>
      </header>

      <div v-if="scope.teams.length" class="cp-overview__block">
        <h3 class="cp-overview__l2">Teams ({{ scope.teams.length }})</h3>
        <ul class="ci-list">
          <li v-for="leaf in scope.teams" :key="`team-${leaf.id}`">
            <PersonListHit
                :label="leaf.label"
                :subtitle="leaf.subtitle"
                :logo-stem="leaf.logo_stem"
                :status="leaf.status"
                :status-title="statusTitle(leaf.status)"
            />
          </li>
        </ul>
      </div>

      <div v-if="scope.helper_buckets.length" class="cp-overview__block">
        <h3 class="cp-overview__l2">
          Helfer:innen ({{ scope.helper_buckets.reduce((n, b) => n + b.people.length, 0) }})
        </h3>
        <div
            v-for="bucket in scope.helper_buckets"
            :key="bucket.label"
            class="cp-overview__bucket"
        >
          <h4 class="cp-overview__l3">{{ bucket.label }} ({{ bucket.people.length }})</h4>
          <ul class="ci-list">
            <li v-for="leaf in bucket.people" :key="`vol-${leaf.id}`">
              <PersonListHit
                  :label="leaf.label"
                  :subtitle="leaf.subtitle"
                  :logo-stem="leaf.logo_stem"
                  :scope-icon="!leaf.logo_stem ? scopeIcon(scope.kind) : ''"
                  :status="leaf.status"
                  :status-title="statusTitle(leaf.status)"
              />
            </li>
          </ul>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.cp-overview {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.cp-overview__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.cp-overview__filter {
  flex: 1 1 auto;
  min-width: 5.5rem;
  justify-content: center;
}

.cp-overview__hint {
  margin: 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
}

.cp-overview__scope {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.cp-overview__scope-head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.cp-overview__scope-logo {
  width: 1.5rem;
  height: 1.5rem;
  object-fit: contain;
  flex-shrink: 0;
}

.cp-overview__scope-icon {
  font-size: 1.25rem;
  line-height: 1;
  color: var(--color-text-muted);
  flex-shrink: 0;
}

.cp-overview__scope-title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 750;
  color: var(--color-text);
}

.cp-overview__block {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.cp-overview__l2 {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.cp-overview__bucket {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.cp-overview__l3 {
  margin: 0.15rem 0 0;
  font-size: 0.9rem;
  font-weight: 650;
  color: var(--color-text);
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
