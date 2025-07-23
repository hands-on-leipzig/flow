<script setup>
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import ParameterField from "@/components/ParameterField.vue";

import {useEventStore} from '@/stores/event'
import AccordionArrow from "@/components/icons/IconAccordionArrow.vue";
import LoaderFlow from "@/components/atoms/LoaderFlow.vue";
import LoaderText from "@/components/LoaderText.vue";

const eventStore = useEventStore()
const selectedEvent = computed(() => eventStore.selectedEvent)
const parameters = ref([])
const scheduleUrl = ref('')
const inputName = ref('')
const plans = ref([])
const selectedPlanId = ref(null)
const previewRef = ref(null)
const loading = ref(true)

watch(selectedPlanId, async (newPlanId) => {
  if (!newPlanId) return
  await fetchParams(newPlanId)
  updateScheduleUrl(newPlanId)
})

const fetchParams = async (planId) => {
  if (!planId) return
  try {
    const {data} = await axios.get(`/plans/${planId}/parameters`)
    parameters.value = data
  } finally {
    loading.value = false
  }

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
  loading.value = true
  try {
    await axios.post(`/plans/${selectedPlanId?.value}/parameters`, {
      id: param.id,
      value: typeof param.value == 'string' ? param.value : param.value.toString(),
    });
    updateScheduleUrl(selectedPlanId.value)
  } catch (error) {
    console.error('Error updating parameter:', error);
  } finally {
    loading.value = false
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
  const res = await axios.get(`/events/${selectedEvent.value.id}/plans`)
  plans.value = res.data
  if (plans.value.length > 0) {
    selectedPlanId.value = plans.value[0].id
    await fetchParams(selectedPlanId.value)
    updateScheduleUrl(selectedPlanId.value)
  } else {
    const newPlanId = await createDefaultPlan()
    if (newPlanId) {
      const newPlan = {
        id: newPlanId,
        name: 'Standard-Zeitplan',
        is_chosen: true
      }
      plans.value.push(newPlan)
      selectedPlanId.value = newPlanId
      await fetchParams(newPlanId)
      updateScheduleUrl(newPlanId)
    }
  }
}

const createDefaultPlan = async () => {
  try {
    const response = await axios.post(`/plans`, {
      event: selectedEvent.value.id,
      name: 'Zeitplan'
    })
    return response.data.id
  } catch (e) {
    console.error('Fehler beim Erstellen des Plans', e)
    return null
  }
}


onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }

  if (!selectedEvent.value) {
    console.error('No selected event could be loaded.')
    return
  }

  await fetchPlans()
})

const openGroup = ref(null)
const toggle = (id) => {
  openGroup.value = openGroup.value === id ? null : id
}
</script>

<template>
  <div class="h-screen p-6 flex flex-col space-y-5">

    <div v-if="false" class="flex items-center space-x-4">
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
    </div>

    <div
        v-for="(group, programName) in inputParamsByProgram"
        :key="programName"
        class="bg-white border rounded-lg shadow"
    >
      <button
          class="w-full text-left px-4 py-2 bg-gray-100 font-semibold text-black uppercase flex justify-between items-center"
          @click="toggle(programName)"
      >
        {{ 'Parameter ' + programName }}
        <span>
          <AccordionArrow :opened="openGroup === programName"/>
        </span>
      </button>
      <transition name="fade">
        <div v-if="openGroup === programName" class="p-4 space-y-2">
          <ParameterField
              v-for="param in group"
              :key="param.id"
              :param="param"
              @update="updateParam"
          />
        </div>
      </transition>
    </div>


    <div class="mb-4 bg-white border rounded-lg shadow">
      <button
          class="w-full text-left px-4 py-2 bg-gray-100 font-semibold text-black uppercase flex justify-between items-center"
          @click="toggle('expert')"
      >
        Expertenparameter
        <AccordionArrow :opened="openGroup === 'expert'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'expert'" class="p-4">
          <div class="grid grid-cols-2 gap-6 max-h-[600px] overflow-y-auto">
            <div
                v-for="(group, programName) in expertParamsGrouped"
                :key="programName"
            >
              <h4 class="text-md font-semibold mb-2">{{ programName }}</h4>
              <ParameterField
                  v-for="param in group"
                  :key="param.id"
                  :param="param"
                  @update="updateParam"
              />
            </div>
          </div>
        </div>
      </transition>
    </div>

    <div class="mb-4 bg-white border rounded-lg shadow" v-if="selectedEvent?.value?.level === 3">
      <button
          class="w-full text-left px-4 py-2 bg-gray-100 font-semibold text-black uppercase flex justify-between items-center"
          @click="toggle('finals')"
      >
        Finalparameter
        <AccordionArrow :opened="openGroup === 'finals'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'finals'" class="p-4">
          <div class="grid grid-cols-2 gap-6 max-h-[600px] overflow-y-auto">
            <ParameterField v-for="param in finaleParams" :key="param.id" :param="param" @update="updateParam"/>
          </div>
        </div>
      </transition>
    </div>

    <div class="flex-grow overflow-hidden">
      <div v-if="loading" class="flex flex-col justify-center items-center h-64 space-y-4">
        <LoaderFlow/>
        <LoaderText/>
      </div>
      <object
          v-else
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

.fade-enter-active, .fade-leave-active {
  transition: all 0.2s ease;
}

.fade-enter-from, .fade-leave-to {
  opacity: 0;
  transform: translateY(-0.5rem);
}

</style>
