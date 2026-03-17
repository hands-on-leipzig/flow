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
const newForExplore = ref(true)
const newForChallenge = ref(true)
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

function canCreateNewSlot() {
  const nameOk = newSlotName.value.trim().length > 0
  const flagsOk = newForExplore.value || newForChallenge.value
  const durOk = Number(newSlotDuration.value) >= 1
  return nameOk && flagsOk && durOk
}

async function createNewSlotBlock() {
  if (!planId.value || isCreatingSlot.value) return
  if (!canCreateNewSlot()) return

  isCreatingSlot.value = true
  isSavingNew.value = true
  errorMsg.value = null
  try {
    const {data} = await axios.post<SlotBlock>(`/plans/${planId.value}/slot-blocks`, {
      name: newSlotName.value.trim(),
      description: newSlotDescription.value.trim() || null,
      link: newSlotLink.value.trim() || null,
      duration: Math.max(1, Number(newSlotDuration.value) || 1),
      for_explore: newForExplore.value,
      for_challenge: newForChallenge.value,
    })
    newSlotName.value = ''
    newSlotDescription.value = ''
    newSlotLink.value = ''
    newSlotDuration.value = 30
    newForExplore.value = true
    newForChallenge.value = true
    await loadBlocks()
    selectedId.value = data.id
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
      <!-- Slot-Blöcke (~wie Räume links) -->
      <div class="lg:col-span-1 min-w-0">
        <h2 class="text-lg md:text-xl font-bold mb-3 md:mb-4">Slot-Blöcke</h2>
        <p v-if="errorMsg" class="text-sm text-red-600 mb-2">{{ errorMsg }}</p>

        <div class="space-y-3 md:space-y-4 max-h-[calc(100vh-200px)] overflow-y-auto pr-1">
          <div
            v-for="block in blocks"
            :key="block.id"
            class="p-3 md:p-4 mb-2 border rounded bg-white shadow-sm md:shadow transition-shadow cursor-pointer"
            :class="
              selectedId === block.id
                ? 'ring-2 ring-blue-500 border-blue-400 shadow-md'
                : 'hover:shadow-md'
            "
            @click="selectedId = block.id"
          >
            <div class="flex items-center gap-2 mb-2">
              <input
                v-model="block.name"
                :class="inputTitle"
                placeholder="Name"
                @click.stop
                @blur="patchBlock(block, { name: block.name.trim() })"
              />
              <button
                type="button"
                class="text-lg hover:text-red-800 flex-shrink-0"
                title="Slot-Block löschen"
                @click.stop="blockToDelete = block"
              >
                <i style="color: grey" class="bi bi-trash-fill"/>
              </button>
            </div>
            <input
              v-model="block.description"
              type="text"
              :class="inputUnderline + ' text-xs md:text-sm text-gray-700 mb-2'"
              placeholder="Beschreibung"
              @click.stop
              @blur="patchBlock(block, { description: block.description || null })"
            />
            <input
              v-model="block.link"
              type="url"
              :class="inputUnderline + ' text-xs md:text-sm text-gray-700 mb-2'"
              placeholder="Link (URL)"
              @click.stop
              @blur="patchBlock(block, { link: block.link || null })"
            />
            <div class="mb-2 flex flex-wrap items-center gap-3">
              <label class="text-xs text-gray-600 whitespace-nowrap">Dauer (Min.)</label>
              <input
                v-model.number="block.duration"
                type="number"
                min="1"
                class="w-16 text-sm border-b border-gray-300 focus:outline-none focus:border-blue-500"
                @click.stop
                @change="patchBlock(block, { duration: Math.max(1, Number(block.duration) || 1) })"
              />
              <div class="flex flex-wrap gap-4 text-xs items-center" @click.stop>
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
            </div>
            <div v-if="savingBlockId === block.id" class="text-xs text-gray-500">Speichern…</div>
          </div>

          <!-- Neuer Slot-Block (wie „Neuer Raum“) -->
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
                <div class="flex flex-wrap items-center gap-3">
                  <label class="text-xs text-gray-600">Dauer (Min.)</label>
                  <input
                    v-model.number="newSlotDuration"
                    :disabled="isSavingNew"
                    type="number"
                    min="1"
                    class="w-16 text-sm border-b border-gray-300 focus:outline-none focus:border-blue-500"
                  />
                </div>
                <div class="flex flex-wrap gap-4 text-xs items-center pt-1">
                  <div class="flex items-center gap-2">
                    <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-5 h-5"/>
                    <ToggleSwitch v-model="newForExplore"/>
                  </div>
                  <div class="flex items-center gap-2">
                    <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-5 h-5"/>
                    <ToggleSwitch v-model="newForChallenge"/>
                  </div>
                </div>
                <p class="text-xs text-gray-500">
                  Klick außerhalb oder Enter legt den Block an (mindestens Explore oder Challenge).
                </p>
              </div>
            </transition>
          </div>
        </div>
      </div>

      <!-- Teams (rechte Spalte, Typo wie Räume → Aktivitäten) -->
      <div class="lg:col-span-2 min-w-0">
        <div class="mb-3 md:mb-4 border-b border-gray-200 pb-2">
          <h2 class="text-base md:text-xl font-bold text-gray-900">
            {{ selectedBlock ? selectedBlock.name : 'Teams' }}
          </h2>
          <p v-if="selectedBlock" class="text-xs md:text-sm text-gray-500 font-normal mt-1">
            Startzeit pro Team (team_plan) — nur Start editierbar
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
                      class="text-sm border-b border-gray-300 bg-transparent py-0.5 w-[180px] max-w-full focus:outline-none focus:border-blue-500"
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
