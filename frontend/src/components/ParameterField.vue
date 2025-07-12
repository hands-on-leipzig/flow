<script setup>
import {ref, watch} from 'vue'

const props = defineProps({param: Object})
const emit = defineEmits(['update'])

const localValue = ref(props.param.value)

watch(() => props.param.value, val => {
  localValue.value = val
})

function emitChange() {
  emit('update', {...props.param, value: localValue.value})
}
</script>

<template>
  <div class="flex items-center justify-between space-x-4 px-14">
    <label class="block font-medium mb-1">
      {{ param.ui_label }}
    </label>

    <input
        v-if="param.type === 'integer' || param.type === 'decimal'"
        type="number"
        :min="param.min"
        :max="param.max"
        :step="param.step"
        v-model="localValue"
        @change="emitChange"
        class="border border-gray-300 rounded px-2 py-1  text-sm shadow-sm"
    />

    <input
        v-else-if="param.type === 'boolean'"
        type="checkbox"
        v-model="localValue"
        @change="emitChange"
        class="h-4 w-4 text-blue-600 border-gray-300 rounded"
    />

    <input
        v-else-if="param.type === 'date'"
        type="date"
        v-model="localValue"
        @change="emitChange"
        class="border border-gray-300 rounded px-2 py-1  text-sm shadow-sm"
    />

    <input
        v-else-if="param.type === 'time'"
        type="time"
        v-model="localValue"
        @change="emitChange"
        class="border border-gray-300 rounded px-2 py-1  text-sm shadow-sm"
    />

    <input
        v-else
        type="text"
        v-model="localValue"
        @change="emitChange"
        class="border border-gray-300 rounded px-2 py-1  text-sm shadow-sm"
    />

    <span>
      ({{ param.value }})
    </span>
  </div>
</template>

<style scoped>

</style>