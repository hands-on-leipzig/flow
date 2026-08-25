<script setup>
import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue'
import QPlanList from './QPlanList.vue'
import axios from 'axios'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'

import { formatDateTime } from '@/utils/dateTimeFormat'
import {showGlassToast} from '@/composables/useGlassToast'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import { getProgramTheme } from '@/utils/programTheme'


const props = defineProps({
  reload: { type: Number, required: false, default: 0 },
})

const qruns = ref([])
const loading = ref(true)
const error = ref(null)
const expandedQRunId = ref(null)
const qrunToDelete = ref(null)
const showDeletePreviewConfirm = ref(false)
let intervalId = null

const toggleExpanded = (id) => {
  expandedQRunId.value = expandedQRunId.value === id ? null : id
}

const loadQRuns = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get('/quality/qruns')
    qruns.value = response.data.qruns.map(qrun => {
      let selection = {}
      try {
        selection = qrun.selection ? JSON.parse(qrun.selection) : {}
      } catch (e) {
        console.warn(`Ungültiges JSON in selection für qrun ${qrun.id}`)
      }
      return {
        ...qrun,
        selection,
        // Raw null = Preview / ReRun (no mass-test grid)
        isPreviewRun: qrun.selection == null,
      }
    })
      } catch (err) {
    console.error('Fehler beim Laden der QRuns', err)
    error.value = 'Fehler beim Laden der Liste'
  } finally {
    loading.value = false
  }
}

onMounted(loadQRuns)
watch(() => props.reload, loadQRuns)
onBeforeUnmount(() => {
  if (intervalId) clearInterval(intervalId)
})

const previewRunCount = computed(() => qruns.value.filter(q => q.isPreviewRun).length)

const confirmDeleteQRun = (qrunId) => {
  qrunToDelete.value = qrunId
}

const cancelDeleteQRun = () => {
  qrunToDelete.value = null
}

const deleteQRunMessage = computed(() => {
  if (qrunToDelete.value === null) return ''
  return `QRun ${qrunToDelete.value || 'Unbekannt'} wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

const deletePreviewMessage = computed(() => {
  const n = previewRunCount.value
  if (n < 1) {
    return 'Es gibt derzeit keine Preview-/ReRun-QRuns (selection leer).'
  }
  return `${n} Preview-/ReRun-QRun${n === 1 ? '' : 's'} (ohne selection) löschen? ` +
    'Zugehörige Qualitätsdaten (q_plan / Teams / Matches) werden entfernt. Event-Pläne bleiben erhalten.'
})

async function handleDelete() {
  if (!qrunToDelete.value) return

  try {
    await axios.delete(`/quality/delete/${qrunToDelete.value}`)
    await loadQRuns()
    qrunToDelete.value = null
  } catch (err) {
    console.error('Fehler beim Löschen des QRuns:', err)
    showGlassToast('Löschen fehlgeschlagen.', 'error')
    qrunToDelete.value = null
  }
}

async function handleDeletePreviewRuns() {
  showDeletePreviewConfirm.value = false
  if (previewRunCount.value < 1) return

  try {
    const { data } = await axios.delete('/quality/preview-runs')
    await loadQRuns()
    const n = data?.q_runs_deleted ?? 0
    showGlassToast(
      n < 1 ? 'Keine Preview-QRuns gefunden.' : `${n} Preview-/ReRun-QRun${n === 1 ? '' : 's'} gelöscht.`,
      n < 1 ? 'info' : 'success',
    )
  } catch (err) {
    console.error('Fehler beim Löschen der Preview-QRuns:', err)
    showGlassToast('Löschen der Preview-QRuns fehlgeschlagen.', 'error')
  }
}

// compress functionality removed

function resolveFirstProgram(qrun) {
  return Number(qrun.first_program ?? qrun.selection?.first_program ?? 3)
}

function isFuture8(qrun) {
  return resolveFirstProgram(qrun) === 8
}

function programTheme(qrun) {
  return getProgramTheme(isFuture8(qrun) ? 'future8' : 'challenge')
}

</script>

<template>
  <div class="space-y-2 mt-4">
    <div class="flex items-center justify-end gap-2">
      <button
        type="button"
        class="px-3 py-1.5 text-sm rounded border border-red-200 text-red-700 hover:bg-red-50 disabled:opacity-40 disabled:cursor-not-allowed"
        :disabled="loading || previewRunCount < 1"
        title="Löscht alle QRuns ohne selection (Preview / ReRun)"
        @click="showDeletePreviewConfirm = true"
      >
        Preview-QRuns löschen{{ previewRunCount > 0 ? ` (${previewRunCount})` : '' }}
      </button>
    </div>

    <div v-if="loading" class="text-[var(--color-text-subtle)]">Lade QRuns …</div>
    <div v-else-if="error" class="text-red-500">{{ error }}</div>
    <div v-else-if="qruns.length === 0" class="text-[var(--color-text-subtle)]">Keine QRuns gefunden.</div>
    <div v-else>
      <div
        v-for="qrun in qruns"
        :key="qrun.id"
        class="border rounded bg-[var(--color-bg-muted)] overflow-hidden"
      >
        <div
          class="flex p-4 items-start hover:bg-[var(--color-bg-hover)] cursor-pointer"
          @click="toggleExpanded(qrun.id)"
        >
          <!-- Spalte 1: Name + Kommentar -->
          <div class="basis-[35%] flex-shrink-0">
            <div class="font-bold text-lg flex items-center gap-2">
              <img
                v-if="programTheme(qrun).catalogName"
                :src="programLogoSrc(programTheme(qrun).catalogName)"
                :alt="programLogoAlt(programTheme(qrun).catalogName)"
                :title="programTheme(qrun).shortName"
                class="w-8 h-8 flex-shrink-0 object-contain"
              />
              <span>{{ qrun.id }} {{ qrun.name }}</span>
            </div>
            <div class="text-xs text-[var(--color-text-subtle)] italic"> {{ qrun.host || 'unknown' }} </div>
            <div class="text-sm text-[var(--color-text-muted)] whitespace-pre-line">{{ qrun.comment || '—' }}</div>
          </div>

          <!-- Spalte 2: Teams + Runden -->
          <div class="basis-[20%] flex-shrink-0 text-sm text-[var(--color-text-muted)] space-y-1">
            <div><strong>Teams:</strong> {{ qrun.selection.min_teams ?? '?' }}–{{ qrun.selection.max_teams ?? '?' }}</div>
            <div><strong>Runden:</strong> {{ qrun.selection.jury_rounds?.join(', ') ?? '?' }}</div>
          </div>

          <!-- Spalte 3: Spuren + Tische/Felder -->
          <div class="basis-[20%] flex-shrink-0 text-sm text-[var(--color-text-muted)] space-y-1">
            <div><strong>Spuren:</strong> {{ qrun.selection.jury_lanes?.join(', ') ?? '?' }}</div>
            <div>
              <strong>{{ isFuture8(qrun) ? 'Felder' : 'Tische' }}:</strong>
              {{ qrun.selection.tables?.join(', ') ?? '?' }}
            </div>
          </div>

          <!-- Spalte 4: QPlans + Status + Start/Ende -->
          <div class="basis-[15%] flex-shrink-0 text-right text-sm space-y-1">
            <div class="flex justify-end items-center gap-2">
              <div>QPlans: {{ qrun.qplans_calculated }} / {{ qrun.qplans_total }}</div>
              <span
                class="inline-block rounded px-2 py-0.5 text-xs"
                :class="{
                  'bg-gray-400 text-white': qrun.status === 'pending',
                  'bg-yellow-500 text-white': qrun.status === 'running',
                  'bg-green-600 text-white': qrun.status === 'done',
                }"
              >
                {{ qrun.status }}
              </span>            
            </div>
            <div>Start: {{ formatDateTime(qrun.started_at) }}</div>
            <div v-if="qrun.finished_at">
              Dauer: {{
                Math.round(
                  (new Date(qrun.finished_at) - new Date(qrun.started_at)) / 60000
                )
              }} Minuten
            </div>
          </div>

          <!-- kein @click.stop hier -->
        <div class="basis-[10%] flex-shrink-0 flex items-center justify-center ml-4">
          <div class="flex flex-col items-center gap-2">
            <button
              @click.stop="confirmDeleteQRun(qrun.id)"
              class="px-2 py-1 rounded hover:bg-red-50"
              title="QRun löschen (inkl. zugehöriger QPlans & Pläne)"
            >
              🗑️
            </button>
            
          </div>
        </div>

        </div>

        <div v-if="expandedQRunId === qrun.id" class="border-t border-[var(--color-border)]">
           <div class="bg-white px-4 py-2">
            <QPlanList
              :qrun="qrun.id"
              @refreshParent="loadQRuns"
            />
          </div>
        </div>

      </div>
    </div>
  </div>

  <ConfirmationModal
    :show="qrunToDelete !== null"
    title="QRun löschen"
    :message="deleteQRunMessage"
    type="danger"
    confirm-text="Löschen"
    cancel-text="Abbrechen"
    @confirm="handleDelete"
    @cancel="cancelDeleteQRun"
  />

  <ConfirmationModal
    :show="showDeletePreviewConfirm"
    title="Preview-QRuns löschen"
    :message="deletePreviewMessage"
    type="danger"
    confirm-text="Löschen"
    cancel-text="Abbrechen"
    :disable-confirm-button="previewRunCount < 1"
    @confirm="handleDeletePreviewRuns"
    @cancel="showDeletePreviewConfirm = false"
  />
</template>