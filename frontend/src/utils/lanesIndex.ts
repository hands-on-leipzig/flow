// utils/lanesIndex.ts
export type LaneRow = {
    first_program: number; // 3 = Challenge, 2 = Explore
    teams: number;
    lanes: number;
    tables?: number | null;
    note?: string | null;
    recommended?: boolean | null;
    suggested?: number | null;
};

export type LanesIndex = {
    // compatibility: allowed lanes list (existing code keeps working)
    challenge: Record<string, number[]>; // key: `${teams}|${tables}`
    explore: Record<string, number[]>;   // key: `${teams}`

    // NEW: metadata per combo
    metaChallenge: Record<string, Record<number, { note?: string; recommended?: boolean; suggested?: boolean }>>;
    metaExplore: Record<string, Record<number, { note?: string; recommended?: boolean; suggested?: boolean }>>;
};

export function buildLanesIndex(rows: LaneRow[]): LanesIndex {
    const challenge: Record<string, number[]> = {};
    const explore: Record<string, number[]> = {};
    const metaChallenge: LanesIndex['metaChallenge'] = {};
    const metaExplore: LanesIndex['metaExplore'] = {};

    for (const r of rows) {
        if (r.first_program === 3) {
            const key = `${r.teams}|${r.tables ?? 0}`;
            (challenge[key] ||= []).push(Number(r.lanes));
            (metaChallenge[key] ||= {})[Number(r.lanes)] = {
                note: r.note ?? undefined,
                recommended: !!r.recommended,
                suggested: !!(r.suggested ?? 0),
            };
        } else if (r.first_program === 2) {
            const key = `${r.teams}`;
            (explore[key] ||= []).push(Number(r.lanes));
            (metaExplore[key] ||= {})[Number(r.lanes)] = {
                note: r.note ?? undefined,
                recommended: !!r.recommended,
                suggested: !!(r.suggested ?? 0),
            };
        }
    }

    const dedupSort = (arr: number[]) => Array.from(new Set(arr)).sort((a, b) => a - b);
    for (const k in challenge) challenge[k] = dedupSort(challenge[k]);
    for (const k in explore) explore[k] = dedupSort(explore[k]);

    return {challenge, explore, metaChallenge, metaExplore};
}
