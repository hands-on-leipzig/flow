export type CustomFieldDefinition = {
  id: number
  field_key: string
  label: string
  type: 'text' | 'number' | 'boolean' | 'select'
  options: Array<{value: string; label: string}>
  sequence: number
  usage_count?: number
}

export type CustomFieldDraft = {
  label: string
  type: CustomFieldDefinition['type']
  optionsText: string
}

export const CUSTOM_FIELD_TYPES = [
  {value: 'text', label: 'Text'},
  {value: 'number', label: 'Zahl'},
  {value: 'boolean', label: 'Ja / Nein'},
  {value: 'select', label: 'Auswahl'},
] as const

export function emptyCustomFieldDraft(): CustomFieldDraft {
  return {label: '', type: 'text', optionsText: ''}
}

export function parseCustomFieldOptions(text: string) {
  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((label) => ({value: label, label}))
}

export function optionLinesFromCustomField(field: CustomFieldDefinition) {
  return (field.options ?? []).map((option) => option.label).join('\n')
}

export function customFieldTypeLabel(type: CustomFieldDefinition['type']) {
  return CUSTOM_FIELD_TYPES.find((entry) => entry.value === type)?.label ?? type
}

export function deleteCustomFieldConfirmMessage(field: CustomFieldDefinition) {
  const n = Number(field.usage_count ?? 0)
  if (n > 0) {
    return `„${field.label}“ löschen? ${n} Einträge werden gelöscht.`
  }
  return `„${field.label}“ und alle Werte werden entfernt.`
}

export function canAddCustomField(draft: CustomFieldDraft, adding: boolean) {
  if (adding || !draft.label.trim()) return false
  if (draft.type === 'select' && parseCustomFieldOptions(draft.optionsText).length === 0) return false
  return true
}
