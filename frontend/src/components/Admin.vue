<script setup>
import {ref, watch, onMounted, computed} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import Quality from '@/components/molecules/Quality.vue'
import Statistics from '@/components/molecules/Statistics.vue'
import NowAndNext from '@/components/molecules/NowAndNext.vue'
import UserRegionalPartnerRelations from '@/components/molecules/UserRegionalPartnerRelations.vue'
import MainTablesAdmin from '@/components/molecules/MainTablesAdmin.vue'
import SystemNews from '@/components/molecules/SystemNews.vue'
import ExternalApiManagement from '@/components/molecules/ExternalApiManagement.vue'
import SharePointAdmin from '@/components/molecules/SharePointAdmin.vue'
import CalendarFeedsAdmin from '@/components/molecules/CalendarFeedsAdmin.vue'
import AdminWartung from '@/components/molecules/AdminWartung.vue'
import {
  ADMIN_DEFAULT_SECTION,
  ADMIN_SECTIONS,
  isAdminSection,
  isAdminSectionAvailable,
  resolveAdminSection,
} from '@/constants/adminNav'
import {useAdminEnvironment} from '@/composables/useAdminEnvironment'

defineOptions({name: 'Admin'})

const route = useRoute()
const router = useRouter()
const {isDevEnvironment, isLocal, ensureLoaded: ensureAdminEnvironment} = useAdminEnvironment()

const statisticsTableOnly = ref(false)

const activeTab = computed(() => {
  const section = resolveAdminSection(String(route.params.section || ''))
  return isAdminSection(section) ? section : ADMIN_DEFAULT_SECTION
})

const sectionAllowed = computed(() => {
  const item = ADMIN_SECTIONS.find((entry) => entry.key === activeTab.value)
  if (!item) return true
  return isAdminSectionAvailable(item, isDevEnvironment.value, isLocal)
})

function redirectIfSectionBlocked(section) {
  const resolved = resolveAdminSection(String(section || ''))
  if (resolved !== String(section || '')) {
    void router.replace(`/plan/admin/${resolved}`)
    return
  }
  if (!isAdminSection(resolved)) {
    void router.replace(`/plan/admin/${ADMIN_DEFAULT_SECTION}`)
    return
  }
  const item = ADMIN_SECTIONS.find((entry) => entry.key === resolved)
  if (item && !isAdminSectionAvailable(item, isDevEnvironment.value, isLocal)) {
    void router.replace(`/plan/admin/${ADMIN_DEFAULT_SECTION}`)
  }
}

watch(
  () => route.params.section,
  (section) => redirectIfSectionBlocked(section),
  {immediate: true},
)

watch(isDevEnvironment, () => {
  redirectIfSectionBlocked(route.params.section)
})

onMounted(() => {
  void ensureAdminEnvironment()
})
</script>

<template>
  <div class="h-full min-h-0 overflow-auto p-4 lg:p-6">
    <div v-if="activeTab === 'user-regional-partners'">
      <h2 class="text-xl font-bold mb-4">User ↔ Regionen</h2>
      <UserRegionalPartnerRelations/>
    </div>

    <div v-else-if="activeTab === 'quality' && sectionAllowed">
      <h2 class="text-xl font-bold mb-4">Massentest</h2>
      <Quality/>
    </div>

    <div v-else-if="activeTab === 'main-tables' && sectionAllowed">
      <MainTablesAdmin/>
    </div>

    <div v-else-if="activeTab === 'system-news'">
      <SystemNews/>
    </div>

    <div v-else-if="activeTab === 'nowandnext' && sectionAllowed">
      <h2 class="text-xl font-bold mb-4">Was passiert gerade? Und was als nächstes?</h2>
      <NowAndNext/>
    </div>

    <div v-else-if="activeTab === 'calendar'">
      <CalendarFeedsAdmin/>
    </div>

    <div v-else-if="activeTab === 'statistics'">
      <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
        <h2 class="text-xl font-bold">Statistiken</h2>
        <label class="glass-chip !px-3 !py-1.5 !text-sm inline-flex items-center gap-2 cursor-pointer select-none">
          <input
              v-model="statisticsTableOnly"
              type="checkbox"
              class="rounded border-[var(--color-border)]"
          />
          <span class="text-[var(--color-text-muted)]">Nur Tabelle</span>
        </label>
      </div>
      <Statistics :table-only="statisticsTableOnly"/>
    </div>

    <div v-else-if="activeTab === 'external-api'">
      <ExternalApiManagement/>
    </div>

    <div v-else-if="activeTab === 'sharepoint'">
      <SharePointAdmin/>
    </div>

    <div v-else-if="activeTab === 'wartung'">
      <AdminWartung/>
    </div>
  </div>
</template>
