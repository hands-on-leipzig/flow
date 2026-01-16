/**
 * Central style constants for consistent styling across the application
 * These logical names ensure consistency and make it easy to update styles globally
 */

// Banner/Alert styles
export const BANNER_STYLES = {
  warning: 'bg-yellow-100 border border-yellow-300 text-yellow-800',
  warningRedText: 'bg-yellow-100 border border-yellow-300 text-red-800',
  success: 'bg-green-100 border border-green-300 text-green-700',
  info: 'bg-blue-100 border border-blue-300 text-blue-700',
  danger: 'bg-red-100 border border-red-300 text-red-700',
  risk: 'bg-orange-100 border border-orange-300 text-orange-700',
} as const

// Alert level styles (for configuration alerts)
export const ALERT_LEVEL_STYLES = {
  level1: 'bg-green-100 border border-green-300 text-green-700', // Recommended
  level2: 'bg-orange-100 border border-orange-300 text-orange-700', // Risk
  level3: 'bg-red-100 border border-red-300 text-red-700', // High risk
} as const

// Border/ring styles for form elements
export const ALERT_BORDER_STYLES = {
  level1: 'border-2 border-green-500 ring-2 ring-green-500', // Recommended
  level2: 'border-2 border-orange-500 ring-2 ring-orange-500', // Risk
  level3: 'border-2 border-red-500 ring-2 ring-red-500', // High risk
  default: 'ring-1 ring-gray-500 border-gray-500', // OK
} as const
