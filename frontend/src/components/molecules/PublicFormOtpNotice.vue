<script setup lang="ts">
import {isOtpStubAllowed} from '@/utils/otpStub'

const stubAllowed = isOtpStubAllowed()
</script>

<template>
  <div class="glass-alert-warning otp-stub-notice" role="status">
    <i class="bi bi-plug otp-stub-notice__icon" aria-hidden="true"/>
    <p class="otp-stub-notice__text">
      <strong>OTP ist noch nicht implementiert.</strong><br>
      <template v-if="stubAllowed">
        Zum Testen 007008 verwenden. Aber die email muss trotzdem richtig sein!
      </template>
      <template v-else>
        Sobald es da ist, kommt der Code wirklich per Mail. Bisher dahin geht es hier noch nicht weiter.
      </template>
    </p>
  </div>
</template>

<style scoped>
.otp-stub-notice {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  margin: 0;
}

.otp-stub-notice__icon {
  flex-shrink: 0;
  margin-top: 0.15rem;
  font-size: 1.35rem;
  line-height: 1;
}

.otp-stub-notice__text {
  margin: 0;
  line-height: 1.45;
}
</style>
