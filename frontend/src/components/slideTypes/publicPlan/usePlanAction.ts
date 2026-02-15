import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

export type PlanActionEndpoint = 'now' | 'next';

export interface PlanActionContent {
  planId: number;
  role: number;
  interval?: number; // Only used for "next", ignored for "now"
}

export function buildRequestParameters(content: PlanActionContent) {
  const now = new Date();
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');
  return {
    point_in_time: `${hours}:${minutes}`,
    role: content.role,
    interval: content.interval, // Only for "next", ignored for "now"
  };
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
 * Returns result, loading, and refresh. Use refresh() when data should be re-fetched.
 */
export function usePlanAction(
  content: PlanActionContent,
  endpoint: PlanActionEndpoint
) {
  const loading = ref(false);
  const result = ref<any>(null);

  async function refresh() {
    loading.value = true;
    result.value = null;
    try {
      const params: Record<string, string | number> = {
        ...buildRequestParameters(content),
      };
      const { data } = await axios.get(
        `/plans/action-${endpoint}/${content.planId}`,
        { params }
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

  return { result, loading, refresh };
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
  const { result, loading, refresh } = usePlanAction(content, endpoint);
  let intervalId: ReturnType<typeof setInterval> | null = null;

  onMounted(() => {
    refresh();
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

  return { result, loading, refresh };
}
