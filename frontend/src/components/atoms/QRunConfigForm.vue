<script setup>
import { computed } from 'vue'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import { getProgramTheme } from '@/utils/programTheme'
import { tableFieldPlural } from '@/utils/tableFieldLabels'

const FIRST_PROGRAM = {
  CHALLENGE: 3,
  FUTURE_8: 8,
}

const PROGRAM_OPTIONS = [
  { id: FIRST_PROGRAM.CHALLENGE, key: 'challenge' },
  { id: FIRST_PROGRAM.FUTURE_8, key: 'future8' },
]

const props = defineProps({
  firstProgram: Number,
  minTeams: Number,
  maxTeams: Number,
  juryLanes: Object,
  tables: Object,
  juryRounds: Object,
  qrunName: String,
  qrunComment: String,
  isValid: Boolean,
  robotCheck: Object,
  teamMin: { type: Number, default: 4 },
  teamMax: { type: Number, default: 25 },
  laneOptions: { type: Array, default: () => [1, 2, 3, 4, 5] },
})

const emit = defineEmits([
  'update:firstProgram',
  'update:minTeams',
  'update:maxTeams',
  'update:juryLanes',
  'update:tables',
  'update:juryRounds',
  'update:robotCheck',
  'update:qrunName',
  'update:qrunComment',
  'start',
  'refresh',
])

const isFuture8 = computed(() => props.firstProgram === FIRST_PROGRAM.FUTURE_8)
const tablesLabel = computed(() => tableFieldPlural(Number(props.firstProgram || FIRST_PROGRAM.CHALLENGE)))

function themeFor(key) {
  return getProgramTheme(key)
}

function selectProgram(id) {
  emit('update:firstProgram', id)
}
</script>

<template>
  <div class="qrun-config">
    <div class="qrun-config__fields">
      <div class="qrun-config__field qrun-config__field--break">
        <label class="qrun-config__label">Programm</label>
        <div class="flex items-center gap-2">
          <button
            v-for="option in PROGRAM_OPTIONS"
            :key="option.id"
            type="button"
            class="venues-view-btn"
            :class="{ 'is-active': firstProgram === option.id }"
            :aria-pressed="firstProgram === option.id"
            :title="themeFor(option.key).shortName"
            @click="selectProgram(option.id)"
          >
            <img
              v-if="themeFor(option.key).catalogName"
              :src="programLogoSrc(themeFor(option.key).catalogName)"
              :alt="programLogoAlt(themeFor(option.key).catalogName)"
              class="qrun-program-choice__logo"
            />
            <span class="qrun-program-choice__label">{{ themeFor(option.key).shortName }}</span>
          </button>
        </div>
      </div>

      <div class="qrun-config__field">
        <label class="qrun-config__label">Name für den QRun</label>
        <input
          type="text"
          class="glass-input liquid-surface-control !px-3 !py-2 w-64 max-w-full"
          :value="qrunName"
          @input="emit('update:qrunName', $event.target.value)"
          placeholder="z. B. letzter Test für heute"
        />
      </div>

      <div class="qrun-config__field">
        <label class="qrun-config__label">Teams (min–max)</label>
        <div class="flex gap-2 items-center">
          <input
            type="number"
            :min="teamMin"
            :max="teamMax"
            class="glass-input liquid-surface-control !px-3 !py-2 w-20"
            :value="minTeams"
            @input="emit('update:minTeams', Number($event.target.value))"
          />
          <span class="text-[var(--color-text-muted)]">–</span>
          <input
            type="number"
            :min="teamMin"
            :max="teamMax"
            class="glass-input liquid-surface-control !px-3 !py-2 w-20"
            :value="maxTeams"
            @input="emit('update:maxTeams', Number($event.target.value))"
          />
        </div>
      </div>

      <div class="qrun-config__field">
        <label class="qrun-config__label">Jury-Spuren</label>
        <div class="qrun-config__checks">
          <label
            v-for="i in laneOptions"
            :key="'lane_' + i"
            class="qrun-config__check"
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

      <div class="qrun-config__field">
        <label class="qrun-config__label">{{ tablesLabel }}</label>
        <div class="qrun-config__checks">
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="tables.tables_2"
              @change="emit('update:tables', { ...tables, tables_2: $event.target.checked })"
            />
            2
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="tables.tables_4"
              @change="emit('update:tables', { ...tables, tables_4: $event.target.checked })"
            />
            4
          </label>
        </div>
      </div>

      <div class="qrun-config__field">
        <label class="qrun-config__label">Jury-Runden</label>
        <div class="qrun-config__checks">
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_3"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_3: $event.target.checked })"
            />
            3
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_4"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_4: $event.target.checked })"
            />
            4
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_5"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_5: $event.target.checked })"
            />
            5
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_6"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_6: $event.target.checked })"
            />
            6
          </label>
        </div>
      </div>

      <div class="qrun-config__field">
        <label class="qrun-config__label">{{ isFuture8 ? 'Allianz-Gespräch' : 'Robot-Check' }}</label>
        <div class="qrun-config__checks">
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="robotCheck.rc_off"
              @change="emit('update:robotCheck', { ...robotCheck, rc_off: $event.target.checked })"
            />
            Aus
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="robotCheck.rc_on"
              @change="emit('update:robotCheck', { ...robotCheck, rc_on: $event.target.checked })"
            />
            An
          </label>
        </div>
      </div>

      <div class="qrun-config__field qrun-config__field--wide">
        <label class="qrun-config__label">Kommentar (optional)</label>
        <textarea
          rows="2"
          class="glass-input liquid-surface-control !px-3 !py-2 w-full"
          :value="qrunComment"
          @input="emit('update:qrunComment', $event.target.value)"
          placeholder="Notizen zum QRun…"
        />
      </div>

      <div class="qrun-config__actions">
        <button
          type="button"
          class="glass-btn-accent"
          :disabled="!isValid"
          @click="emit('start')"
        >
          <i class="bi bi-play-fill" aria-hidden="true"/>
          Start
        </button>

        <button
          type="button"
          class="glass-btn-secondary"
          title="Liste neu laden"
          @click="emit('refresh')"
        >
          <i class="bi bi-arrow-clockwise" aria-hidden="true"/>
          Aktualisieren
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.qrun-config__fields {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 1.25rem 1.5rem;
}

.qrun-config__field {
  min-width: 0;
}

.qrun-config__field--wide {
  width: 100%;
}

.qrun-config__field--break {
  flex: 1 0 100%;
}

.qrun-config__label {
  display: block;
  margin-bottom: 0.35rem;
  font-size: 0.8125rem;
  font-weight: 650;
  color: var(--color-text-muted);
}

.qrun-config__checks {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.qrun-config__check {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.875rem;
  color: var(--color-text);
  cursor: pointer;
}

.qrun-config__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.qrun-program-choice__logo {
  width: 1.25rem;
  height: 1.25rem;
  flex-shrink: 0;
  object-fit: contain;
}

.qrun-program-choice__label {
  letter-spacing: -0.02em;
}
</style>
