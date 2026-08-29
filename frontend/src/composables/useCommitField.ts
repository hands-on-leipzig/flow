import {ref, watch, type Ref} from 'vue'

/**
 * Local editing state for schedule inputs. Commits on blur or Enter only —
 * same pattern as ParameterField @change, not per-keystroke scheduling.
 */
export function useCommitField<T>(
  source: () => T,
  onCommit: (value: T) => void,
  equals: (a: T, b: T) => boolean = (a, b) => Object.is(a, b),
) {
  const local = ref(source()) as Ref<T>
  let editing = false

  watch(source, (value) => {
    if (!editing) {
      local.value = value
    }
  })

  function onInput(value: T) {
    editing = true
    local.value = value
  }

  function commit() {
    editing = false
    const next = local.value
    if (!equals(next, source())) {
      onCommit(next)
    }
  }

  function onBlur() {
    commit()
  }

  function onEnter(event: KeyboardEvent) {
    event.preventDefault()
    ;(event.target as HTMLElement)?.blur()
  }

  return {local, onInput, onBlur, onEnter, commit}
}
