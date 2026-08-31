<script setup lang="ts">
import {computed} from 'vue'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import {useAdminInlineVisibility} from '@/composables/useAdminInlineVisibility'

defineOptions({name: 'AdminDbIdsBox'})

const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const {showAdminInline} = useAdminInlineVisibility()

const event = computed(() => eventStore.selectedEvent)
const regionalPartnerId = computed(() => event.value?.regional_partner ?? null)
const eventId = computed(() => event.value?.id ?? null)
const planId = computed(() => planCache.plan?.id ?? null)

function formatId(value: number | null | undefined): string {
  return value != null ? String(value) : '—'
}
</script>

<template>
  <div
      v-if="showAdminInline && eventId"
      class="admin-db-ids glass-chip liquid-surface-inner shrink-0"
      title="Admin"
  >
    <i class="bi bi-shield-lock admin-db-ids__mark" aria-hidden="true"/>
    <span class="admin-db-ids__item tabular-nums">
      <span class="admin-db-ids__label">RP:</span> {{ formatId(regionalPartnerId) }}
    </span>
    <span class="admin-db-ids__item tabular-nums">
      <span class="admin-db-ids__label">Event:</span> {{ formatId(eventId) }}
    </span>
    <span class="admin-db-ids__item tabular-nums">
      <span class="admin-db-ids__label">Plan:</span> {{ formatId(planId) }}
    </span>
  </div>
</template>

<style scoped>
.admin-db-ids {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem 0.75rem;
  padding: 0.35rem 0.65rem !important;
  font-size: 0.8125rem;
  line-height: 1.25;
}

.admin-db-ids__mark {
  color: var(--color-text-muted);
  opacity: 0.85;
  font-size: 0.875rem;
}

.admin-db-ids__label {
  color: var(--color-text-muted);
  font-weight: 500;
}
</style>
