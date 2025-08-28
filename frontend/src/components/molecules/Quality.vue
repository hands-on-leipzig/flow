<script setup>
import { ref, computed } from 'vue'
import axios from 'axios'

const minTeams = ref(8)
const maxTeams = ref(8)

const juryLanes = ref({
  lane_1: true,
  lane_2: true,
  lane_3: true,
  lane_4: true,
  lane_5: true,
})

const tables = ref({
  tables_2: true,
  tables_4: false,
})

const juryRounds = ref({
  rounds_4: true,
  rounds_5: true,
  rounds_6: true,
})

const runName = ref('')
const runComment = ref('')

const isValid = computed(() => {
  const atLeastOneLane = Object.values(juryLanes.value).some(v => v)
  const atLeastOneTable = Object.values(tables.value).some(v => v)
  const atLeastOneRound = Object.values(juryRounds.value).some(v => v)
  const validTeamRange = minTeams.value >= 4 && maxTeams.value <= 25 && minTeams.value <= maxTeams.value
  const hasName = runName.value.trim().length > 0
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
  }

  const payload = {
    name: runName.value.trim(),
    comment: runComment.value.trim(),
    selection,
  }

  axios.post('/quality/start-qrun', payload)
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
  <div class="space-y-6">

    <!-- Sticky Eingabebereich -->
    <div class="sticky top-0 bg-white border-b p-4 z-10">
      <div class="flex flex-wrap items-end gap-6">

        <!-- Name -->
        <div>
        <label class="block font-semibold mb-1">Name für den Run</label>
        <input
            v-model="runName"
            type="text"
            class="border rounded px-2 py-1 w-64"
            placeholder="z. B. letzter Test für heute"
        />
        </div>


        <!-- Team Range -->
        <div>
          <label class="block font-semibold mb-1">Teams (min–max)</label>
          <div class="flex gap-2">
            <input type="number" v-model.number="minTeams" min="4" max="25" class="border rounded px-2 py-1 w-20" />
            <span class="self-center">–</span>
            <input type="number" v-model.number="maxTeams" min="4" max="25" class="border rounded px-2 py-1 w-20" />
          </div>
        </div>

        <!-- Jury Runden -->
        <div>
            <label class="block font-semibold mb-1">Anzahl Jury-Runden</label>
            <div class="flex gap-4">
                <label class="flex items-center gap-1">
                <input type="checkbox" v-model="juryRounds.rounds_4" />
                4
                </label>
                <label class="flex items-center gap-1">
                <input type="checkbox" v-model="juryRounds.rounds_5" />
                5
                </label>
                <label class="flex items-center gap-1">
                <input type="checkbox" v-model="juryRounds.rounds_6" />
                6
                </label>
            </div>
        </div>

        <!-- Jury Lanes -->
        <div>
          <label class="block font-semibold mb-1">Jury-Spuren</label>
          <div class="flex flex-wrap gap-2">
            <label v-for="i in 5" :key="'lane_' + i" class="flex items-center gap-1">
              <input type="checkbox" v-model="juryLanes['lane_' + i]" />
              {{ i }}
            </label>
          </div>
        </div>

        <!-- Table Types -->
        <div>
          <label class="block font-semibold mb-1">Anzahl RG-Tische</label>
          <div class="flex gap-4">
            <label class="flex items-center gap-1">
              <input type="checkbox" v-model="tables['tables_2']" />
              2 
            </label>
            <label class="flex items-center gap-1">
              <input type="checkbox" v-model="tables['tables_4']" />
              4
            </label>
          </div>
        </div>

        <!-- Kommentar -->
        <div class="w-full">
        <label class="block font-semibold mb-1">Kommentar (optional)</label>
        <textarea
            v-model="runComment"
            class="border rounded px-2 py-1 w-full"
            rows="2"
            placeholder="Notizen zum Run …"
        />
        </div>

        <!-- Start Button -->
        <div>
          <button
            class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded disabled:opacity-40"
            :disabled="!isValid"
            @click="startVolumeTest"
          >
            ▶️ Start
          </button>
        </div>

      </div>
    </div>

    <!-- Platz für Runs -->
    <div class="p-4">
      <p class="text-gray-500">Hier erscheinen später alle gestarteten Runs.</p>
    </div>
  </div>
</template>