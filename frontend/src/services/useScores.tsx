import { ref } from 'vue';
import axios from 'axios';

type ScoresResponse = { name?: string, rounds?: any };
type RoundResponse = { [key: string]: TeamResponse }
type TeamResponse = { [key: string]: Team }
type Team = { name: string, scores: Score[], rank: number, id: number }
type Score = { points: number; highlight: boolean }
type Round = 'VR' | 'VF' | 'HF';

export const expectedScores: { [round in Round]: number } = {
    VR: 3,
    VF: 1,
    HF: 1,
};

export const roundNames: { [round in Round]: string } = {
    VR: 'Vorrunden',
    VF: 'Viertelfinale',
    HF: 'Halbfinale',
};

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

export function createTeams(category: TeamResponse, round: string): Team[] {
    if (!category || !round) {
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
        while (team.scores.length < expectedScores[round]) {
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

export function useScores(eventId: number) {
    const scores = ref<ScoresResponse | null>(null);
    const error = ref<string | null>(null);
    let refreshInterval: number | undefined = undefined;

    async function loadScores() {
        try {
            const response = await axios.get('/contao/score', { params: { event_id: eventId } });
            scores.value = response.data;
            error.value = null;
        } catch (err: any) {
            console.error(err?.message || err);
            error.value = err?.message || String(err);
        }
    }

    function startAutoRefresh(ms = 5 * 60 * 1000) {
        stopAutoRefresh();
        refreshInterval = window.setInterval(loadScores, ms);
    }

    function stopAutoRefresh() {
        if (refreshInterval !== undefined) {
            clearInterval(refreshInterval);
            refreshInterval = undefined;
        }
    }

    function setDemoData(demo: ScoresResponse) {
        scores.value = demo;
    }

    return { scores, error, loadScores, startAutoRefresh, stopAutoRefresh, setDemoData };
}