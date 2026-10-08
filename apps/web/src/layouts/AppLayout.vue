<script setup>
import { onMounted, ref } from 'vue'
import AppHeader from '@/components/app/AppHeader.vue'
import BookTimeOffModal from '@/components/leave/BookTimeOffModal.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const bookingOpen = ref(false)
const requestRefreshKey = ref(0)

function onRequestCreated() {
  requestRefreshKey.value += 1
}

onMounted(() => {
  if (!auth.loaded) auth.fetchCurrentUser()
})
</script>

<template>
  <div class="app-shell">
    <AppHeader @book-time-off="bookingOpen = true" />

    <main id="main-content" class="app-main">
      <div class="app-wrapper">
        <RouterView :request-refresh-key="requestRefreshKey" @book-time-off="bookingOpen = true" />
      </div>
    </main>

    <BookTimeOffModal
      :open="bookingOpen"
      @close="bookingOpen = false"
      @created="onRequestCreated"
    />
  </div>
</template>
