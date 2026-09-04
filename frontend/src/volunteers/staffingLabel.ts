import type {StaffingGroup, StaffingRole} from '@/volunteers/staffingTypes'

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
