<script setup lang="ts">
</script>

<template>
  <div class="vol-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Besetzung</h1>
        <p class="vol-page__sub">
          Rollen und Einsätze folgen in Slice 2/3 — nach erfolgreicher Plangenerierung werden Gruppen angelegt.
        </p>
      </div>
    </header>
    <div class="glass-card vol-stub">
      <p>Noch nicht implementiert. Personen und Anmeldung sind bereits nutzbar.</p>
    </div>
  </div>
</template>

<style scoped>
.vol-page { padding: 0.5rem 0 2rem; display: flex; flex-direction: column; gap: 1rem; }
.vol-page__title { font-size: 1.5rem; font-weight: 650; margin: 0; }
.vol-page__sub { margin: 0.25rem 0 0; opacity: 0.75; }
.vol-stub { padding: 1.25rem; opacity: 0.85; }
</style>
