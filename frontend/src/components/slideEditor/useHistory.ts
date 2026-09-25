import {computed, ref} from 'vue';

export function useHistory(limit = 100) {
  const stack: string[] = [];
  const index = ref(-1);
  const size = ref(0);

  const canUndo = computed(() => index.value > 0);
  const canRedo = computed(() => index.value < size.value - 1);

  function reset(state: string) {
    stack.length = 0;
    stack.push(state);
    index.value = 0;
    size.value = 1;
  }

  /** Returns false if the state equals the current entry (nothing to record). */
  function push(state: string): boolean {
    if (stack[index.value] === state) return false;
    stack.splice(index.value + 1);
    stack.push(state);
    if (stack.length > limit) stack.shift();
    index.value = stack.length - 1;
    size.value = stack.length;
    return true;
  }

  function undo(): string | null {
    if (!canUndo.value) return null;
    index.value--;
    return stack[index.value];
  }

  function redo(): string | null {
    if (!canRedo.value) return null;
    index.value++;
    return stack[index.value];
  }

  return {reset, push, undo, redo, canUndo, canRedo};
}
