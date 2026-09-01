<script setup lang="ts">
import {computed, ref} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {flowFilename} from '@/utils/flowFilename'

export type VolunteerOutreachPerson = {
  id: number
  first_name: string
  last_name: string
  email: string
  mobile?: string | null
  organization?: string | null
  updated_at?: string | null
}

const props = defineProps<{
  scope: 'pool' | 'roster'
  /** When set, outreach uses this list instead of loading the full pool/roster. */
  people?: VolunteerOutreachPerson[]
}>()

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const eventDate = computed(() => eventStore.selectedEvent?.date)
const open = ref(false)
const busy = ref(false)

const usesCustomPeople = computed(() => props.people !== undefined)

function excelFilename() {
  const name = props.scope === 'roster' ? 'Helferliste_email' : 'Personen_email'
  return flowFilename(name, 'xlsx', eventDate.value)
}

function close() {
  open.value = false
}

function uniqueEmails(people: VolunteerOutreachPerson[]): string[] {
  const seen = new Set<string>()
  const emails: string[] = []
  for (const person of people) {
    const email = person.email?.trim()
    if (!email) continue
    const key = email.toLowerCase()
    if (seen.has(key)) continue
    seen.add(key)
    emails.push(email)
  }
  return emails
}

async function loadPeople(): Promise<VolunteerOutreachPerson[]> {
  if (usesCustomPeople.value) return props.people ?? []
  if (!eventId.value) return []
  if (props.scope === 'pool') {
    const {data} = await axios.get(`/events/${eventId.value}/volunteers`)
    return data.people ?? []
  }
  const {data} = await axios.get(`/events/${eventId.value}/volunteer-roster`)
  return (data.roster ?? []).map((row: {person: VolunteerOutreachPerson}) => row.person)
}

async function fetchEmails(): Promise<string[]> {
  return uniqueEmails(await loadPeople())
}

async function copyEmails() {
  if (!eventId.value || busy.value) return
  busy.value = true
  try {
    const emails = await fetchEmails()
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
    const emails = await fetchEmails()
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
    const people = await loadPeople()
    if (!people.length) {
      showGlassToast('Keine Personen', 'info')
      return
    }

    const params: Record<string, string> = {scope: props.scope, variant: 'email'}
    if (usesCustomPeople.value) {
      params.person_ids = people.map((person) => person.id).filter((id) => id > 0).join(',')
    }

    const response = await axios.get(`/events/${eventId.value}/volunteers/export`, {
      params,
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
      class="glass-btn-secondary vol-email-trigger"
      :disabled="!eventId"
      @click="open = true"
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
          class="glass-modal vol-email-dialog"
          role="dialog"
          aria-labelledby="vol-email-dialog-title"
          @click.stop
      >
        <h2 id="vol-email-dialog-title" class="vol-email-dialog__title">E-Mail-Adressen</h2>

        <div class="vol-email-dialog__actions">
          <button
              type="button"
              class="vol-email-dialog__btn"
              :disabled="busy"
              @click="copyEmails"
          >
            <i class="bi bi-clipboard" aria-hidden="true"/>
            Ins Clipboard kopieren
          </button>
          <button
              type="button"
              class="vol-email-dialog__btn"
              :disabled="busy"
              @click="openMailto"
          >
            <i class="bi bi-envelope-open" aria-hidden="true"/>
            Direkt im Mailprogramm öffnen
          </button>
          <button
              type="button"
              class="vol-email-dialog__btn"
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
.vol-email-trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  flex-shrink: 0;
}

.vol-email-dialog {
  width: min(100%, 22rem);
  padding: 1.35rem 1.25rem 1.25rem;
}

.vol-email-dialog__title {
  margin: 0 0 1rem;
  font-size: 1.05rem;
  font-weight: 650;
  color: var(--color-text);
}

.vol-email-dialog__actions {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.vol-email-dialog__btn {
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

.vol-email-dialog__btn .bi {
  font-size: 1.05rem;
  opacity: 0.85;
  flex-shrink: 0;
}

.vol-email-dialog__btn:hover:not(:disabled) {
  background: var(--color-bg-hover);
}

.vol-email-dialog__btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
</style>
