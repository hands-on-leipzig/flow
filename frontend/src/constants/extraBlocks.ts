/**
 * Shared constants for ExtraBlock management
 */

export const TIMING_FIELDS = [
  'start',
  'end',
  'buffer_before',
  'duration',
  'buffer_after',
  'insert_point',
  'first_program'
] as const

export const TOGGLE_FIELDS = ['active'] as const

export const DEBOUNCE_DELAY = 60000

