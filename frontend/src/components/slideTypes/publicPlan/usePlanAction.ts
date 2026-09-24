import {ref, onMounted, onUnmounted, watch} from 'vue';
import axios from 'axios';
import type FllEvent from "../../../models/FllEvent";
import {resolvedAudienceSelection} from "@/models/abstractPublicPlanSlideContent";
import {isPlannerPreview} from "@/utils/usageCapture";

export type PlanActionEndpoint = 'now' | 'next';

export interface PlanActionContent {
    planId: number;
    joint: boolean;
    programs: number[];
    legacyRole?: number | null;
    room: number;
    interval?: number; // Only used for "next", ignored for "now"
    eventId: number;
}

/** HH:MM set by the preview clock. Null means the public carousel sends no time. */
export const previewClock = ref<string | null>(null);

export function setPreviewClock(value: string | null) {
    previewClock.value = value && /^\d{2}:\d{2}$/.test(value) ? value : null;
}

export function buildRequestParameters(content: PlanActionContent, event?: FllEvent): Record<string, string | number> {
    const selection = resolvedAudienceSelection(content, event);
    const params: Record<string, string | number> = {
        joint: selection.joint ? 1 : 0,
        programs: selection.programs.join(','),
        room: content.room,
        interval: content.interval ?? 30,
    };

    const clock = previewClock.value;
    const day = eventDayStamp(event);
    if (isPlannerPreview() && clock && day) {
        params.now = `${day} ${clock}`;
    }

    return params;
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

function eventDayStamp(event?: FllEvent): string | null {
    const raw = String(event?.date ?? '').slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
        return null;
    }
    if (getDateForEvent(event) !== 2) {
        return raw;
    }
    const [year, month, day] = raw.split('-').map(Number);
    const next = new Date(year, month - 1, day + 1);
    const m = String(next.getMonth() + 1).padStart(2, '0');
    const d = String(next.getDate()).padStart(2, '0');
    return `${next.getFullYear()}-${m}-${d}`;
}

/**
 * Fetch plan action (now or next) for the stored Übergreifend and program selection.
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
            result.value = data;
        } catch (e) {
            console.error(e);
        } finally {
            loading.value = false;
        }
    }

    watch(previewClock, () => {
        refresh();
    });

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
