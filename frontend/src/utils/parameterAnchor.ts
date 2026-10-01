export const PARAMETER_QUERY_KEY = 'parameter'

export function parameterAnchorId(id: number | string): string {
  return `parameter-${id}`
}

export function parameterAnchorAttr(param: {id?: number | string | null} | null | undefined): string | undefined {
  if (param?.id == null || param.id === '') return undefined
  return parameterAnchorId(param.id)
}
