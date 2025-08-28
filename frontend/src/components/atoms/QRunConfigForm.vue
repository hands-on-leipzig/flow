<script setup>
const props = defineProps({
  minTeams: Number,
  maxTeams: Number,
  juryLanes: Object,
  tables: Object,
  juryRounds: Object,
  runName: String,
  runComment: String,
  isValid: Boolean,
})

const emit = defineEmits([
  'update:minTeams',
  'update:maxTeams',
  'update:juryLanes',
  'update:tables',
  'update:juryRounds',
  'update:runName',
  'update:runComment',
  'start',
])
</script>

<template>
  <div class="sticky top-0 bg-white border-b p-4 z-10">
    <div class="flex flex-wrap items-end gap-6">
      <!-- Name -->
      <div>
        <label class="block font-semibold mb-1">Name für den Run</label>
        <input
          type="text"
          class="border rounded px-2 py-1 w-64"
          :value="runName"
          @input="emit('update:runName', $event.target.value)"
          placeholder="z. B. letzter Test für heute"
        />
      </div>

      <!-- Team Range -->
      <div>
        <label class="block font-semibold mb-1">Teams (min–max)</label>
        <div class="flex gap-2">
          <input
            type="number"
            min="4"
            max="25"
            class="border rounded px-2 py-1 w-20"
            :value="minTeams"
            @input="emit('update:minTeams', Number($event.target.value))"
          />
          <span class="self-center">–</span>
          <input
            type="number"
            min="4"
            max="25"
            class="border rounded px-2 py-1 w-20"
            :value="maxTeams"
            @input="emit('update:maxTeams', Number($event.target.value))"
          />
        </div>
      </div>

      <!-- Jury Runden -->
      <div>
        <label class="block font-semibold mb-1">Anzahl Jury-Runden</label>
        <div class="flex gap-4">
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_4"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_4: $event.target.checked })"
            />
            4
          </label>
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_5"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_5: $event.target.checked })"
            />
            5
          </label>
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_6"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_6: $event.target.checked })"
            />
            6
          </label>
        </div>
      </div>

      <!-- Jury Lanes -->
      <div>
        <label class="block font-semibold mb-1">Jury-Spuren</label>
        <div class="flex flex-wrap gap-2">
          <label
            v-for="i in 5"
            :key="'lane_' + i"
            class="flex items-center gap-1"
          >
            <input
              type="checkbox"
              :checked="juryLanes['lane_' + i]"
              @change="emit('update:juryLanes', {
                ...juryLanes,
                ['lane_' + i]: $event.target.checked
              })"
            />
            {{ i }}
          </label>
        </div>
      </div>

      <!-- Table Types -->
      <div>
        <label class="block font-semibold mb-1">Anzahl RG-Tische</label>
        <div class="flex gap-4">
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="tables.tables_2"
              @change="emit('update:tables', { ...tables, tables_2: $event.target.checked })"
            />
            2
          </label>
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="tables.tables_4"
              @change="emit('update:tables', { ...tables, tables_4: $event.target.checked })"
            />
            4
          </label>
        </div>
      </div>

      <!-- Kommentar -->
      <div class="w-full">
        <label class="block font-semibold mb-1">Kommentar (optional)</label>
        <textarea
          rows="2"
          class="border rounded px-2 py-1 w-full"
          :value="runComment"
          @input="emit('update:runComment', $event.target.value)"
          placeholder="Notizen zum Run …"
        />
      </div>

      <!-- Start Button -->
      <div>
        <button
          class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded disabled:opacity-40"
          :disabled="!isValid"
          @click="emit('start')"
        >
          ▶️ Start
        </button>
      </div>
    </div>
  </div>
</template>