import { createRouter, createWebHistory } from 'vue-router'
import AppLayout from '@/layouts/AppLayout.vue'
import WallchartView from '@/views/WallchartView.vue'
import MyCalendarView from '@/views/MyCalendarView.vue'
import PlaceholderView from '@/views/PlaceholderView.vue'
import RequestsView from '@/views/RequestsView.vue'
import ReportsView from '@/views/ReportsView.vue'
import PeopleView from '@/views/PeopleView.vue'
import SettingsView from '@/views/SettingsView.vue'
import LoginView from '@/views/LoginView.vue'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: {
      title: 'Sign in',
      guestOnly: true,
    },
  },
  {
    path: '/',
    component: AppLayout,
    meta: {
      requiresAuth: true,
    },
    children: [
      {
        path: '',
        name: 'wallchart',
        component: WallchartView,
        meta: { title: 'Wallchart' },
      },
      {
        path: 'calendar',
        name: 'calendar',
        component: MyCalendarView,
        meta: { title: 'My calendar' },
      },
      {
        path: 'requests',
        name: 'requests',
        component: RequestsView,
        meta: { title: 'Requests' },
      },
      {
        path: 'people',
        name: 'people',
        component: PeopleView,
        meta: { title: 'People' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: ReportsView,
        meta: { title: 'Reports' },
      },
      {
        path: 'settings/:section?',
        name: 'settings',
        component: SettingsView,
        meta: { title: 'Settings' },
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.loaded) {
    try {
      await auth.fetchCurrentUser()
    } catch {
      // Non-auth API failures should not create an infinite redirect loop.
    }
  }

  if (to.matched.some((record) => record.meta.requiresAuth) && !auth.authenticated) {
    return {
      name: 'login',
      query: {
        redirect: to.fullPath,
      },
    }
  }

  if (to.meta.guestOnly && auth.authenticated) {
    return { name: 'wallchart' }
  }

  return true
})

router.afterEach((to) => {
  const pageTitle = to.meta?.title
  document.title = pageTitle
    ? `${pageTitle} | Platform Holidays`
    : 'Platform Holidays'
})

export default router
