import {ref, onMounted, onUnmounted, watch} from 'vue';
import axios from 'axios';
import type FllEvent from "../../../models/FllEvent";

export type PlanActionEndpoint = 'now' | 'next';

export interface PlanActionContent {
    planId: number;
    role: number;
    room: number;
    interval?: number; // Only used for "next", ignored for "now"
    eventId: number;
}

export function buildRequestParameters(content: PlanActionContent, event?: FllEvent): Record<string, string | number> {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const day = getDateForEvent(event);
    return {
        point_in_time: `${hours}:${minutes}`,
        role: content.role,
        room: content.room,
        day: day,
        interval: content.interval, // Only for "next", ignored for "now"
    };
}

function getDateForEvent(event?: FllEvent): number {
    if (event?.days >= 2 && event?.date) {
        try {
            const eventDate = new Date(event.date);
            const today = new Date();

            today.setHours(0, 0, 0, 0);
            eventDate.setHours(0, 0, 0, 0);

            const diffTime = today.getTime() - eventDate.getTime();
            const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));
            if (diffDays >= 1) {
                return 2;
            }
        } catch (e) {
            console.error('Error parsing event.date', e);
        }
    }
    return 1;
}

/**
 * Apply role-based program filter to plan groups.
 * Role 6 (Besucher Challenge): exclude Explore (first_program_id === 2).
 * Role 10 (Besucher Explore): exclude Challenge (first_program_id === 3).
 */
export function applyRoleFilter(groups: any[] | null | undefined, role: number): any[] {
    if (!groups || !Array.isArray(groups)) return [];
    if (role === 6) {
        return groups.filter((g: any) => g.group_meta?.first_program_id !== 2);
    }
    if (role === 10) {
        return groups.filter((g: any) => g.group_meta?.first_program_id !== 3);
    }
    return groups;
}

/**
 * Fetch plan action (now or next) and apply role filter.
 * Returns result, loading, refresh and the fetched event ref.
 * Use refresh() when data should be re-fetched.
 */
export function usePlanAction(
    content: PlanActionContent,
    endpoint: PlanActionEndpoint
) {
    const loading = ref(false);
    const result = ref<any>(null);
    const event = ref<any>();

    onMounted(async () => {
        event.value = await fetchEvent(content.eventId);
    });

    async function refresh() {
        loading.value = true;
        result.value = null;
        try {
            const params: Record<string, string | number> = {
                ...buildRequestParameters(content, event.value),
            };
            const {data} = await axios.get(
                `/plans/action-${endpoint}/${content.planId}`,
                {params}
            );
            if (data && data.groups) {
                data.groups = applyRoleFilter(data.groups, content.role);
            }
            result.value = data;
        } catch (e) {
            console.error(e);
        } finally {
            loading.value = false;
        }
    }

    return {result, loading, refresh, event};
}

async function fetchEvent(eventId: number) {
    try {
        const {data} = await axios.get('/events/public/' + eventId);
        return data;
    } catch (e) {
        console.error(e);
    }
    return null;
}

/**
 * Like usePlanAction but also starts polling (e.g. every 5 minutes for "now").
 * Pass pollIntervalMs (default 5 * 60 * 1000); pass 0 to disable polling.
 */
export function usePlanActionWithPolling(
    content: PlanActionContent,
    endpoint: PlanActionEndpoint,
    pollIntervalMs: number = 5 * 60 * 1000
) {
    const {result, loading, refresh, event} = usePlanAction(content, endpoint);
    let intervalId: ReturnType<typeof setInterval> | null = null;

    onMounted(async () => {
        // wait until usePlanAction fetched the event (event is set to value or null)
        if (event.value === undefined) {
            await new Promise<void>((resolve) => {
                const stop = watch(event, (v) => {
                    if (v !== undefined) {
                        stop();
                        resolve();
                    }
                });
            });
        }

        // Now load data with the correct date set
        await refresh();

        if (pollIntervalMs > 0) {
            intervalId = setInterval(refresh, pollIntervalMs);
        }
    });

    onUnmounted(() => {
        if (intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
    });

    return {result, loading, refresh};
}
