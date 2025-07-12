<script setup>
import {ref, onMounted} from 'vue'
import axios from 'axios'
import {useRouter} from 'vue-router'
import {useEventStore} from '@/stores/event'
import dayjs from "dayjs";

const regionalPartners = ref([])
const eventStore = useEventStore()
const router = useRouter()

onMounted(async () => {
  const {data} = await axios.get('/events/selectable')
  regionalPartners.value = data
})

async function selectEvent(eventId) {
  await axios.post('/user/select-event', {event_id: eventId})
  await eventStore.fetchSelectedEvent()
  router.push('/schedule')
}
</script>

<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-4">Wettbewerb wählen</h1>

    <div v-for="rp in regionalPartners" :key="rp.regional_partner.id" class="mb-6">
      <h2 class="text-xl font-semibold">{{ rp.regional_partner.name }}</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-2">
        <div
            v-for="event in rp.events"
            :key="event.id"
            class="p-4 bg-white shadow rounded hover:bg-gray-100 cursor-pointer"
            @click="selectEvent(event.id)"
        >
          <h3 class="font-medium text-lg">{{ event.name }}</h3>
          <p class="text-sm text-gray-500">{{ dayjs(event.date).format('dddd, DD.MM.YYYY') }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
<style scoped>
</style>

