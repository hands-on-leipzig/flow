<script setup lang="ts">
import {computed, ref} from 'vue'
import {RouterLink, useRoute} from 'vue-router'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import {useEventStore} from '@/stores/event'
import {useNoticeStore, type NoticeMessage} from '@/stores/notice'
import {HELP_SCREEN_KEY_BY_PATH, TEAMS_PROGRAM_HELP_KEY} from '@/utils/helpRoutes'
import {programCompact} from '@/utils/eventPrograms'

defineOptions({name: 'NoticePane'})

const route = useRoute()
const eventStore = useEventStore()
const noticeStore = useNoticeStore()

const hideTarget = ref<NoticeMessage | null>(null)
const hiding = ref(false)
const restoring = ref(false)

const isOverview = computed(() => {
  const path = (route.path || '').replace(/\/$/, '') || '/'
  return path === '/plan/overview'
})

const screenKey = computed(() => {
  if (isOverview.value) return 'overview'
  if (route.name === 'teams-program') return TEAMS_PROGRAM_HELP_KEY
  const path = (route.path || '').replace(/\/$/, '') || '/'
  return HELP_SCREEN_KEY_BY_PATH[path] ?? null
})

const visibleMessages = computed(() => {
  if (!eventStore.selectedEvent?.id) return []
  const all = noticeStore.messages
  if (isOverview.value) return all
  const key = screenKey.value
  if (!key) return []
  if (key === TEAMS_PROGRAM_HELP_KEY) {
    const compact = programCompact(String(route.params.program ?? ''))
    return all.filter((row) =>
      row.screen_key === TEAMS_PROGRAM_HELP_KEY
      && programCompact(row.program) === compact
    )
  }
  return all.filter((row) => row.screen_key === key)
})

const showRestore = computed(() => isOverview.value && noticeStore.restore_available)

async function confirmHide() {
  const target = hideTarget.value
  if (!target) return
  hiding.value = true
  try {
    await noticeStore.hide(target.notice_id)
    hideTarget.value = null
  } finally {
    hiding.value = false
  }
}

async function restoreAll() {
  restoring.value = true
  try {
    await noticeStore.restore()
  } finally {
    restoring.value = false
  }
}
</script>

<template>
  <div v-if="visibleMessages.length || showRestore" class="notice-pane shrink-0">
    <div v-if="showRestore" class="notice-pane__restore">
      <button
          type="button"
          class="glass-btn-secondary !px-3 !py-1.5 !text-sm"
          :disabled="restoring"
          @click="restoreAll"
      >
        Ausgeblendete Nachrichten wieder anzeigen
      </button>
    </div>

    <ul v-if="visibleMessages.length" class="notice-pane__list">
      <li
          v-for="row in visibleMessages"
          :key="row.id"
          class="notice-pane__item"
          :class="row.kind === 'red_dot' ? 'notice-pane__item--warn' : 'notice-pane__item--time'"
      >
        <div class="notice-pane__row">
          <i
              class="bi bi-exclamation-triangle-fill notice-pane__icon"
              aria-hidden="true"
          />
          <component
              :is="isOverview && row.jump_path ? RouterLink : 'p'"
              v-bind="isOverview && row.jump_path ? {to: row.jump_path} : {}"
              class="notice-pane__body"
          >
            {{ row.body }}
          </component>
          <button
              v-if="row.hideable"
              type="button"
              class="notice-pane__hide"
              @click="hideTarget = row"
          >
            Nicht mehr anzeigen
          </button>
        </div>
      </li>
    </ul>

    <ConfirmationModal
        :show="!!hideTarget"
        type="warning"
        title="Nicht mehr anzeigen?"
        message="Diese Nachricht wird für diese Veranstaltung ausgeblendet."
        confirm-text="Nicht mehr anzeigen"
        cancel-text="Abbrechen"
        :disable-confirm-button="hiding"
        @confirm="confirmHide"
        @cancel="hideTarget = null"
    />
  </div>
</template>

<style scoped>
.notice-pane {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.notice-pane__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.notice-pane__item {
  border-radius: 0.5rem;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
}

.notice-pane__item--warn {
  background: color-mix(in srgb, var(--color-warning, #f59e0b) 18%, transparent);
  border: 1px solid color-mix(in srgb, var(--color-warning, #f59e0b) 40%, transparent);
}

.notice-pane__item--time {
  background: var(--color-bg, transparent);
  border: 1px solid var(--color-border);
}

.notice-pane__row {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
}

.notice-pane__icon {
  margin-top: 0.125rem;
  flex-shrink: 0;
}

.notice-pane__body {
  flex: 1 1 auto;
  min-width: 0;
  color: inherit;
  text-decoration: none;
}

.notice-pane__body[href]:hover {
  text-decoration: underline;
}

.notice-pane__hide {
  flex-shrink: 0;
  font-size: 0.75rem;
  color: var(--color-accent);
  background: none;
  border: 0;
  padding: 0;
  cursor: pointer;
  text-decoration: underline;
}

.notice-pane__hide:hover {
  color: var(--color-text);
}
</style>
