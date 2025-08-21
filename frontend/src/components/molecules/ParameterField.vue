<script setup>
import {ref, watch} from 'vue'
import InfoPopover from "@/components/atoms/InfoPopover.vue";

const props = defineProps({
  param: {
    type: Object
  },
  withLabel: {
    type: Boolean,
    default: false,
  },
  horizontal: {
    type: Boolean,
    default: false,
  }
})
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
  <div
      class="flex items-center px-4 py-1 space-x-4 w-full hover:bg-gray-50 transition-colors duration-150 rounded"
      :class="{ 'flex-col items-start space-x-0 space-y-1': !horizontal }"
  >
    <div class="flex items-center min-w-[25rem]" v-if="withLabel">
      <span class="font-medium">{{ param.ui_label }}</span>
      <InfoPopover :text="param.ui_description"/>
    </div>

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

      <div v-if="!horizontal" class="text-gray-500 text-sm mt-1">
        ({{ showDefaultValue(param) }})
      </div>
    </div>

    <span v-if="horizontal" class="text-gray-500 text-sm">
      ({{ showDefaultValue(param) }})
    </span>
  </div>
</template>


<style scoped>

</style>