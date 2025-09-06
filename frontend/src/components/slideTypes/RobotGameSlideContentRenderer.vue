<script setup lang="ts">
import {RobotGameSlideContent} from '../../models/robotGameSlideContent.js';
import api from "../../services/api.js";
import {onMounted, onUnmounted, ref, computed} from "vue";

type ScoresResponse = { name?: string, rounds?: RoundResponse }
type RoundResponse = { [key: string]: TeamResponse }
type TeamResponse = { [key: string]: Team }
type Team = { name: string, scores: Score[], rank: number, id: number }
type Score = { points: number; highlight: boolean }
type Round = 'VR' | 'AF' | 'VF' | 'HF';

const expectedScores: { [round in Round]: number } = {
  VR: 3,
  AF: 1,
  VF: 1,
  HF: 1,
};

const roundNames: { [round in Round]: string } = {
  VR: 'Vorrunden',
  AF: 'Achtelfinale',
  VF: 'Viertelfinale',
  HF: 'Halbfinale',
};

const scores = ref<ScoresResponse>(null);
const error = ref<string | null>(null);
const currentIndex = ref(0);
const isPaused = ref(false);
const teamsPerPage = ref(8);
const round = ref<string | undefined>(undefined);
const teams = computed(() => {
  const category = getRoundToShow(scores.value?.rounds);
  return createTeams(category) || [];
});
const paginatedTeams = computed(() => {
  return teams.value.slice(currentIndex.value, currentIndex.value + teamsPerPage.value);
});

// const settings = ref<any>(null);

function sortScores(team: any): number[] {
  return team.scores.map((score: any) => +score.points).sort((a: number, b: number) => b - a);
}

function assignRanks(teams: Team[]): Team[] {
  if (!teams || teams.length === 0) {
    return teams;
  }
  let rank = 1;
  let prevScore = 0;
  const result: Team[] = [];
  for (let i = 0; i < teams.length; i++) {
    const maxScore = sortScores(teams[i])[0];
    if (maxScore !== prevScore) {
      rank = i + 1;
    }
    teams[i].rank = rank;
    if (maxScore > 0 || prevScore === 0) {
      result.push(teams[i]);
      prevScore = maxScore;
    }
  }
  return result;
}

function createTeams(category: TeamResponse): Team[] {
  if (!category || !round.value) {
    return undefined;
  }
  const teams: Team[] = [];
  for (const id in category) {
    const team = {...category[id], id: +id};
    const scores = sortScores(team);
    const maxScore = scores[0];
    team.scores = team.scores.map((score: any) => {
      score.highlight = +score.points === maxScore && maxScore > 0 && scores.length > 1;
      return score;
    });
    // Add extra scores if necessary
    while (team.scores.length < expectedScores[round.value]) {
      team.scores.push({points: 0, highlight: false});
    }
    teams.push(team);
  }
  teams.sort((a: any, b: any) => {
    const aScores = sortScores(a);
    const bScores = sortScores(b);
    for (let i = 0; i < aScores.length && i < bScores.length; i++) {
      if (aScores[i] !== bScores[i]) {
        return bScores[i] - aScores[i];
      }
    }
    return 0;
  });
  return assignRanks(teams);
}

function getRoundToShow(rounds: RoundResponse): TeamResponse {
  if (!rounds) {
    return undefined;
  }
  /*if (rounds.HF) {
    round.value = 'HF';
    return rounds.HF;
  }
  if (rounds.VF) {
    round.value = 'VF';
    return rounds.VF;
  }
  if (rounds.AF) {
    round.value = 'AF';
    return rounds.AF;
  } */
  if (rounds.VR) {
    round.value = 'VR';
    return rounds.VR;
  }
  return undefined;
}

// Load data function
function loadDACHData() {
  api.get('/api/events/1/data/rg-scores')
      .then((response) => {
        scores.value = response.data;
      })
      .catch((err) => {
        console.error(err.message);
      });
}

function advancePage() {
  if (currentIndex.value + teamsPerPage.value > teams.value.length) {
    currentIndex.value = 0;
  } else {
    currentIndex.value = (currentIndex.value + teamsPerPage.value) % teams.value.length;
  }
}

// Previous page function
function previousPage() {
  if (currentIndex.value === 0) {
    const teamsLastPage = teams.value.length % teamsPerPage.value;
    if (teamsLastPage === 0) {
      currentIndex.value = teams.value.length - teamsPerPage.value;
    } else {
      currentIndex.value = teams.value.length - teamsLastPage;
    }
  } else {
    currentIndex.value = Math.max(currentIndex.value - teamsPerPage.value, 0);
  }
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Enter') {
    console.log('pausing');
    isPaused.value = !isPaused.value;
  } else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
    advancePage();
  } else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
    previousPage();
  }
}

let refreshInterval: number;
let autoAdvanceInterval: number;

onMounted(loadDACHData);
onMounted(() => {
  refreshInterval = setInterval(loadDACHData, 5 * 60 * 1000);

  autoAdvanceInterval = setInterval(() => {
    if (!isPaused.value) {
      advancePage();
    }
  }, 15000);

  // Add keydown event listener
  window.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
  clearInterval(refreshInterval);
  clearInterval(autoAdvanceInterval);
  window.removeEventListener('keydown', handleKeyDown);
})
const props = defineProps({
  content: RobotGameSlideContent
});
</script>

<template>
  <div class="slide-container">
    <h1 class="slide-title">
      ERGEBNISSE {{ round ? roundNames[round].toUpperCase() : '' }}: {{ scores?.name?.toUpperCase() }}
    </h1>

    <div>
      <table class="scores">
        <thead>
        <tr>
          <th>Team</th>
          <template v-if="round === 'VR'">
            <th class="cell">R I</th>
            <th class="cell">R II</th>
            <th class="cell">R III</th>
          </template>
          <template v-else>
            <th class="cell">Score</th>
          </template>
          <th class="cell">Rank</th>
        </tr>
        </thead>
        <tbody>
        <tr v-for="team in paginatedTeams" :key="team.id">
          <td class="teamName">{{ team.name }}</td>
          <template v-for="(score, index) in team.scores" :key="index">
            <td class="cell" :class="{ highlight: score.highlight }">
              {{ score.points }}
            </td>
          </template>
          <td class="cell">{{ team.rank }}</td>
        </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>

.slide-container {
  height: 100%;
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: start;
  background-size: cover;
  background-position: center;
  padding: 5em;
  /* TODO: use user-defined values from settings */
  background-color: black;
  color: white;
}

.slide-title {
  font-size: 3rem;
  font-weight: bold;
  padding: 0 1rem 3rem 1rem;
}

.scores {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 3rem;
}

th, td {
  padding: 0.5rem 1rem 0.5rem 1rem;
}

.teamName {
  border-right: 1px solid white;
  border-top: 1px solid white;
  width: auto;
}

.cell {
  width: 8rem;
  text-align: center;
}

td {
  border-top: 1px solid white;
}

tr > td:not(:last-child),
tr > th:not(:last-child) {
  border-right: 1px solid white;
}

.highlight {
  background-color: v-bind('props.content.highlightColor');
}
</style>
