<script setup>
const props = defineProps({
  username: String,
  jwt: String,
})


</script>

<template>
You are logged in as {{username}}.<br>
  JWT is {{jwt}}.
</template>

<style scoped>

</style>