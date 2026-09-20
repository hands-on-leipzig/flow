<script setup lang="ts">
import {isNonProductionPublicHost} from '@/utils/publicFormOtpEnv'

defineProps<{
  ssoEmail?: string
}>()

const show = isNonProductionPublicHost()
</script>

<template>
  <p v-if="show && ssoEmail" class="vol-public-form__info">
    Testumgebung: Der Code geht an {{ ssoEmail }}, nicht an die eingegebene Adresse.
  </p>
  <div
      v-else-if="show"
      class="otp-test-notice glass-chip liquid-surface-inner"
      title="Admin"
  >
    <i class="bi bi-shield-lock otp-test-notice__mark" aria-hidden="true"/>
    <p class="otp-test-notice__text">
      Testumgebung: In der Preview wird kein Code versendet. Zum Testen in eigenem Tab öffnen. Dann greift SSO und das OTP-Mail wird umgeleitet.
    </p>
  </div>
</template>

<style scoped>
.otp-test-notice {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  margin: 0;
  padding: 0.7rem 0.85rem !important;
}

.otp-test-notice__mark {
  flex-shrink: 0;
  margin-top: 0.1rem;
  color: var(--color-text-muted);
  opacity: 0.85;
  font-size: 1rem;
  line-height: 1;
}

.otp-test-notice__text {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  line-height: 1.45;
}
</style>
