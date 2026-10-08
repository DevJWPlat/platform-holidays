import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/api/client'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const loading = ref(false)
  const loaded = ref(false)

  const authenticated = computed(() => Boolean(user.value))

  async function fetchCurrentUser() {
    loading.value = true

    try {
      const response = await api.get('/api/v1/me')
      user.value = response.data.data
      return user.value
    } catch (error) {
      if (error.response?.status === 401) {
        user.value = null
        return null
      }

      throw error
    } finally {
      loaded.value = true
      loading.value = false
    }
  }

  function loginWithGoogle() {
    const apiUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000'
    window.location.assign(`${apiUrl}/auth/google/redirect`)
  }

  async function logout() {
    try {
      await api.get('/sanctum/csrf-cookie')
      await api.post('/logout')
    } finally {
      user.value = null
      loaded.value = true
    }
  }

  return {
    user,
    loading,
    loaded,
    authenticated,
    fetchCurrentUser,
    loginWithGoogle,
    logout,
  }
})
