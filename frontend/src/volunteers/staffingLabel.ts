import type {StaffingGroup, StaffingRole} from '@/volunteers/staffingTypes'
import {volunteerSearchHaystack, type VolunteerPersonRef} from '@/utils/volunteerPerson'

export function staffingContainerTitle(
  role: Pick<StaffingRole, 'label' | 'group_label'>,
  group: Pick<StaffingGroup, 'group_index' | 'name'> | null,
): string {
  if (!group) return role.label
  if (group.name) return group.name
  const label = (role.group_label || '').trim()
  if (label) return `${label} ${group.group_index}`
  return role.label
}

export function staffingTileKey(roleId: number, groupId: number | null): string {
  return groupId != null ? `g-${groupId}` : `r-${roleId}`
}

export function rosterAssignmentCaption(assignment: {
  label: string
  group_label?: string | null
  group_index: number | null
}): string {
  const groupLabel = (assignment.group_label || '').trim()
  if (assignment.group_index != null && assignment.group_index > 0 && groupLabel) {
    return `${assignment.label} (${groupLabel} ${assignment.group_index})`
  }
  return assignment.label
}

export function rosterEntrySearchHaystack(entry: {
  person: VolunteerPersonRef
  assignments?: Array<{
    label: string
    group_label?: string | null
    group_index: number | null
  }>
}): string {
  const parts = [volunteerSearchHaystack(entry.person)]
  for (const assignment of entry.assignments ?? []) {
    parts.push(assignment.label)
    const groupLabel = (assignment.group_label || '').trim()
    if (groupLabel) {
      parts.push(groupLabel)
      if (assignment.group_index != null && assignment.group_index > 0) {
        parts.push(`${groupLabel} ${assignment.group_index}`)
      }
    }
    parts.push(rosterAssignmentCaption(assignment))
  }
  return parts.filter(Boolean).join(' ').toLowerCase()
}
