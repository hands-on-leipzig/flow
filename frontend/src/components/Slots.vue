<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import {useEventStore} from '@/stores/event'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import LoaderText from '@/components/atoms/LoaderText.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import {programLogoSrc, programLogoAlt} from '@/utils/images'

type SlotBlock = {
  id: number
  name: string
  description: string
  link: string
  duration: number
  for_explore: boolean
  for_challenge: boolean
}

type TeamRow = {
  team_id: number
  team_number_plan: number | null
  team_number_hot: string | null
  team_name: string
  first_program: number
  start: string | null
}

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const planId = ref<number | null>(null)
const loading = ref(true)
const blocks = ref<SlotBlock[]>([])
const draftBlock = ref<SlotBlock | null>(null)
const selectedId = ref<number | null>(null)
const teams = ref<TeamRow[]>([])
const loadingTeams = ref(false)
const savingBlockId = ref<number | null>(null)
const blockToDelete = ref<SlotBlock | null>(null)
const errorMsg = ref<string | null>(null)

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
  const {data} = await axios.get<SlotBlock[]>(`/plans/${planId.value}/slot-blocks`)
  blocks.value = (Array.isArray(data) ? data : []).map((b) => ({
    ...b,
    description: b.description ?? '',
    link: b.link ?? '',
  }))
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

onMounted(async () => {
  loading.value = true
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  await loadPlan()
  if (planId.value) await loadBlocks()
  await loadTeams()
  loading.value = false
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

function startDraft() {
  draftBlock.value = {
    id: -1,
    name: '',
    description: '',
    link: '',
    duration: 30,
    for_explore: true,
    for_challenge: true,
  }
  selectedId.value = null
  teams.value = []
  errorMsg.value = null
}

function cancelDraft() {
  draftBlock.value = null
  errorMsg.value = null
  if (!selectedId.value && blocks.value.length) selectedId.value = blocks.value[0].id
}

function canCreateDraft(b: SlotBlock) {
  const nameOk = (b.name || '').trim().length > 0
  const flagsOk = !!b.for_explore || !!b.for_challenge
  const durOk = Number(b.duration) >= 1
  return nameOk && flagsOk && durOk
}

async function maybeCreateDraft() {
  const b = draftBlock.value
  if (!b || !planId.value) return
  if (!canCreateDraft(b)) return

  errorMsg.value = null
  try {
    const {data} = await axios.post<SlotBlock>(`/plans/${planId.value}/slot-blocks`, {
      name: b.name.trim(),
      description: b.description || null,
      link: b.link || null,
      duration: Math.max(1, Number(b.duration) || 1),
      for_explore: !!b.for_explore,
      for_challenge: !!b.for_challenge,
    })
    draftBlock.value = null
    await loadBlocks()
    selectedId.value = data.id
  } catch (e: any) {
    const d = e?.response?.data
    errorMsg.value =
      (d?.errors && Object.values(d.errors).flat().join(' ')) ||
      d?.message ||
      e?.message ||
      'Anlegen fehlgeschlagen'
  }
}

async function patchBlock(block: SlotBlock, patch: Partial<SlotBlock>) {
  if (!planId.value) return
  savingBlockId.value = block.id
  errorMsg.value = null
  try {
    const {data} = await axios.put<SlotBlock>(
      `/plans/${planId.value}/slot-blocks/${block.id}`,
      patch
    )
    const i = blocks.value.findIndex((b) => b.id === block.id)
    if (i >= 0) {
      blocks.value[i] = {
        ...blocks.value[i],
        ...data,
        description: data.description ?? '',
        link: data.link ?? '',
      }
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

</script>

<template>
  <div v-if="loading" class="flex items-center justify-center h-full flex-col text-gray-600 min-h-[400px]">
    <LoaderFlow/>
    <LoaderText/>
  </div>
  <div v-else-if="!planId" class="p-6 text-gray-600">Kein Plan für diese Veranstaltung.</div>
  <div v-else class="flex flex-col lg:flex-row mt-4 min-h-[480px] rounded-xl border border-gray-200 bg-white overflow-hidden">
    <!-- Left: ~1/3 slot block maintenance -->
    <div class="w-full lg:w-1/3 flex flex-col min-w-0 bg-gray-50/60">
      <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-gray-200 bg-white">
        <h2 class="text-base font-semibold text-gray-900">Slot-Blöcke</h2>
        <button
          type="button"
          class="px-3 py-1.5 text-sm font-medium rounded-md bg-gray-900 text-white hover:bg-gray-800"
          @click="startDraft"
        >
          + Neu
        </button>
      </div>
      <p v-if="errorMsg" class="text-sm text-red-600">{{ errorMsg }}</p>
      <div class="flex flex-col gap-3 overflow-y-auto max-h-[calc(100vh-240px)] px-4 py-3">
        <div
          v-if="draftBlock"
          class="rounded-lg border-2 border-dashed border-gray-300 bg-white shadow-sm p-4"
        >
          <div class="flex justify-between items-start gap-2 mb-2">
            <input
              v-model="draftBlock.name"
              class="flex-1 min-w-0 text-sm font-medium border border-gray-200 rounded px-2 py-1"
              placeholder="Name"
              @blur="maybeCreateDraft"
            />
            <div class="flex items-center gap-1 flex-shrink-0">
              <button
                type="button"
                class="px-2 py-1 text-xs font-medium rounded border border-gray-200 text-gray-700 hover:bg-gray-50"
                title="Abbrechen"
                @click="cancelDraft"
              >
                Abbrechen
              </button>
            </div>
          </div>
          <textarea
            v-model="draftBlock.description"
            rows="2"
            class="w-full text-xs border border-gray-200 rounded px-2 py-1 mb-2"
            placeholder="Beschreibung"
            @blur="maybeCreateDraft"
          />
          <input
            v-model="draftBlock.link"
            type="url"
            class="w-full text-xs border border-gray-200 rounded px-2 py-1 mb-2"
            placeholder="Link"
            @blur="maybeCreateDraft"
          />
          <div class="flex items-center gap-2 mb-2">
            <label class="text-xs text-gray-600 whitespace-nowrap">Dauer (Min.)</label>
            <input
              v-model.number="draftBlock.duration"
              type="number"
              min="1"
              class="w-20 text-sm border border-gray-200 rounded px-2 py-1"
              @change="maybeCreateDraft"
            />
          </div>
          <div class="flex flex-wrap gap-4 text-xs">
            <div class="flex items-center gap-2">
              <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-5 h-5"/>
              <ToggleSwitch
                :model-value="draftBlock.for_explore"
                @update:model-value="
                  (v: boolean) => {
                    draftBlock!.for_explore = v
                    maybeCreateDraft()
                  }
                "
              />
            </div>
            <div class="flex items-center gap-2">
              <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-5 h-5"/>
              <ToggleSwitch
                :model-value="draftBlock.for_challenge"
                @update:model-value="
                  (v: boolean) => {
                    draftBlock!.for_challenge = v
                    maybeCreateDraft()
                  }
                "
              />
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            Wird automatisch angelegt, sobald Name gesetzt ist (und E oder C aktiv ist).
          </p>
        </div>
        <div
          v-for="block in blocks"
          :key="block.id"
          class="rounded-lg border shadow-sm p-4 cursor-pointer transition-colors"
          :class="
            selectedId === block.id
              ? 'border-blue-500 bg-blue-50/50 ring-1 ring-blue-200'
              : 'border-gray-200 bg-white hover:border-gray-300'
          "
          @click="selectedId = block.id"
        >
          <div class="flex justify-between items-start gap-2 mb-2">
            <input
              v-model="block.name"
              class="flex-1 min-w-0 text-sm font-medium border border-gray-200 rounded px-2 py-1"
              placeholder="Name"
              @click.stop
              @blur="patchBlock(block, { name: block.name.trim() })"
            />
            <button
              type="button"
              class="p-1 text-gray-400 hover:text-red-600 flex-shrink-0"
              title="Löschen"
              @click.stop="blockToDelete = block"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
            </button>
          </div>
          <textarea
            v-model="block.description"
            rows="2"
            class="w-full text-xs border border-gray-200 rounded px-2 py-1 mb-2"
            placeholder="Beschreibung"
            @click.stop
            @blur="patchBlock(block, { description: block.description || null })"
          />
          <input
            v-model="block.link"
            type="url"
            class="w-full text-xs border border-gray-200 rounded px-2 py-1 mb-2"
            placeholder="Link"
            @click.stop
            @blur="patchBlock(block, { link: block.link || null })"
          />
          <div class="flex items-center gap-2 mb-2">
            <label class="text-xs text-gray-600 whitespace-nowrap">Dauer (Min.)</label>
            <input
              v-model.number="block.duration"
              type="number"
              min="1"
              class="w-20 text-sm border border-gray-200 rounded px-2 py-1"
              @click.stop
              @change="patchBlock(block, { duration: Math.max(1, Number(block.duration) || 1) })"
            />
          </div>
          <div class="flex flex-wrap gap-4 text-xs" @click.stop>
            <div class="flex items-center gap-2">
              <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-5 h-5"/>
              <ToggleSwitch
                :model-value="block.for_explore"
                @update:model-value="
                  (v: boolean) => {
                    block.for_explore = v
                    patchBlock(block, { for_explore: v })
                  }
                "
              />
            </div>
            <div class="flex items-center gap-2">
              <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-5 h-5"/>
              <ToggleSwitch
                :model-value="block.for_challenge"
                @update:model-value="
                  (v: boolean) => {
                    block.for_challenge = v
                    patchBlock(block, { for_challenge: v })
                  }
                "
              />
            </div>
          </div>
          <div v-if="savingBlockId === block.id" class="text-xs text-gray-500 mt-2">Speichern…</div>
        </div>
        <p v-if="!blocks.length" class="text-sm text-gray-500">Noch keine Slot-Blöcke. „+ Neu“ zum Anlegen.</p>
      </div>
    </div>

    <!-- Right: ~2/3 team assignments -->
    <div class="w-full lg:flex-1 lg:min-w-0 border-t lg:border-t-0 lg:border-l-2 border-gray-200 bg-white px-4 py-3">
      <template v-if="selectedBlock">
        <h2 class="text-base font-semibold text-gray-900 mb-1">{{ selectedBlock.name }}</h2>
        <p class="text-sm text-gray-500 mb-4">Startzeit pro Team (aus team_plan)</p>
        <div v-if="loadingTeams" class="flex items-center gap-2 text-gray-500 py-8">
          <LoaderFlow class="scale-75"/>
          <span class="text-sm">Lade Teams…</span>
        </div>
        <div v-else class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
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
                    class="border border-gray-200 rounded px-2 py-1 text-sm w-[180px] max-w-full"
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
          <p v-if="!teams.length" class="p-6 text-sm text-gray-500 text-center">Keine Teams im Plan für diesen Slot-Typ.</p>
        </div>
      </template>
      <p v-else class="text-gray-500 text-sm">Links einen Slot-Block wählen.</p>
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
