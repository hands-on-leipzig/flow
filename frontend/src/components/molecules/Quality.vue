<script setup>
import { ref, computed } from 'vue'
import axios from 'axios'
import QRunConfigForm from '@/components/atoms/QRunConfigForm.vue'
import QRunList from '@/components/atoms/QRunList.vue'

const reload = ref(0)

const minTeams = ref(4)
const maxTeams = ref(25)

const juryLanes = ref({
  lane_1: true,
  lane_2: true,
  lane_3: true,
  lane_4: true,
  lane_5: true,
})

const tables = ref({
  tables_2: true,
  tables_4: true,
})

const juryRounds = ref({
  rounds_4: true,
  rounds_5: true,
  rounds_6: true,
})

const robotCheck = ref(1)  

const qrunName = ref('')
const qrunComment = ref('')

const isValid = computed(() => {
  const atLeastOneLane = Object.values(juryLanes.value).some(v => v)
  const atLeastOneTable = Object.values(tables.value).some(v => v)
  const atLeastOneRound = Object.values(juryRounds.value).some(v => v)
  const validTeamRange = minTeams.value >= 4 && maxTeams.value <= 25 && minTeams.value <= maxTeams.value
  const hasName = qrunName.value.trim().length > 0
  return atLeastOneLane && atLeastOneTable && atLeastOneRound && validTeamRange && hasName
})

const startVolumeTest = () => {
  const selection = {
    min_teams: minTeams.value,
    max_teams: maxTeams.value,
    jury_lanes: Object.entries(juryLanes.value)
      .filter(([_, v]) => v)
      .map(([k]) => Number(k.split('_')[1])),
    tables: Object.entries(tables.value)
      .filter(([_, v]) => v)
      .map(([k]) => Number(k.split('_')[1])),
    jury_rounds: Object.entries(juryRounds.value)
      .filter(([_, v]) => v)
      .map(([k]) => Number(k.split('_')[1])),
    robot_check: robotCheck.value,
  }

  const payload = {
    name: qrunName.value.trim(),
    comment: qrunComment.value.trim(),
    selection,
  }

  axios.post('/quality/qrun', payload)
    .then(() => {
      reload.value++  
      qrunName.value = ''
      qrunComment.value = ''
    })
    .catch(error => {
      if (error.response) {
        const status = error.response.status
        const data = error.response.data

        if (status === 429 && data.error) {
          alert(data.error)
        } else {
          console.error('Backend-Antwort:', status, data)
          alert('Ein Fehler ist aufgetreten. Bitte prüfe die Eingaben.')
        }
      } else {
        console.error('Netzwerk-Fehler:', error)
        alert('Keine Verbindung zum Server.')
      }
    })
}
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <!-- Eingabebereich: bleibt oben fix -->
    <div class="sticky top-0 z-10 bg-white border-b p-4">
      <QRunConfigForm
        v-model:min-teams="minTeams"
        v-model:max-teams="maxTeams"
        v-model:jury-lanes="juryLanes"
        v-model:tables="tables"
        v-model:jury-rounds="juryRounds"
        v-model:qrun-name="qrunName"
        v-model:qrun-comment="qrunComment"
        :is-valid="isValid"
        @start="startVolumeTest"
        @refresh="reload++" 
      />
    </div>

    <!-- Scrollbarer Bereich darunter -->
    <div class="flex-1 overflow-y-auto p-4">
      <QRunList :reload="reload" />
    </div>
  </div>
</template>