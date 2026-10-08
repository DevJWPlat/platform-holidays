<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import PlatformLogo from '@/components/app/PlatformLogo.vue'

const route = useRoute()

const apiUrl = (import.meta.env.VITE_API_URL || 'http://localhost:8000').replace(/\/$/, '')
const googleSignInUrl = `${apiUrl}/auth/google/redirect`

const errorMessage = computed(() => {
  switch (route.query.error) {
    case 'domain':
      return 'Use your Platform Google account to sign in.'
    case 'archived':
      return 'This account has been archived and cannot sign in.'
    default:
      return ''
  }
})
</script>

<template>
  <main class="login-page">
    <section class="login-panel">
      <div class="login-brand">
        <PlatformLogo />
        <span class="login-brand__divider" aria-hidden="true" />
        <span>HOLIDAYS</span>
      </div>

      <div class="login-panel__content">
        <p class="eyebrow">PLATFORM HOLIDAYS</p>
        <h1>Sign in</h1>

        <p class="login-intro">
          Sign in with your Platform Google account.
        </p>

        <p
          v-if="errorMessage"
          class="login-error"
          role="alert"
        >
          {{ errorMessage }}
        </p>

        <a
          class="google-signin-button"
          :href="googleSignInUrl"
        >
          <svg
            class="google-signin-button__icon"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.39-.18-2.05H12v3.88h5.38a4.6 4.6 0 0 1-2 3.02v2.51h3.24c1.9-1.75 2.98-4.33 2.98-7.36Z"/>
            <path fill="#34A853" d="M12 22c2.7 0 4.97-.9 6.62-2.41l-3.24-2.51c-.9.6-2.05.96-3.38.96-2.61 0-4.82-1.76-5.61-4.13H3.04v2.6A10 10 0 0 0 12 22Z"/>
            <path fill="#FBBC05" d="M6.39 13.91A6 6 0 0 1 6.08 12c0-.66.11-1.3.31-1.91V7.5H3.04A10 10 0 0 0 2 12c0 1.61.38 3.13 1.04 4.5l3.35-2.59Z"/>
            <path fill="#EA4335" d="M12 5.96c1.47 0 2.79.5 3.83 1.5l2.87-2.87A9.63 9.63 0 0 0 12 2 10 10 0 0 0 3.04 7.5l3.35 2.59C7.18 7.72 9.39 5.96 12 5.96Z"/>
          </svg>

          <span>Continue with Google</span>
        </a>
      </div>
    </section>
  </main>
</template>
