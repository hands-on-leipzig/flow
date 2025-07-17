<script setup>
import draggable from 'vuedraggable'
import {computed, toRef, ref, watch} from "vue";
import axios from "axios";
import {useEventStore} from "@/stores/event";
import IconDraggable from "@/components/icons/IconDraggable.vue";

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

// Accept props
const props = defineProps({
  program: {type: String, required: true}, // 'explore' or 'challenge'
  teams: {type: Array, required: true}
})

// Local copy of teams for reactivity and drag state
const teamList = ref([...props.teams])

// Watch for prop updates
watch(() => props.teams, (newVal) => {
  teamList.value = [...newVal]
})

const syncTeams = async () => {
  await axios.post(`/events/${event.value?.id}/teams/sync`, {
    program: props.program
  })
  location.reload()
}

const onSort = async () => {
  const payload = teamList.value.map((team, index) => ({
    team_id: team.id,
    order: index + 1
  }))

  try {
    await axios.post(`/events/${event.value?.id}/teams/update-order`, {
      program: props.program,
      order: payload
    })
  } catch (e) {
    console.error('Order update failed', e)
  }
}

const updateTeamName = async (team) => {
  try {
    await axios.put(`/events/${event.value?.id}/teams/${team.id}`, {
      name: team.name,
      program: props.program
    })
  } catch (e) {
    console.error(`Failed to update team name for ${team.id}`, e)
  }
}

const colour = props.program === 'explore' ? 'green' : 'red';
</script>

<template>
  <div class="p-4 border rounded shadow">
    <div class="flex justify-between items-center mb-2">
      <h3 class="text-lg font-semibold capitalize">{{ program }}</h3>
      <div class="flex gap-2">
        <button class="text-sm text-blue-600" @click="syncTeams">Teamdaten aktualisieren</button>
        <button class="text-sm text-blue-600">XML exportieren</button>
      </div>
    </div>
    <draggable
        v-model="teamList"
        item-key="id"
        handle=".drag-handle"
        @end="onSort"
        ghost-class="drag-ghost"
        chosen-class="drag-chosen"
        drag-class="drag-dragging"
        animation="150"
    >
      <template #item="{element: team}">
        <li
            :class="'bg-'+colour+'-100 rounded px-3 py-1 mb-1 flex justify-between items-center gap-2'"
        >
          <span class="text-sm">{{ team.number }}</span>
          <input
              v-model="team.name"
              @blur="updateTeamName(team)"
              :class="'flex-1 bg-transparent border-b border-'+colour+'-300 focus:outline-none text-sm'"
          />
          <span class="drag-handle cursor-move text-gray-500"><IconDraggable/></span>
        </li>
      </template>
    </draggable>
  </div>
</template>
<style scoped>
.drag-ghost {
  opacity: 0.4;
  transform: scale(0.98);
}

.drag-chosen {
  background-color: #fde68a; /* yellow-200 */
  box-shadow: 0 0 0 2px #facc15; /* yellow-400 */
}

.drag-dragging {
  cursor: grabbing;
}
</style>

