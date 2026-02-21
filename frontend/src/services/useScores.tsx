import {ref} from 'vue';
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

export function getDemoData() {
    const scores = {
        "name": "München",
        "rounds": {
            "VR": {
                "1233": {
                    "name": "Here We GO",
                    "scores": [{"points": 210, "highlight": false}, {
                        "points": 130,
                        "highlight": false
                    }, {"points": 455, "highlight": true}],
                    "rank": 0,
                    "id": 1233
                },
                "1028": {
                    "name": "Needs No Name",
                    "scores": [{"points": 110, "highlight": false}, {
                        "points": 330,
                        "highlight": true
                    }, {"points": 265, "highlight": false}],
                    "rank": 0,
                    "id": 1028
                },
                "1714": {
                    "name": "MPG Youngsters",
                    "scores": [{"points": 235, "highlight": false}, {
                        "points": 265,
                        "highlight": true
                    }, {"points": 170, "highlight": false}],
                    "rank": 0,
                    "id": 1714
                },
                "1578": {
                    "name": "MPG Robotics",
                    "scores": [{"points": 240, "highlight": true}, {
                        "points": 225,
                        "highlight": false
                    }, {"points": 160, "highlight": false}],
                    "rank": 0,
                    "id": 1578
                },
                "1409": {
                    "name": "RoHoKi",
                    "scores": [{"points": 225, "highlight": false}, {
                        "points": 235,
                        "highlight": true
                    }, {"points": 195, "highlight": false}],
                    "rank": 0,
                    "id": 1409
                },
                "1579": {
                    "name": "MPG IT Crowd",
                    "scores": [{"points": 225, "highlight": true}, {
                        "points": 115,
                        "highlight": false
                    }, {"points": 195, "highlight": false}],
                    "rank": 0,
                    "id": 1579
                },
                "1509": {
                    "name": "We are ReDI",
                    "scores": [{"points": 225, "highlight": true}, {
                        "points": 155,
                        "highlight": false
                    }, {"points": 170, "highlight": false}],
                    "rank": 0,
                    "id": 1509
                },
                "1236": {
                    "name": "GO ROBOT",
                    "scores": [{"points": 100, "highlight": false}, {
                        "points": 150,
                        "highlight": false
                    }, {"points": 210, "highlight": true}],
                    "rank": 0,
                    "id": 1236
                },
                "1142": {
                    "name": "Robotik AG BvSG Team 1",
                    "scores": [{"points": 160, "highlight": false}, {
                        "points": 205,
                        "highlight": true
                    }, {"points": 190, "highlight": false}],
                    "rank": 0,
                    "id": 1142
                },
                "1588": {
                    "name": "RoboKids",
                    "scores": [{"points": 95, "highlight": false}, {
                        "points": 140,
                        "highlight": false
                    }, {"points": 205, "highlight": true}],
                    "rank": 0,
                    "id": 1588
                },
                "1467": {
                    "name": "Huberts Katze",
                    "scores": [{"points": 180, "highlight": false}, {
                        "points": 195,
                        "highlight": true
                    }, {"points": 190, "highlight": false}],
                    "rank": 0,
                    "id": 1467
                },
                "1466": {
                    "name": "SC-DigiTrain",
                    "scores": [{"points": 95, "highlight": false}, {
                        "points": 80,
                        "highlight": false
                    }, {"points": 185, "highlight": true}],
                    "rank": 0,
                    "id": 1466
                },
                "1263": {
                    "name": "RoboRo",
                    "scores": [{"points": 140, "highlight": false}, {
                        "points": 150,
                        "highlight": false
                    }, {"points": 170, "highlight": true}],
                    "rank": 0,
                    "id": 1263
                },
                "1248": {
                    "name": "Robotik AG BvSG Team 2",
                    "scores": [{"points": 160, "highlight": true}, {
                        "points": 160,
                        "highlight": true
                    }, {"points": 75, "highlight": false}],
                    "rank": 0,
                    "id": 1248
                },
                "1589": {
                    "name": "Gummibärchenbande",
                    "scores": [{"points": 160, "highlight": true}, {
                        "points": 160,
                        "highlight": true
                    }, {"points": 160, "highlight": true}],
                    "rank": 0,
                    "id": 1589
                },
                "1604": {
                    "name": "Lon3",
                    "scores": [{"points": 140, "highlight": false}, {
                        "points": 135,
                        "highlight": false
                    }, {"points": 155, "highlight": true}],
                    "rank": 0,
                    "id": 1604
                },
                "1445": {
                    "name": "EmileIntelligence \"EI\"",
                    "scores": [{"points": 145, "highlight": true}, {
                        "points": 80,
                        "highlight": false
                    }, {"points": 95, "highlight": false}],
                    "rank": 0,
                    "id": 1445
                },
                "1269": {
                    "name": "RoboCats",
                    "scores": [{"points": 135, "highlight": true}, {
                        "points": 80,
                        "highlight": false
                    }, {"points": 135, "highlight": true}],
                    "rank": 0,
                    "id": 1269
                },
                "1417": {
                    "name": "JFG Augsburg",
                    "scores": [{"points": 105, "highlight": false}, {
                        "points": 95,
                        "highlight": false
                    }, {"points": 130, "highlight": true}],
                    "rank": 0,
                    "id": 1417
                },
                "1166": {
                    "name": "MINT White Bricks",
                    "scores": [{"points": 125, "highlight": true}, {
                        "points": 120,
                        "highlight": false
                    }, {"points": 115, "highlight": false}],
                    "rank": 0,
                    "id": 1166
                },
                "1140": {
                    "name": "PaRaMeRoS",
                    "scores": [{"points": 80, "highlight": false}, {
                        "points": 75,
                        "highlight": false
                    }, {"points": 100, "highlight": true}],
                    "rank": 0,
                    "id": 1140
                },
                "1362": {
                    "name": "MakerLab Brickies",
                    "scores": [{"points": 0, "highlight": false}, {"points": 0, "highlight": false}, {
                        "points": 0,
                        "highlight": false
                    }],
                    "rank": 0,
                    "id": 1362
                },
                "1093": {
                    "name": "Die Musketiere",
                    "scores": [{"points": 0, "highlight": false}, {"points": 0, "highlight": false}, {
                        "points": 0,
                        "highlight": false
                    }],
                    "rank": 0,
                    "id": 1093
                }
            },
            "VF": {
                "1233": {
                    "name": "Here We GO",
                    "scores": [{"points": 360, "highlight": false}],
                    "rank": 0,
                    "id": 1233
                },
                "1028": {
                    "name": "Needs No Name",
                    "scores": [{"points": 255, "highlight": false}],
                    "rank": 0,
                    "id": 1028
                },
                "1714": {
                    "name": "MPG Youngsters",
                    "scores": [{"points": 170, "highlight": false}],
                    "rank": 0,
                    "id": 1714
                },
                "1578": {
                    "name": "MPG Robotics",
                    "scores": [{"points": 290, "highlight": false}],
                    "rank": 0,
                    "id": 1578
                },
                "1409": {"name": "RoHoKi", "scores": [{"points": 285, "highlight": false}], "rank": 0, "id": 1409},
                "1579": {
                    "name": "MPG IT Crowd",
                    "scores": [{"points": 245, "highlight": false}],
                    "rank": 0,
                    "id": 1579
                },
                "1509": {
                    "name": "We are ReDI",
                    "scores": [{"points": 115, "highlight": false}],
                    "rank": 0,
                    "id": 1509
                },
                "1236": {
                    "name": "GO ROBOT",
                    "scores": [{"points": 230, "highlight": false}],
                    "rank": 0,
                    "id": 1236
                },
                "1142": {
                    "name": "Robotik AG BvSG Team 1",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1142
                },
                "1588": {"name": "RoboKids", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1588},
                "1467": {
                    "name": "Huberts Katze",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1467
                },
                "1466": {
                    "name": "SC-DigiTrain",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1466
                },
                "1263": {"name": "RoboRo", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1263},
                "1248": {
                    "name": "Robotik AG BvSG Team 2",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1248
                },
                "1589": {
                    "name": "Gummibärchenbande",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1589
                },
                "1604": {"name": "Lon3", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1604},
                "1445": {
                    "name": "EmileIntelligence \"EI\"",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1445
                },
                "1269": {"name": "RoboCats", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1269},
                "1417": {
                    "name": "JFG Augsburg",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1417
                },
                "1166": {
                    "name": "MINT White Bricks",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1166
                },
                "1140": {"name": "PaRaMeRoS", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1140},
                "1362": {
                    "name": "MakerLab Brickies",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1362
                },
                "1093": {
                    "name": "Die Musketiere",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1093
                }
            },
            "HF": {
                "1233": {
                    "name": "Here We GO",
                    "scores": [{"points": 395, "highlight": false}],
                    "rank": 0,
                    "id": 1233
                },
                "1028": {
                    "name": "Needs No Name",
                    "scores": [{"points": 275, "highlight": false}],
                    "rank": 0,
                    "id": 1028
                },
                "1714": {
                    "name": "MPG Youngsters",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1714
                },
                "1578": {
                    "name": "MPG Robotics",
                    "scores": [{"points": 250, "highlight": false}],
                    "rank": 0,
                    "id": 1578
                },
                "1409": {"name": "RoHoKi", "scores": [{"points": 195, "highlight": false}], "rank": 0, "id": 1409},
                "1579": {
                    "name": "MPG IT Crowd",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1579
                },
                "1509": {
                    "name": "We are ReDI",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1509
                },
                "1236": {"name": "GO ROBOT", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1236},
                "1142": {
                    "name": "Robotik AG BvSG Team 1",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1142
                },
                "1588": {"name": "RoboKids", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1588},
                "1467": {
                    "name": "Huberts Katze",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1467
                },
                "1466": {
                    "name": "SC-DigiTrain",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1466
                },
                "1263": {"name": "RoboRo", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1263},
                "1248": {
                    "name": "Robotik AG BvSG Team 2",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1248
                },
                "1589": {
                    "name": "Gummibärchenbande",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1589
                },
                "1604": {"name": "Lon3", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1604},
                "1445": {
                    "name": "EmileIntelligence \"EI\"",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1445
                },
                "1269": {"name": "RoboCats", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1269},
                "1417": {
                    "name": "JFG Augsburg",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1417
                },
                "1166": {
                    "name": "MINT White Bricks",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1166
                },
                "1140": {"name": "PaRaMeRoS", "scores": [{"points": 0, "highlight": false}], "rank": 0, "id": 1140},
                "1362": {
                    "name": "MakerLab Brickies",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1362
                },
                "1093": {
                    "name": "Die Musketiere",
                    "scores": [{"points": 0, "highlight": false}],
                    "rank": 0,
                    "id": 1093
                }
            }
        }
    };

    return createTeams(scores["rounds"]["VR"], "VR");
}

export function useScores(eventId: number) {
    const scores = ref<ScoresResponse | null>(null);
    const error = ref<string | null>(null);
    let refreshInterval: number | undefined = undefined;

    async function loadScores() {
        try {
            const response = await axios.get('/contao/score', {params: {event_id: eventId}});
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

    return {scores, error, loadScores, startAutoRefresh, stopAutoRefresh};
}