<script setup lang="ts">
import {computed, ref} from 'vue'
import axios from 'axios'
import Spinner from '@/components/atoms/Spinner.vue'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {eventPrograms, programDisplayName, programId} from '@/utils/eventPrograms'
import {flowFilename} from '@/utils/flowFilename'

defineOptions({name: 'OverviewSheetsPrint'})

/** Catalog Publikum role per first_program — matches FirstProgram::audienceRoleId */
const AUDIENCE_ROLE_BY_PROGRAM: Record<number, number> = {
  0: 14,
  1: 10,
  2: 10,
  3: 6,
  8: 24,
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const eventDate = computed(() => eventStore.selectedEvent?.date)
const busy = ref(false)
const roleId = ref(14)

const options = computed(() => {
  const rows: {id: number, label: string}[] = [{id: 14, label: 'Publikum'}]
  const seen = new Set<number>([14])
  for (const program of eventPrograms(eventStore.selectedEvent)) {
    const id = AUDIENCE_ROLE_BY_PROGRAM[programId(program)]
    if (!id || seen.has(id)) continue
    seen.add(id)
    rows.push({id, label: programDisplayName(program)})
  }
  return rows
})

async function errorFromBody(data: unknown): Promise<string> {
  if (data && typeof data === 'object' && !(data instanceof Blob) && 'error' in data) {
    const message = (data as {error?: string}).error
    if (message) return message
  }
  if (data instanceof Blob) {
    try {
      const json = JSON.parse(await data.text()) as {error?: string}
      if (json.error) return json.error
    } catch {
      // not JSON
    }
  }
  return 'PDF erzeugen fehlgeschlagen'
}

async function errorMessage(error: unknown): Promise<string> {
  return errorFromBody(axios.isAxiosError(error) ? error.response?.data : null)
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function downloadPdf() {
  if (!eventId.value || busy.value) return
  busy.value = true
  try {
    const started = await axios.post(`/print/${eventId.value}/overview-sheet`, {
      role_id: roleId.value,
    })
    const jobId = started.data?.id as string | undefined
    const filename = (started.data?.filename as string | undefined)
      || flowFilename('Uebersichtsplan', 'pdf', eventDate.value)
    if (!jobId) {
      showGlassToast('PDF erzeugen fehlgeschlagen', 'error')
      return
    }

    const deadline = Date.now() + 90_000
    while (Date.now() < deadline) {
      const response = await axios.get(
        `/print/${eventId.value}/overview-sheet/${jobId}`,
        {
          responseType: 'blob',
          timeout: 30_000,
          validateStatus: (status) => status === 200 || status === 202 || status === 502,
        },
      )
      if (response.status === 202) {
        await sleep(500)
        continue
      }
      if (response.status !== 200) {
        showGlassToast(await errorFromBody(response.data), 'error')
        return
      }
      const url = window.URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = response.headers['x-filename'] || filename
      link.click()
      window.URL.revokeObjectURL(url)
      return
    }
    showGlassToast('PDF-Erzeugung dauert zu lange.', 'error')
  } catch (error) {
    showGlassToast(await errorMessage(error), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <article class="liquid-surface-inner role-sheets">
    <header class="role-sheets__head">
      <h2 class="role-sheets__title">Übersichtsplan</h2>
      <p class="role-sheets__sub">Öffentlicher Tagesplan auf einer A4-Seite</p>
    </header>
    <div v-if="options.length > 1" class="role-sheets__body">
      <fieldset class="role-sheets__choices">
        <legend class="role-sheets__legend">Programm</legend>
        <label v-for="option in options" :key="option.id" class="role-sheets__choice">
          <input v-model.number="roleId" type="radio" name="overview-role" :value="option.id"/>
          <span>{{ option.label }}</span>
        </label>
      </fieldset>
    </div>
    <footer class="role-sheets__actions">
      <button
          type="button"
          class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
          :disabled="!eventId || busy"
          @click="downloadPdf"
      >
        <Spinner v-if="busy" size="sm"/>
        <span>{{ busy ? 'Erzeuge…' : 'PDF erzeugen' }}</span>
      </button>
    </footer>
  </article>
</template>

<style scoped>
.role-sheets {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  min-width: 0;
  padding: 1rem 1.05rem 1.05rem;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 38%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 90%, var(--liquid-tile-bg-inner));
  box-shadow:
    0 8px 20px rgba(15, 23, 42, 0.045),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.role-sheets__title {
  margin: 0;
  font-size: 0.98rem;
  font-weight: 650;
  letter-spacing: -0.015em;
  line-height: 1.3;
}

.role-sheets__sub {
  margin: 0.28rem 0 0;
  font-size: 0.8rem;
  line-height: 1.4;
  color: var(--color-text-muted);
}

.role-sheets__body {
  min-height: 0;
}

.role-sheets__choices {
  margin: 0;
  padding: 0;
  border: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.role-sheets__legend {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.role-sheets__choice {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.82rem;
  line-height: 1.3;
  cursor: pointer;
}

.role-sheets__actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
}
</style>
