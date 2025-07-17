<script setup>

</script>

<template>
  <div class="logo">
    <span class="text">FL</span>

    <div class="clock">
      <div class="hour-hand"></div>
      <div class="minute-hand"></div>
    </div>

    <span class="text">W</span>
  </div>

</template>

<style scoped>
.logo {
  display: flex;
  align-items: center;
  font-weight: bold;
  font-size: 48px;
  color: #f39200;
  font-family: sans-serif;
}

.text {
  padding: 0 4px;
}

.clock {
  position: relative;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background-color: #f39200;
  display: flex;
  align-items: center;
  justify-content: center;
}

.hour-hand, .minute-hand {
  position: absolute;
  background: white;
  transform-origin: bottom center;
  border-radius: 2px;
}

.hour-hand {
  width: 3px;
  height: 13px;
  bottom: 50%;
  animation: rotate-hour 6s linear infinite;
}

.minute-hand {
  width: 3px;
  height: 18px;
  bottom: 50%;
  animation: rotate-minute 2s linear infinite;
}

@keyframes rotate-hour {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

@keyframes rotate-minute {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

</style>