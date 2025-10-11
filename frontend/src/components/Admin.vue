<script setup>
import {ref, watch} from 'vue'
import axios from 'axios'
import Multiselect from '@vueform/multiselect'
import Quality from '@/components/molecules/Quality.vue'
import Statistics from '@/components/molecules/Statistics.vue'
import MParameter from '@/components/molecules/MParameter.vue'
import NowAndNext from '@/components/molecules/NowAndNext.vue'
import UserRegionalPartnerRelations from '@/components/molecules/UserRegionalPartnerRelations.vue'
import MainTablesAdmin from '@/components/molecules/MainTablesAdmin.vue'
import '@vueform/multiselect/themes/default.css'

const activeTab = ref('statistics')

const parameters = ref([])
const conditions = ref([])

const syncDrahtRegions = async () => {
  await axios.get('/admin/draht/sync-draht-regions')
}

const syncDrahtEvents = async () => {
  await axios.get('/admin/draht/sync-draht-events/2')
}

const fetchParameters = async () => {
  const {data} = await axios.get('/parameter')
  parameters.value = data
  console.log(parameters.value)
}

const fetchConditions = async () => {
  const {data} = await axios.get('/parameter/condition')
  conditions.value = data
}

const addCondition = async () => {
  conditions.value.push({
    parameter: '',
    if_parameter: '',
    is: '=',
    value: '',
    action: 'hide',
    _new: true,
    _dirty: false,
  })
}

const removeCondition = async (index) => {
  const cond = conditions.value[index]
  if (cond.id) await axios.delete(`/parameter/condition/${cond.id}`)
  conditions.value.splice(index, 1)
}

watch(conditions, async (newVal) => {
  for (const cond of newVal) {
    if (cond._dirty) {
      if (cond._new) {
        const {data} = await axios.post('/parameter/condition', cond)
        Object.assign(cond, data)
        cond._new = false
      } else if (cond.id) {
        await axios.put(`/parameter/condition/${cond.id}`, cond)
      }
      cond._dirty = false
    }
  }
}, {deep: true})


fetchParameters()
fetchConditions()
</script>

<template>
  <div class="flex h-full min-h-screen">
    <!-- Sidebar -->
    <div class="w-64 bg-gray-100 border-r p-4 space-y-2">

      <button
        class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
        :class="{ 'bg-white font-semibold shadow': activeTab === 'statistics' }"
        @click="activeTab = 'statistics'"
      >
        📊 Statistiken
      </button>

            <button
        class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
        :class="{ 'bg-white font-semibold shadow': activeTab === 'main-tables' }"
        @click="activeTab = 'main-tables'"
      >
        📝 Main Tables
      </button>


      <button
        class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
        :class="{ 'bg-white font-semibold shadow': activeTab === 'nowandnext' }"
        @click="activeTab = 'nowandnext'"
      >
        ⏰ Now and Next
      </button>

      <button
          class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
          :class="{ 'bg-white font-semibold shadow': activeTab === 'volumetest' }"
          @click="activeTab = 'quality'">
        📈 Plan-Qualität
      </button>

      <button
          class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
          :class="{ 'bg-white font-semibold shadow': activeTab === 'conditions' }"
          @click="activeTab = 'conditions'"
      >
        📄 Parameter-Anzeige
      </button>
      <button
          class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
          :class="{ 'bg-white font-semibold shadow': activeTab === 'user-regional-partners' }"
          @click="activeTab = 'user-regional-partners'"
      >
        👥 User-Regional Partner Relations
      </button>

      <button
          class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
          :class="{ 'bg-white font-semibold shadow': activeTab === 'sync' }"
          @click="activeTab = 'sync'"
      >
        🔁 Draht Sync
      </button>







    </div>

    <div class="flex-1 p-6 overflow-auto">


      <div v-if="activeTab === 'conditions'">
        <h2 class="text-xl font-bold mb-4">Parameter-Anzeige-Bedingungen</h2>
        <div
            v-for="(cond, index) in conditions"
            :key="cond.id || index"
            class="flex items-center justify-center gap-4 px-3 py-2 rounded bg-white hover:bg-gray-200"
        >
          <Multiselect
              v-model="cond.parameter"
              :options="parameters"
              label="name"
              track-by="name"
              valueProp="id"
              searchable
              placeholder="Parameter"
              class="min-w-[12rem]"
              @update:modelValue="cond._dirty = true"
          />

          <select v-model="cond.action" class="border px-2 py-1 rounded" @change="cond._dirty = true">
            <option value="show">anzeigen</option>
            <option value="hide">verstecken</option>
            <option value="disable">ausgrauen</option>
          </select>

          <span>wenn</span>

          <Multiselect
              v-model="cond.if_parameter"
              :options="parameters"
              label="name"
              track-by="name"
              valueProp="id"
              searchable
              placeholder="Wenn-Parameter"
              class="min-w-[12rem]"
              @update:modelValue="cond._dirty = true"
          />

          <select v-model="cond.is" class="border px-2 py-1 rounded" @change="cond._dirty = true">
            <option value="=">=</option>
            <option value="<">&lt;</option>
            <option value=">">&gt;</option>
          </select>

          <input v-model="cond.value" class="border px-2 py-1 rounded" placeholder="Wert"
                 @change="cond._dirty = true"/>

          <button class="text-red-500 text-lg" @click="removeCondition(index)" @update:modelValue="cond._dirty = true">
            🗑
          </button>
        </div>

        <button class="px-4 py-2 rounded bg-green-500 text-white" @click="addCondition">
          ➕ Bedingung hinzufügen
        </button>
      </div>


      <div v-if="activeTab === 'user-regional-partners'">
        <h2 class="text-xl font-bold mb-4">User-Regional Partner Relations</h2>
        <UserRegionalPartnerRelations />
      </div>

      <div v-if="activeTab === 'sync'">
        <h2 class="text-xl font-bold mb-4">Sync aus DRAHT</h2>
        <button class="px-4 py-2 rounded bg-blue-500 text-white mr-2" @click="syncDrahtRegions">
          Sync draht-regions
        </button>
        <button class="px-4 py-2 rounded bg-blue-500 text-white" @click="syncDrahtEvents">
          Sync Draht
        </button>
      </div>

      <div v-if="activeTab === 'quality'">
        <h2 class="text-xl font-bold mb-4">Plan-Qualität</h2>
        <quality />
      </div>


      <div v-if="activeTab === 'main-tables'">
        <MainTablesAdmin />
      </div>

      <div v-if="activeTab === 'mparameter'">
        <h2 class="text-xl font-bold mb-4">Tabelle m_parameter (Legacy)</h2>
        <MParameter />
      </div>
      
      <div v-if="activeTab === 'nowandnext'">
        <h2 class="text-xl font-bold mb-4">Was passiert gerade? Und was als nächstes?</h2>
        <NowAndNext />
      </div>

      <div v-if="activeTab === 'statistics'">
        <h2 class="text-xl font-bold mb-4">Statistiken</h2>
        <statistics />
      </div>

    </div>
  </div>
</template>

<style scoped>
button:focus {
  outline: none;
}
</style>
