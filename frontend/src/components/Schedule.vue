<script setup>
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import ParameterField from "@/components/ParameterField.vue";

import {useEventStore} from '@/stores/event'

const eventStore = useEventStore()


onMounted(() => {
  eventStore.fetchSelectedEvent()
})
const selectedEvent = computed(() => eventStore.selectedEvent)
const parameters = ref([])
const scheduleUrl = ref('')
const inputName = ref('')
const plans = ref([])
const selectedPlanId = ref(null)
const previewRef = ref(null)

watch(selectedPlanId, async (newPlanId) => {
  if (!newPlanId) return
  await fetchParams(newPlanId)
  updateScheduleUrl(newPlanId)
})

const fetchParams = async (planId) => {
  if (!planId) return
  const {data} = await axios.get(`/plans/${planId}/parameters`)
  parameters.value = data
}

function updateScheduleUrl(planId) {
  scheduleUrl.value = `https://dev.planning.hands-on-technology.org/event/${selectedEvent.value?.id}/schedule/${planId}/show?ts=${Date.now()}`
}

const inputParamsByProgram = computed(() => {
  return parameters.value
      .filter(p => p.context === 'input')
      .reduce((groups, param) => {
        const key = param.program_name || 'allgemein'
        if (!groups[key]) groups[key] = []
        groups[key].push(param)
        return groups
      }, {})
})

const expertParams = computed(() =>
    parameters.value.filter(p => p.context === 'expert')
        .sort((a, b) => (a.first_program || 0) - (b.first_program || 0))
)

const finaleParams = computed(() =>
    parameters.value.filter(p => p.context === 'finale')
)

const updateParam = async (param) => {
  try {
    await axios.post(`/plans/${selectedPlanId?.value}/parameters`, {
      id: param.id,
      value: typeof param.value == 'string' ? param.value : param.value.toString(),
    });
    updateScheduleUrl(selectedPlanId.value)
  } catch (error) {
    console.error('Error updating parameter:', error);
  }
}

const expertParamsGrouped = computed(() => {
  return parameters.value
      .filter(p => p.context === 'expert')
      .reduce((acc, param) => {
        const key = param.program_name || 'Unassigned'
        if (!acc[key]) acc[key] = []
        acc[key].push(param)
        return acc
      }, {})
})

async function fetchPlans() {
  if (!selectedEvent.value) return
  const res = await axios.get(`/api/events/${selectedEvent?.value.id}/plans`)
  plans.value = res.data
  if (plans.value.length > 0) {
    selectedPlanId.value = plans.value[0].id
    await fetchParams(selectedPlanId.value)
    updateScheduleUrl(selectedPlanId.value)
  }
}

onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }

  if (!eventStore.selectedEvent) {
    console.error('No selected event could be loaded.')
    return
  }

  await fetchPlans()
})
</script>

<template>
  <div class="h-screen p-6 flex flex-col space-y-5">

    <div class="flex items-center space-x-4">
      <label for="plan-select" class="text-sm font-medium">Plan auswählen:</label>
      <select
          id="plan-select"
          v-model="selectedPlanId"
          class="border rounded px-2 py-1 text-sm"
      >
        <option
            v-for="plan in plans"
            :key="plan.id"
            :value="plan.id"
        >
          {{ plan.name }}
        </option>
      </select>

      <label for="name" class="block text-sm font-medium text-gray-700 whitespace-nowrap">Planname</label>
      <input v-model="inputName" id="name"
             class="border border-gray-300 rounded px-5 py-2 focus:outline-none"
             type="text"/>
      <!--// mt-1 w-full border p-2 rounded
      // border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring focus:border-0-->
    </div>

    <div v-for="(group, programId) in inputParamsByProgram" :key="programId">
      <details class="bg-white rounded shadow">
        <summary class="cursor-pointer px-4 py-2 bg-gray-200 text-sm font-medium uppercase">
          {{ 'Input Parameters - Program ' + programId }}
        </summary>
        <div class="p-4">
          <ParameterField v-for="param in group" :key="param.id" :param="param" @update="updateParam"/>
        </div>
      </details>
    </div>

    <details class="bg-white rounded shadow">
      <summary class="cursor-pointer px-4 py-2 bg-gray-200 text-sm font-medium uppercase">
        Expertenparameter
      </summary>
      <div class="grid grid-cols-2 gap-6 max-h-[600px] overflow-y-auto px-4">
        <div v-for="(group, programName) in expertParamsGrouped" :key="programName" class="">
          <h4 class="text-md font-semibold mb-2">{{ programName }}</h4>
          <div class="grid grid-cols-1 gap-2">
            <ParameterField
                v-for="param in group"
                :key="param.id"
                :param="param"
                @update="updateParam"
            />
          </div>
        </div>
      </div>
    </details>

    <details class=" bg-white rounded shadow" v-if="selectedEvent?.value?.level === 3">
      <summary class="cursor-pointer px-4 py-2 bg-gray-200 text-sm font-medium uppercase">
        Finalparameter
      </summary>
      <div class="p-4 space-y-2">
        <ParameterField v-for="param in finaleParams" :key="param.id" :param="param" @update="updateParam"/>
      </div>
    </details>

    <div class="flex-grow overflow-hidden">
      <object
          ref="previewRef"
          :data="scheduleUrl"
          class="w-full h-full border rounded shadow"
          type="text/html"
      ></object>
    </div>
  </div>
</template>

<style scoped>
details[open] summary::after {
  content: '▲';
  float: right;
}

summary::after {
  content: '▼';
  float: right;
}
</style>
