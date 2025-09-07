<script lang="ts" setup>
import ParameterField from '@/components/molecules/ParameterField.vue'
import InfoPopover from "@/components/atoms/InfoPopover.vue"

interface Props {
  param: any
  visibilityMap: Record<string, boolean>
  disabledMap: Record<string, boolean>
  additionalClasses?: string | Record<string, boolean>
}

const props = withDefaults(defineProps<Props>(), {
  additionalClasses: ''
})

const emit = defineEmits<{
  (e: 'update', param: any): void
}>()

function handleUpdate(param: any) {
  emit('update', param)
}
</script>

<template>
  <div :class="additionalClasses" class="p-2">
    <InfoPopover :text="param?.ui_description"/>
    <ParameterField
        v-if="param && visibilityMap[param.id]"
        :disabled="disabledMap[param.id]"
        :horizontal="true"
        :param="param"
        @update="handleUpdate"
    />
  </div>
</template>

<style scoped>

</style>