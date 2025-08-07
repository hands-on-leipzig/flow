<script setup>
import {ref, watch} from 'vue'

const props = defineProps({param: Object})
const emit = defineEmits(['update'])
const showInfo = ref(false)

const localValue = ref(props.param.value)

watch(() => props.param.value, val => {
  localValue.value = val
})

const showDefaultValue = (param) => {
  switch (param.type) {
    case 'boolean':
      return param.value === 1 ? 'an' : 'aus'
    default:
      return param.value
  }
}

function emitChange() {
  emit('update', {...props.param, value: localValue.value})
}
</script>

<template>
  <div class="flex items-center px-4 py-1 space-x-4 w-full hover:bg-gray-50 transition-colors duration-150 rounded">
    <!-- Label + Info -->
    <div class="flex items-center min-w-[25rem]">
      <span class="font-medium">{{ param.ui_label }}</span>
      <button
          @click="showInfo = !showInfo"
          class="ml-2 text-sm text-gray-500 hover:text-blue-600 focus:outline-none"
          title="Mehr Informationen"
      >
        ⓘ
      </button>
    </div>

    <!-- Input -->
    <div>
      <input
          v-if="param.type === 'integer' || param.type === 'decimal'"
          type="number"
          :min="param.min"
          :max="param.max"
          :step="param.step"
          v-model="localValue"
          @change="emitChange"
          class="w-20 border border-gray-300 rounded px-2 py-1 text-sm shadow-sm"
      />

      <div v-else-if="param.type === 'boolean'" class="w-20 flex items-center justify-center">
        <input
            type="checkbox"
            v-model="localValue"
            @change="emitChange"
            class="h-4 w-4 text-blue-600 border-gray-300 rounded"
        />
      </div>


      <input
          v-else-if="param.type === 'date'"
          type="date"
          v-model="localValue"
          @change="emitChange"
          class="border border-gray-300 rounded px-2 py-1 text-sm shadow-sm"
      />

      <input
          v-else-if="param.type === 'time'"
          type="time"
          v-model="localValue"
          @change="emitChange"
          class="border border-gray-300 rounded px-2 py-1 text-sm shadow-sm"
      />

      <input
          v-else
          type="text"
          v-model="localValue"
          @change="emitChange"
          class="border border-gray-300 rounded px-2 py-1 text-sm shadow-sm"
      />
    </div>

    <span class="text-gray-500 text-sm">({{ showDefaultValue(param) }})</span>

    <div
        v-if="showInfo"
        class="absolute mt-10 ml-2 z-10 w-64 p-2 text-sm text-gray-700 bg-white border border-gray-300 rounded shadow-lg"
    >
      {{ param.ui_description }}
    </div>
  </div>
</template>


<style scoped>

</style>