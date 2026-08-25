/** Cross-window sync for plan pop-out (reload + presence). */

export const PLAN_PREVIEW_CHANNEL = 'flow-plan-preview'

export type PlanPreviewReloadMessage = {
  type: 'reload'
  planId: number
  at: number
}

export type PlanPreviewPresenceMessage = {
  type: 'presence'
  status: 'open' | 'closed' | 'ping'
  planId: number
  at: number
}

export type PlanPreviewMessage = PlanPreviewReloadMessage | PlanPreviewPresenceMessage

function post(message: PlanPreviewMessage) {
  if (typeof window === 'undefined') return
  try {
    const channel = new BroadcastChannel(PLAN_PREVIEW_CHANNEL)
    channel.postMessage(message)
    channel.close()
  } catch {
    /* BroadcastChannel unsupported */
  }
}

export function notifyPlanPreviewReload(planId: number) {
  if (!planId) return
  const message: PlanPreviewReloadMessage = {
    type: 'reload',
    planId: Number(planId),
    at: Date.now(),
  }
  post(message)
  try {
    localStorage.setItem(`${PLAN_PREVIEW_CHANNEL}:reload:${planId}`, String(message.at))
  } catch {
    /* private mode / quota */
  }
}

export function notifyPlanPopoutPresence(
  planId: number,
  status: PlanPreviewPresenceMessage['status']
) {
  if (!planId) return
  post({
    type: 'presence',
    status,
    planId: Number(planId),
    at: Date.now(),
  })
}

export function subscribePlanPreviewMessages(
  onMessage: (message: PlanPreviewMessage) => void
): () => void {
  if (typeof window === 'undefined') return () => {}

  let channel: BroadcastChannel | null = null
  try {
    channel = new BroadcastChannel(PLAN_PREVIEW_CHANNEL)
    channel.onmessage = (event: MessageEvent<PlanPreviewMessage>) => {
      if (event.data?.type) onMessage(event.data)
    }
  } catch {
    channel = null
  }

  const onStorage = (event: StorageEvent) => {
    if (!event.key || !event.newValue) return
    const reloadPrefix = `${PLAN_PREVIEW_CHANNEL}:reload:`
    if (!event.key.startsWith(reloadPrefix)) return
    const planId = Number(event.key.slice(reloadPrefix.length))
    if (!planId) return
    onMessage({
      type: 'reload',
      planId,
      at: Number(event.newValue) || Date.now(),
    })
  }
  window.addEventListener('storage', onStorage)

  return () => {
    window.removeEventListener('storage', onStorage)
    try {
      channel?.close()
    } catch {
      /* ignore */
    }
  }
}

export function subscribePlanPreviewReload(
  planId: () => number | null | undefined,
  onReload: () => void
): () => void {
  return subscribePlanPreviewMessages((message) => {
    if (message.type !== 'reload') return
    const id = Number(planId())
    if (id && Number(message.planId) === id) onReload()
  })
}
