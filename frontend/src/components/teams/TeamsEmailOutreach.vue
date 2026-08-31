<script setup lang="ts">
import {computed, ref} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {drahtIdFor, eventPrograms, programSlug} from '@/utils/eventPrograms'
import {flowFilename} from '@/utils/flowFilename'

const props = defineProps<{
  currentProgram: string
}>()

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const eventDate = computed(() => eventStore.selectedEvent?.date)
const programs = computed(() => eventPrograms(eventStore.selectedEvent))

const open = ref(false)
const busy = ref(false)
const selectedPrograms = ref<string[]>([])

function programSlugs(): string[] {
  return programs.value.map((p) => programSlug(p.name))
}

function openDialog() {
  selectedPrograms.value = [props.currentProgram]
  open.value = true
}

function close() {
  open.value = false
}

function toggleProgram(slug: string) {
  const set = new Set(selectedPrograms.value)
  if (set.has(slug)) set.delete(slug)
  else set.add(slug)
  selectedPrograms.value = [...set]
}

function excelFilename() {
  return flowFilename('Teams', 'xlsx', eventDate.value)
}

type CoachContact = {email: string}

async function loadCoachEmails(): Promise<string[]> {
  if (!eventId.value || selectedPrograms.value.length === 0) return []

  const seen = new Set<string>()
  const emails: string[] = []

  for (const slug of selectedPrograms.value) {
    const drahtId = drahtIdFor(eventStore.selectedEvent, slug)
    if (!drahtId) continue

    try {
      const {data} = await axios.get(`/draht/people/${drahtId}`)
      const {total_players, total_coaches, ...teams} = data ?? {}
      for (const teamData of Object.values(teams) as Record<string, unknown>[]) {
        if (!teamData || typeof teamData !== 'object') continue
        for (const coach of (teamData.coaches as unknown[]) ?? []) {
          let email = ''
          if (typeof coach === 'object' && coach !== null) {
            email = String((coach as CoachContact).email || '').trim()
          }
          if (!email) continue
          const key = email.toLowerCase()
          if (seen.has(key)) continue
          seen.add(key)
          emails.push(email)
        }
      }
    } catch {
      // skip program on failure
    }
  }

  return emails
}

async function copyEmails() {
  if (!eventId.value || busy.value) return
  busy.value = true
  try {
    const emails = await loadCoachEmails()
    if (!emails.length) {
      showGlassToast('Keine E-Mails', 'info')
      return
    }
    await navigator.clipboard.writeText(emails.join('; '))
    showGlassToast(`${emails.length} E-Mails kopiert`, 'success')
    close()
  } catch {
    showGlassToast('Zwischenablage nicht verfügbar', 'error')
  } finally {
    busy.value = false
  }
}

async function openMailto() {
  if (!eventId.value || busy.value) return
  busy.value = true
  try {
    const emails = await loadCoachEmails()
    if (!emails.length) {
      showGlassToast('Keine E-Mails', 'info')
      return
    }
    window.location.href = `mailto:?bcc=${encodeURIComponent(emails.join(','))}`
    close()
  } finally {
    busy.value = false
  }
}

async function downloadExcel() {
  if (!eventId.value || busy.value) return
  busy.value = true
  try {
    const response = await axios.get(`/events/${eventId.value}/teams/people/export`, {
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = response.headers['x-filename'] || excelFilename()
    link.click()
    window.URL.revokeObjectURL(url)
    close()
  } catch {
    showGlassToast('Export fehlgeschlagen', 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <button
      type="button"
      class="glass-btn-secondary teams-email-trigger"
      :disabled="!eventId"
      @click="openDialog"
  >
    <i class="bi bi-envelope" aria-hidden="true"/>
    E-Mail
  </button>

  <Teleport to="body">
    <div
        v-if="open"
        class="glass-scrim fixed inset-0 z-[100] flex items-center justify-center p-4"
        @click="close"
    >
      <div
          class="glass-modal teams-email-dialog"
          role="dialog"
          aria-labelledby="teams-email-dialog-title"
          @click.stop
      >
        <h2 id="teams-email-dialog-title" class="teams-email-dialog__title">E-Mail-Adressen</h2>

        <fieldset class="teams-email-dialog__programs">
          <legend class="teams-email-dialog__legend">Programme (Coaches)</legend>
          <label
              v-for="slug in programSlugs()"
              :key="slug"
              class="teams-email-dialog__check"
          >
            <input
                type="checkbox"
                :checked="selectedPrograms.includes(slug)"
                @change="toggleProgram(slug)"
            />
            <span>{{ slug.replace(/_/g, ' ') }}</span>
          </label>
        </fieldset>

        <div class="teams-email-dialog__actions">
          <button
              type="button"
              class="teams-email-dialog__btn"
              :disabled="busy || selectedPrograms.length === 0"
              @click="copyEmails"
          >
            <i class="bi bi-clipboard" aria-hidden="true"/>
            Ins Clipboard kopieren
          </button>
          <button
              type="button"
              class="teams-email-dialog__btn"
              :disabled="busy || selectedPrograms.length === 0"
              @click="openMailto"
          >
            <i class="bi bi-envelope-open" aria-hidden="true"/>
            Direkt im Mailprogramm öffnen
          </button>
          <button
              type="button"
              class="teams-email-dialog__btn"
              :disabled="busy"
              @click="downloadExcel"
          >
            <i class="bi bi-file-earmark-excel" aria-hidden="true"/>
            Excel download
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.teams-email-trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  width: 100%;
  justify-content: center;
}

.teams-email-dialog {
  width: min(100%, 24rem);
  padding: 1.35rem 1.25rem 1.25rem;
}

.teams-email-dialog__title {
  margin: 0 0 1rem;
  font-size: 1.05rem;
  font-weight: 650;
  color: var(--color-text);
}

.teams-email-dialog__programs {
  border: none;
  margin: 0 0 1rem;
  padding: 0;
}

.teams-email-dialog__legend {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
  margin-bottom: 0.5rem;
}

.teams-email-dialog__check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  margin-bottom: 0.35rem;
  cursor: pointer;
}

.teams-email-dialog__actions {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.teams-email-dialog__btn {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  width: 100%;
  padding: 0.65rem 0.85rem;
  border-radius: var(--radius);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-tile-bg);
  color: var(--color-text);
  font-size: 0.875rem;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  box-shadow:
    0 6px 14px rgba(15, 23, 42, 0.07),
    inset 0 1px 0 rgba(255, 255, 255, 0.9),
    inset 0 -1px 0 rgba(15, 23, 42, 0.04);
  transition: background 0.12s ease, box-shadow 0.1s ease;
}

.teams-email-dialog__btn .bi {
  font-size: 1.05rem;
  opacity: 0.85;
  flex-shrink: 0;
}

.teams-email-dialog__btn:hover:not(:disabled) {
  background: var(--color-bg-hover);
}

.teams-email-dialog__btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
</style>
