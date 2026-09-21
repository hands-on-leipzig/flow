<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {RouterLink, useRoute} from 'vue-router'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import {useAdminInlineVisibility} from '@/composables/useAdminInlineVisibility'
import {useEventStore} from '@/stores/event'
import {useNoticeStore, type NoticeMessage} from '@/stores/notice'
import {HELP_SCREEN_KEY_BY_PATH, noticeMirrorScreenKeysForPath, TEAMS_PROGRAM_HELP_KEY} from '@/utils/helpRoutes'
import {programCompact} from '@/utils/eventPrograms'

defineOptions({name: 'NoticePane'})

const route = useRoute()
const eventStore = useEventStore()
const noticeStore = useNoticeStore()
const {showAdminInline} = useAdminInlineVisibility()

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

const mirrorScreenKeys = computed(() => {
  const path = (route.path || '').replace(/\/$/, '') || '/'
  return noticeMirrorScreenKeysForPath(path)
})

const allowJump = computed(() => isOverview.value || mirrorScreenKeys.value.length > 0)

const visibleMessages = computed(() => {
  if (!eventStore.selectedEvent?.id) return []
  const all = noticeStore.messages
  if (isOverview.value) return all
  const mirrored = mirrorScreenKeys.value
  if (mirrored.length) {
    return all.filter((row) => mirrored.includes(row.screen_key ?? ''))
  }
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
const showTodayOverride = computed(() => showAdminInline.value && !!eventStore.selectedEvent?.id)
const showPane = computed(() =>
  visibleMessages.value.length > 0 || showRestore.value || showTodayOverride.value
)

watch(
  showAdminInline,
  async (on) => {
    noticeStore.setUseSimulatedToday(on)
    const eventId = eventStore.selectedEvent?.id
    if (eventId) await noticeStore.refresh(eventId)
  },
  {immediate: true}
)

async function onTodayInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  await noticeStore.setSimulatedToday(value || null)
}

async function clearSimulatedToday() {
  await noticeStore.setSimulatedToday(null)
}

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
  <div v-if="showPane" class="notice-pane shrink-0">
    <ul v-if="showPane" class="notice-pane__list">
      <li
          v-for="row in visibleMessages"
          :key="row.id"
          class="notice-pane__item"
      >
        <div
            class="notice-pane__tile"
            :class="row.kind === 'red_dot' ? 'notice-pane__tile--warn' : 'notice-pane__tile--time'"
        >
          <div class="notice-pane__head">
            <span
                v-if="row.kind === 'red_dot'"
                class="notice-pane__dot"
                aria-hidden="true"
            />
            <i
                v-else
                class="bi bi-lightbulb notice-pane__icon"
                aria-hidden="true"
            />
            <div class="notice-pane__content">
              <component
                  :is="allowJump && row.jump_path ? RouterLink : 'p'"
                  v-bind="allowJump && row.jump_path ? {to: row.jump_path} : {}"
                  class="notice-pane__body"
              >
                {{ row.body }}
              </component>
              <button
                  v-if="row.hideable"
                  type="button"
                  class="glass-btn-text"
                  @click="hideTarget = row"
              >
                Nicht mehr anzeigen
              </button>
            </div>
          </div>
        </div>
      </li>
      <li v-if="showRestore" class="notice-pane__restore-item">
        <button
            type="button"
            class="glass-btn-text"
            :disabled="restoring"
            title="Ausgeblendete Hinweise wieder anzeigen"
            @click="restoreAll"
        >
          Ausgeblendete Hinweise wieder anzeigen
        </button>
      </li>
      <li v-if="showTodayOverride" class="notice-pane__restore-item">
        <div
            class="glass-chip liquid-surface-inner notice-pane__today"
            title="Admin: Heute überschreiben, um Zeit-Hinweise zu prüfen"
        >
          <i class="bi bi-shield-lock notice-pane__today-mark" aria-hidden="true"/>
          <label class="notice-pane__today-label">
            Heute
            <input
                class="notice-pane__today-input"
                type="date"
                :value="noticeStore.simulatedToday ?? ''"
                @change="onTodayInput"
            >
          </label>
          <button
              v-if="noticeStore.simulatedToday"
              type="button"
              class="glass-btn-icon"
              title="Echtes Datum wiederherstellen"
              aria-label="Echtes Datum wiederherstellen"
              @click="clearSimulatedToday"
          >
            <i class="bi bi-x" aria-hidden="true"/>
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
  flex-direction: row;
  flex-wrap: wrap;
  align-items: stretch;
}

.notice-pane__item {
  flex: 0 0 20%;
  width: 20%;
  height: auto;
  box-sizing: border-box;
  padding: 0.25rem;
}

.notice-pane__restore-item {
  flex: 0 0 auto;
  width: auto;
  height: auto;
  box-sizing: border-box;
  padding: 0.25rem;
  display: flex;
  align-items: center;
}

.notice-pane__tile {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.5rem;
  height: auto;
  border-radius: 0.5rem;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
}

.notice-pane__head {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  width: 100%;
  min-width: 0;
}

.notice-pane__tile--warn {
  background: color-mix(in srgb, var(--color-warning, #f59e0b) 18%, transparent);
  border: 1px solid color-mix(in srgb, var(--color-warning, #f59e0b) 40%, transparent);
}

.notice-pane__tile--time {
  background: color-mix(in srgb, var(--color-success, #22c55e) 18%, transparent);
  border: 1px solid color-mix(in srgb, var(--color-success, #22c55e) 40%, transparent);
}

.notice-pane__dot {
  display: inline-block;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 9999px;
  background: #ef4444;
  flex-shrink: 0;
  margin-top: 0.375rem;
}

.notice-pane__icon {
  flex-shrink: 0;
  font-size: 1rem;
  line-height: 1;
  color: var(--color-text-muted, #4b5563);
}

.notice-pane__content {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.5rem;
  flex: 1 1 auto;
  min-width: 0;
}

.notice-pane__body {
  flex: 1 1 auto;
  min-width: 0;
  margin: 0;
  color: inherit;
  text-decoration: none;
}

.notice-pane__body[href]:hover {
  text-decoration: underline;
}

.notice-pane__today {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.65rem !important;
}

.notice-pane__today-mark {
  color: var(--color-text-muted);
  opacity: 0.85;
  font-size: 0.875rem;
}

.notice-pane__today-label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--color-text-muted);
}

.notice-pane__today-input {
  border: 1px solid var(--color-border);
  border-radius: 0.25rem;
  background: var(--color-bg, transparent);
  color: inherit;
  font-size: 0.8125rem;
  padding: 0.1rem 0.25rem;
}
</style>
