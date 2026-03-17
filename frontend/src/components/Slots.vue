<script setup lang="ts">
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import {useEventStore} from '@/stores/event'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import LoaderText from '@/components/atoms/LoaderText.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import {programLogoSrc, programLogoAlt} from '@/utils/images'

/** 0 = beide, 2 = Explore, 3 = Challenge — wie Freie Blöcke */
type SlotBlock = {
  id: number
  name: string
  description: string
  link: string
  duration: number
  first_program: number
  active: boolean
}

type TeamRow = {
  team_id: number
  team_number_plan: number | null
  team_number_hot: string | null
  team_name: string
  first_program: number
  start: string | null
}

function normalizeDurationMinutes(d: number): number {
  const n = Math.round(Number(d) / 5) * 5
  return Math.min(480, Math.max(5, n || 5))
}

function firstProgramFromFlags(fe: boolean, fc: boolean): number {
  if (fe && fc) return 0
  if (fe) return 2
  if (fc) return 3
  return 0
}

function flagsFromFirstProgram(fp: number): { for_explore: boolean; for_challenge: boolean } {
  const p = Number(fp)
  if (p === 0) return {for_explore: true, for_challenge: true}
  if (p === 2) return {for_explore: true, for_challenge: false}
  if (p === 3) return {for_explore: false, for_challenge: true}
  return {for_explore: true, for_challenge: true}
}

function mapApiToSlot(b: Record<string, unknown>): SlotBlock {
  const fe = !!b.for_explore
  const fc = !!b.for_challenge
  const raw = Number(b.duration)
  const dur =
    Number.isFinite(raw) && raw > 0 ? normalizeDurationMinutes(raw) : 30
  return {
    id: Number(b.id),
    name: String(b.name ?? ''),
    description: (b.description as string) ?? '',
    link: (b.link as string) ?? '',
    duration: dur,
    first_program: firstProgramFromFlags(fe, fc),
    active: b.active !== false,
  }
}

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const planId = ref<number | null>(null)
const loading = ref(true)
const blocks = ref<SlotBlock[]>([])
const selectedId = ref<number | null>(null)
const teams = ref<TeamRow[]>([])
const loadingTeams = ref(false)
const savingBlockId = ref<number | null>(null)
const blockToDelete = ref<SlotBlock | null>(null)
const errorMsg = ref<string | null>(null)

const newSlotName = ref('')
const newSlotDescription = ref('')
const newSlotLink = ref('')
const newSlotDuration = ref(30)
const newFirstProgram = ref(0)
const newSlotCardRef = ref<HTMLElement | null>(null)
const newSlotInput = ref<HTMLInputElement | null>(null)
const isCreatingSlot = ref(false)
const isSavingNew = ref(false)

const selectedBlock = computed(() => blocks.value.find((b) => b.id === selectedId.value) ?? null)

function programIcon(fp: number): { src: string; alt: string } {
  if (fp === 3) return {src: programLogoSrc('C'), alt: programLogoAlt('C')}
  return {src: programLogoSrc('E'), alt: programLogoAlt('E')}
}

async function loadPlan() {
  if (!event.value?.id) return
  const {data} = await axios.get(`/plans/event/${event.value.id}`)
  planId.value = data?.id ?? null
}

async function loadBlocks() {
  if (!planId.value) return
  errorMsg.value = null
  const {data} = await axios.get<Record<string, unknown>[]>(
    `/plans/${planId.value}/slot-blocks`
  )
  const rows = Array.isArray(data) ? data : []
  blocks.value = rows.map((row) => mapApiToSlot(row))
  await Promise.all(
    rows.map(async (row, i) => {
      const desired = blocks.value[i]?.duration
      if (desired == null || Number(row.duration) === desired) return
      try {
        await axios.put(`/plans/${planId.value}/slot-blocks/${row.id}`, {
          duration: desired,
        })
      } catch {
        /* ignore */
      }
    })
  )
  if (selectedId.value && !blocks.value.some((b) => b.id === selectedId.value)) {
    selectedId.value = blocks.value[0]?.id ?? null
  } else if (!selectedId.value && blocks.value.length) {
    selectedId.value = blocks.value[0].id
  }
}

async function loadTeams() {
  if (!planId.value || !selectedId.value) {
    teams.value = []
    return
  }
  loadingTeams.value = true
  try {
    const {data} = await axios.get<{ teams: TeamRow[] }>(
      `/plans/${planId.value}/slot-blocks/${selectedId.value}/teams`
    )
    teams.value = data?.teams ?? []
  } finally {
    loadingTeams.value = false
  }
}

/** Nur Pfeiltasten / Tab — Dauer nur in 5-Min-Schritten per Spinner */
function onDurationKeydown(e: KeyboardEvent) {
  const ok = [
    'Tab',
    'ArrowUp',
    'ArrowDown',
    'ArrowLeft',
    'ArrowRight',
    'Home',
    'End',
    'Enter',
  ].includes(e.key)
  if (e.metaKey || e.ctrlKey || e.altKey) return
  if (!ok && e.key.length === 1) e.preventDefault()
}

function onDurationInputBlock(block: SlotBlock, el: HTMLInputElement) {
  const v = normalizeDurationMinutes(Number(el.value) || 5)
  if (v === block.duration) return
  block.duration = v
  patchBlock(block, {duration: v})
}

function onNewDurationChange(el: HTMLInputElement) {
  newSlotDuration.value = normalizeDurationMinutes(Number(el.value) || 5)
  el.value = String(newSlotDuration.value)
}

/** Gleiche Logik wie FreeBlocks.vue toggleProgram */
function toggleProgramBlock(block: SlotBlock, program: 2 | 3) {
  if (!block.active) return
  let fp = block.first_program
  if (program === 2) {
    if (fp === 2) fp = 3
    else if (fp === 3) fp = 0
    else if (fp === 0) fp = 3
    else fp = 2
  } else {
    if (fp === 3) fp = 2
    else if (fp === 2) fp = 0
    else if (fp === 0) fp = 2
    else fp = 3
  }
  block.first_program = fp
  const {for_explore, for_challenge} = flagsFromFirstProgram(fp)
  patchBlock(block, {for_explore, for_challenge})
}

function toggleProgramNew(program: 2 | 3) {
  let fp = newFirstProgram.value
  if (program === 2) {
    if (fp === 2) fp = 3
    else if (fp === 3) fp = 0
    else if (fp === 0) fp = 3
    else fp = 2
  } else {
    if (fp === 3) fp = 2
    else if (fp === 2) fp = 0
    else if (fp === 0) fp = 2
    else fp = 3
  }
  newFirstProgram.value = fp
}

function toggleActiveBlock(block: SlotBlock, active: boolean) {
  block.active = active
  patchBlock(block, {active})
}

function canCreateNewSlot() {
  return newSlotName.value.trim().length > 0
}

async function createNewSlotBlock() {
  if (!planId.value || isCreatingSlot.value) return
  if (!canCreateNewSlot()) return

  const {for_explore, for_challenge} = flagsFromFirstProgram(newFirstProgram.value)
  isCreatingSlot.value = true
  isSavingNew.value = true
  errorMsg.value = null
  try {
    const {data} = await axios.post<Record<string, unknown>>(
      `/plans/${planId.value}/slot-blocks`,
      {
        name: newSlotName.value.trim(),
        description: newSlotDescription.value.trim() || null,
        link: newSlotLink.value.trim() || null,
        duration: normalizeDurationMinutes(newSlotDuration.value),
        for_explore,
        for_challenge,
        active: true,
      }
    )
    newSlotName.value = ''
    newSlotDescription.value = ''
    newSlotLink.value = ''
    newSlotDuration.value = 30
    newFirstProgram.value = 0
    await loadBlocks()
    selectedId.value = Number(data.id)
    await nextTick()
    newSlotInput.value?.focus()
  } catch (e: any) {
    const d = e?.response?.data
    errorMsg.value =
      (d?.errors && Object.values(d.errors).flat().join(' ')) ||
      d?.message ||
      e?.message ||
      'Anlegen fehlgeschlagen'
  } finally {
    isCreatingSlot.value = false
    isSavingNew.value = false
  }
}

function handleClickOutside(e: MouseEvent) {
  const el = newSlotCardRef.value
  if (el && !el.contains(e.target as Node)) {
    if (newSlotName.value.trim()) createNewSlotBlock()
  }
}

onMounted(async () => {
  loading.value = true
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  await loadPlan()
  if (planId.value) await loadBlocks()
  await loadTeams()
  loading.value = false
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

watch(planId, async (id) => {
  if (id) {
    await loadBlocks()
    await loadTeams()
  }
})

watch(selectedId, () => {
  loadTeams()
})

async function patchBlock(block: SlotBlock, patch: Record<string, unknown>) {
  if (!planId.value) return
  savingBlockId.value = block.id
  errorMsg.value = null
  try {
    const {data} = await axios.put<Record<string, unknown>>(
      `/plans/${planId.value}/slot-blocks/${block.id}`,
      patch
    )
    const mapped = mapApiToSlot(data)
    const i = blocks.value.findIndex((b) => b.id === block.id)
    if (i >= 0) {
      blocks.value[i] = {...blocks.value[i], ...mapped}
    }
    blocks.value = [...blocks.value].sort((a, b) =>
      (a.name || '').localeCompare(b.name || '', 'de', {sensitivity: 'base'})
    )
    if (patch.for_explore !== undefined || patch.for_challenge !== undefined) {
      await loadTeams()
    }
  } catch (e: any) {
    errorMsg.value = e?.response?.data?.message || e?.message || 'Speichern fehlgeschlagen'
  } finally {
    savingBlockId.value = null
  }
}

async function confirmDelete() {
  const b = blockToDelete.value
  if (!b || !planId.value) return
  try {
    await axios.delete(`/plans/${planId.value}/slot-blocks/${b.id}`)
    blockToDelete.value = null
    if (selectedId.value === b.id) selectedId.value = null
    await loadBlocks()
    await loadTeams()
  } catch (e: any) {
    errorMsg.value = e?.response?.data?.message || 'Löschen fehlgeschlagen'
  }
}

function toDatetimeLocal(iso: string | null): string {
  if (!iso) return ''
  return dayjs(iso).format('YYYY-MM-DDTHH:mm')
}

async function onTeamStartChange(row: TeamRow, value: string) {
  if (!planId.value || !selectedId.value) return
  const start = value ? dayjs(value).format('YYYY-MM-DD HH:mm:ss') : null
  try {
    const {data} = await axios.patch(
      `/plans/${planId.value}/slot-blocks/${selectedId.value}/teams/${row.team_id}`,
      {start}
    )
    row.start = data.start
    teams.value = [...teams.value].sort((a, b) => {
      if (!a.start && !b.start) return (a.team_number_plan ?? 0) - (b.team_number_plan ?? 0)
      if (!a.start) return 1
      if (!b.start) return -1
      return dayjs(a.start).valueOf() - dayjs(b.start).valueOf()
    })
  } catch {
    await loadTeams()
  }
}

const inputUnderline =
  'border-b border-gray-300 w-full focus:outline-none focus:border-blue-500'
const inputTitle =
  'text-sm md:text-md font-semibold border-b border-gray-300 flex-1 focus:outline-none focus:border-blue-500'
</script>

<template>
  <div>
    <div v-if="loading" class="flex items-center justify-center h-full flex-col text-gray-600 min-h-[400px]">
      <LoaderFlow/>
      <LoaderText/>
    </div>
    <div v-else-if="!planId" class="p-3 md:p-6 text-gray-600">Kein Plan für diese Veranstaltung.</div>
    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6 p-3 md:p-6">
      <div class="lg:col-span-1 min-w-0">
        <h2 class="text-lg md:text-xl font-bold mb-3 md:mb-4">Slot-Blöcke</h2>
        <p v-if="errorMsg" class="text-sm text-red-600 mb-2">{{ errorMsg }}</p>

        <div class="space-y-3 md:space-y-4 max-h-[calc(100vh-200px)] overflow-y-auto pr-1">
          <div
            v-for="block in blocks"
            :key="block.id"
            class="p-3 md:p-4 mb-2 border rounded shadow-sm md:shadow transition-all duration-200 cursor-pointer flex gap-3"
            :class="[
              selectedId === block.id ? 'ring-2 ring-blue-500 border-blue-400 shadow-md' : 'hover:shadow-md',
              block.active ? 'bg-white border-gray-200' : 'opacity-60 bg-gray-50 border-gray-200',
            ]"
            @click="selectedId = block.id"
          >
            <div class="flex flex-col items-center gap-2 flex-shrink-0 pt-0.5" @click.stop>
              <ToggleSwitch
                :model-value="block.active"
                @update:model-value="toggleActiveBlock(block, $event)"
              />
              <button
                type="button"
                class="text-lg hover:text-red-800"
                title="Slot-Block löschen"
                @click="blockToDelete = block"
              >
                <i style="color: grey" class="bi bi-trash-fill"/>
              </button>
            </div>
            <div class="flex-1 min-w-0">
              <input
                v-model="block.name"
                :disabled="!block.active"
                :class="[
                  inputTitle,
                  'w-full mb-2',
                  !block.active ? 'text-gray-400 cursor-not-allowed' : '',
                ]"
                placeholder="Name"
                @click.stop
                @blur="block.active && patchBlock(block, { name: block.name.trim() })"
              />
              <input
                v-model="block.description"
                type="text"
                :disabled="!block.active"
                :class="[
                  inputUnderline,
                  'text-xs md:text-sm text-gray-700 mb-2',
                  !block.active ? 'text-gray-400 cursor-not-allowed' : '',
                ]"
                placeholder="Beschreibung"
                @click.stop
                @blur="block.active && patchBlock(block, { description: block.description || null })"
              />
              <input
                v-model="block.link"
                type="url"
                :disabled="!block.active"
                :class="[
                  inputUnderline,
                  'text-xs md:text-sm text-gray-700 mb-2',
                  !block.active ? 'text-gray-400 cursor-not-allowed' : '',
                ]"
                placeholder="Link (URL)"
                @click.stop
                @blur="block.active && patchBlock(block, { link: block.link || null })"
              />
              <div class="flex flex-wrap items-center gap-4 mb-1">
                <div class="flex items-center gap-2">
                  <span class="text-xs text-gray-600 whitespace-nowrap">Dauer (Min.)</span>
                  <input
                    type="number"
                    :value="block.duration"
                    min="5"
                    max="480"
                    step="5"
                    :disabled="!block.active"
                    inputmode="none"
                    class="w-[4.25rem] text-sm text-center border border-gray-300 rounded px-1 py-0.5 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-400"
                    title="Nur mit Pfeiltasten oder Klick auf ▲▼ ändern (5-Min-Schritte)"
                    @click.stop
                    @keydown="onDurationKeydown"
                    @paste.prevent
                    @input="onDurationInputBlock(block, $event.target as HTMLInputElement)"
                  />
                </div>
                <div class="flex justify-center gap-1">
                  <img
                    :src="programLogoSrc('E')"
                    :alt="programLogoAlt('E')"
                    :class="[
                      'w-8 h-8 transition-all duration-200',
                      !block.active
                        ? 'opacity-30 grayscale cursor-not-allowed'
                        : block.first_program === 2 || block.first_program === 0
                          ? 'opacity-100 cursor-pointer hover:scale-110'
                          : 'opacity-30 grayscale cursor-pointer hover:scale-110',
                    ]"
                    title="FIRST LEGO League Explore"
                    @click.stop="toggleProgramBlock(block, 2)"
                  />
                  <img
                    :src="programLogoSrc('C')"
                    :alt="programLogoAlt('C')"
                    :class="[
                      'w-8 h-8 transition-all duration-200',
                      !block.active
                        ? 'opacity-30 grayscale cursor-not-allowed'
                        : block.first_program === 3 || block.first_program === 0
                          ? 'opacity-100 cursor-pointer hover:scale-110'
                          : 'opacity-30 grayscale cursor-pointer hover:scale-110',
                    ]"
                    title="FIRST LEGO League Challenge"
                    @click.stop="toggleProgramBlock(block, 3)"
                  />
                </div>
              </div>
              <div v-if="savingBlockId === block.id" class="text-xs text-gray-500">Speichern…</div>
            </div>
          </div>

          <div
            ref="newSlotCardRef"
            class="p-3 md:p-4 mb-2 border-dashed border-2 border-gray-300 rounded bg-gray-50 shadow-sm"
            @click.stop
          >
            <div class="mb-2">
              <input
                ref="newSlotInput"
                v-model="newSlotName"
                :disabled="isSavingNew"
                :class="inputTitle + ' w-full'"
                placeholder="Neuer Slot-Block"
                @keyup.enter="createNewSlotBlock"
              />
              <p v-if="!newSlotName.trim()" class="text-xs text-gray-500 mt-1">
                Kurzer Name für den Zusatzblock (z. B. Interview, Foto).
              </p>
            </div>
            <transition name="fade">
              <div v-if="newSlotName.trim().length > 0" class="space-y-2">
                <input
                  v-model="newSlotDescription"
                  :disabled="isSavingNew"
                  type="text"
                  :class="inputUnderline + ' text-xs md:text-sm text-gray-700'"
                  placeholder="Beschreibung"
                  @keyup.enter="createNewSlotBlock"
                />
                <input
                  v-model="newSlotLink"
                  :disabled="isSavingNew"
                  type="url"
                  :class="inputUnderline + ' text-xs md:text-sm text-gray-700'"
                  placeholder="Link (URL)"
                  @keyup.enter="createNewSlotBlock"
                />
                <div class="flex flex-wrap items-center gap-4">
                  <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-600">Dauer (Min.)</span>
                    <input
                      type="number"
                      :value="newSlotDuration"
                      min="5"
                      max="480"
                      step="5"
                      :disabled="isSavingNew"
                      inputmode="none"
                      class="w-[4.25rem] text-sm text-center border border-gray-300 rounded px-1 py-0.5"
                      @keydown="onDurationKeydown"
                      @paste.prevent
                      @input="onNewDurationChange($event.target as HTMLInputElement)"
                    />
                  </div>
                  <div class="flex gap-1">
                    <img
                      :src="programLogoSrc('E')"
                      :alt="programLogoAlt('E')"
                      :class="[
                        'w-8 h-8 transition-all cursor-pointer',
                        newFirstProgram === 2 || newFirstProgram === 0
                          ? 'opacity-100 hover:scale-110'
                          : 'opacity-30 grayscale hover:scale-110',
                      ]"
                      title="Explore"
                      @click="toggleProgramNew(2)"
                    />
                    <img
                      :src="programLogoSrc('C')"
                      :alt="programLogoAlt('C')"
                      :class="[
                        'w-8 h-8 transition-all cursor-pointer',
                        newFirstProgram === 3 || newFirstProgram === 0
                          ? 'opacity-100 hover:scale-110'
                          : 'opacity-30 grayscale hover:scale-110',
                      ]"
                      title="Challenge"
                      @click="toggleProgramNew(3)"
                    />
                  </div>
                </div>
                <p class="text-xs text-gray-500">
                  Klick außerhalb oder Enter legt den Block an.
                </p>
              </div>
            </transition>
          </div>
        </div>
      </div>

      <div
        class="lg:col-span-2 min-w-0"
        :class="selectedBlock && !selectedBlock.active ? 'opacity-60' : ''"
      >
        <div class="mb-3 md:mb-4 border-b border-gray-200 pb-2">
          <h2 class="text-base md:text-xl font-bold text-gray-900">
            {{ selectedBlock ? selectedBlock.name : 'Teams' }}
          </h2>
          <p v-if="selectedBlock" class="text-xs md:text-sm text-gray-500 font-normal mt-1">
            <template v-if="!selectedBlock.active">Block ist inaktiv — Startzeiten nicht editierbar.</template>
            <template v-else>Startzeit pro Team (team_plan) — nur Start editierbar</template>
          </p>
        </div>

        <template v-if="selectedBlock">
          <div v-if="loadingTeams" class="flex items-center gap-2 text-gray-500 py-8">
            <LoaderFlow class="scale-75"/>
            <span class="text-sm">Lade Teams…</span>
          </div>
          <div v-else class="overflow-x-auto border rounded bg-white shadow-sm">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="text-left px-3 py-2 font-medium text-gray-700">Start</th>
                  <th class="text-center px-2 py-2 w-12"></th>
                  <th class="text-left px-3 py-2 font-medium text-gray-700">Plan-Nr.</th>
                  <th class="text-left px-3 py-2 font-medium text-gray-700">HoT-ID</th>
                  <th class="text-left px-3 py-2 font-medium text-gray-700">Team</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in teams"
                  :key="row.team_id"
                  class="border-b border-gray-100 hover:bg-gray-50/80"
                >
                  <td class="px-3 py-2 align-middle">
                    <input
                      type="datetime-local"
                      :disabled="!selectedBlock.active"
                      class="text-sm border-b border-gray-300 bg-transparent py-0.5 w-[180px] max-w-full focus:outline-none focus:border-blue-500 disabled:text-gray-400 disabled:cursor-not-allowed"
                      :value="toDatetimeLocal(row.start)"
                      @change="onTeamStartChange(row, ($event.target as HTMLInputElement).value)"
                    />
                  </td>
                  <td class="px-2 py-2 text-center">
                    <img
                      :src="programIcon(row.first_program).src"
                      :alt="programIcon(row.first_program).alt"
                      class="w-6 h-6 mx-auto"
                    />
                  </td>
                  <td class="px-3 py-2 text-gray-800">{{ row.team_number_plan ?? '–' }}</td>
                  <td class="px-3 py-2 text-gray-800">{{ row.team_number_hot ?? '–' }}</td>
                  <td class="px-3 py-2 text-gray-900">{{ row.team_name }}</td>
                </tr>
              </tbody>
            </table>
            <p v-if="!teams.length" class="p-6 text-sm text-gray-500 text-center">
              Keine Teams im Plan für diesen Slot-Typ.
            </p>
          </div>
        </template>
        <p v-else class="text-sm text-gray-500">Links einen Slot-Block auswählen.</p>
      </div>
    </div>

    <ConfirmationModal
      :show="!!blockToDelete"
      type="danger"
      title="Slot-Block löschen?"
      :message="blockToDelete ? `„${blockToDelete.name}“ und alle Team-Zeiten dazu werden gelöscht.` : ''"
      confirm-text="Löschen"
      cancel-text="Abbrechen"
      @confirm="confirmDelete"
      @cancel="blockToDelete = null"
    />
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
