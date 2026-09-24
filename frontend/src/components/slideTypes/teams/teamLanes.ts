import axios from "axios";
import {programId, type EventProgramRef} from "@/utils/eventPrograms";

export type TeamLaneTeam = {
    name?: string | null;
    organization?: string | null;
    location?: string | null;
};

export type TeamLane = {
    program_id?: number | string | null;
    name?: string | null;
    teams?: TeamLaneTeam[] | null;
};

export async function loadTeamLanes(eventId: number): Promise<TeamLane[]> {
    const {data} = await axios.get(`/events/${eventId}/team-lanes`);
    const lanes = data?.lanes;
    return Array.isArray(lanes) ? lanes : [];
}

export async function loadEventPrograms(eventId: number): Promise<EventProgramRef[]> {
    const {data} = await axios.get(`/events/public/${eventId}`);
    return Array.isArray(data?.programs) ? data.programs : [];
}

export async function loadCityCoordinates(cities: string[]): Promise<Record<string, {lat: number, lon: number}>> {
    if (cities.length === 0) {
        return {};
    }
    const {data} = await axios.post('/geocode-cities', {cities});
    const rows = data?.cities;
    return rows && typeof rows === 'object' ? rows : {};
}

export function selectedLanes(lanes: TeamLane[], programs: number[] | null): TeamLane[] {
    if (programs == null) {
        return lanes;
    }
    const selected = new Set(programs);
    return lanes.filter((lane) => selected.has(Number(lane.program_id)));
}

export function programForLane(lane: TeamLane, rows: EventProgramRef[]): EventProgramRef {
    const id = Number(lane.program_id);
    const match = rows.find((row) => programId(row) === id);
    if (match) {
        return match;
    }
    return {
        first_program: id,
        name: lane.name,
        display_name: lane.name,
    };
}
