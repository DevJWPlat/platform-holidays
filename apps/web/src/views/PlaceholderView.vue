<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()
</script>

<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <div class="eyebrow">{{ route.meta.eyebrow || 'PLATFORM HOLIDAYS' }}</div>
        <h1>{{ route.meta.title }}</h1>
        <p>This area will be built when its backend workflow is ready.</p>
      </div>
    </div>

    <div class="empty-panel">
      <strong>{{ route.meta.title }}</strong>
      <span>No placeholder dashboard content has been added.</span>
    </div>
  </section>
</template>
