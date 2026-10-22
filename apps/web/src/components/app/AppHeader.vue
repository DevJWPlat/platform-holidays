<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  faBars,
  faCalendarDays,
  faChartColumn,
  faChevronDown,
  faClipboardCheck,
  faGear,
  faPeopleGroup,
  faPlaneDeparture,
  faRightFromBracket,
  faTableColumns,
} from '@fortawesome/free-solid-svg-icons'
import PlatformLogo from './PlatformLogo.vue'
import SoftwareSwitcher from './SoftwareSwitcher.vue'
import { useAuthStore } from '@/stores/auth'

const emit = defineEmits(['book-time-off'])

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const switcherOpen = ref(false)
const mobileOpen = ref(false)
const logoutConfirmOpen = ref(false)
const loggingOut = ref(false)
let previousOverflow = ''

const navItems = computed(() => {
  const items = [
    { label: 'Wallchart', to: '/', icon: faTableColumns },
    { label: 'My calendar', to: '/calendar', icon: faCalendarDays },
    { label: 'Requests', to: '/requests', icon: faClipboardCheck },
  ]

  if (auth.user?.permissions?.manage_department_people) {
    items.push({ label: 'People', to: '/people', icon: faPeopleGroup })
  }

  if (auth.user?.permissions?.view_reports) {
    items.push({ label: 'Reports', to: '/reports', icon: faChartColumn })
  }

  if (auth.user?.permissions?.manage_settings) {
    items.push({ label: 'Settings', to: '/settings/general', icon: faGear })
  }

  return items
})

const initials = computed(() => {
  const name = auth.user?.name || ''
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || 'U'
})

const firstName = computed(() => {
  return auth.user?.name?.split(/\s+/)[0] || 'Account'
})

const menuLabel = computed(() => mobileOpen.value ? 'Close menu' : 'Open menu')

function isActive(item) {
  if (item.to === '/') return route.path === '/'
  const root = '/' + item.to.split('/').filter(Boolean)[0]
  return route.path === root || route.path.startsWith(root + '/')
}

function closeMenus() {
  switcherOpen.value = false
  mobileOpen.value = false
}

function requestLogout() {
  mobileOpen.value = false
  logoutConfirmOpen.value = true
}

function closeLogoutConfirm() {
  if (loggingOut.value) return
  logoutConfirmOpen.value = false
}

async function logout() {
  if (loggingOut.value) return

  loggingOut.value = true

  try {
    await auth.logout()
    logoutConfirmOpen.value = false
    await router.replace('/login')
  } finally {
    loggingOut.value = false
  }
}

watch(() => route.fullPath, closeMenus)

watch(mobileOpen, (open) => {
  if (open) {
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = previousOverflow
  }
})

onBeforeUnmount(() => {
  document.body.style.overflow = previousOverflow
})
</script>

<template>
  <header class="app-header">
    <div class="app-header__wrapper">
      <div class="app-header__row">
        <div class="app-header__left">
          <div class="brand-lockup" style="position: relative;">
            <PlatformLogo />

            <span class="brand-lockup__divider" aria-hidden="true" />

            <div class="brand-lockup__switcher">
              <button
                type="button"
                class="brand-lockup__product"
                :aria-expanded="switcherOpen"
                aria-haspopup="menu"
                @click.stop="switcherOpen = !switcherOpen"
              >
                HOLIDAYS
                <FontAwesomeIcon :icon="faChevronDown" />
              </button>
            </div>

            <SoftwareSwitcher
              :open="switcherOpen"
              @close="switcherOpen = false"
            />
          </div>

          <nav class="desktop-nav" aria-label="Primary navigation">
            <RouterLink
              v-for="item in navItems"
              :key="item.to"
              :to="item.to"
              class="desktop-nav__item"
              :class="{ 'desktop-nav__item--active': isActive(item) }"
              :data-label="item.label"
              :aria-label="item.label"
            >
              <FontAwesomeIcon :icon="item.icon" />
              <span>{{ item.label }}</span>
            </RouterLink>
          </nav>
        </div>

        <div class="app-header__right">
          <button
            type="button"
            class="header-book-button"
            @click="emit('book-time-off')"
          >
            <FontAwesomeIcon :icon="faPlaneDeparture" />
            <span>Book time off</span>
          </button>

          <div class="header-user" :aria-label="`Signed in as ${auth.user?.name || 'user'}`">
            <span class="header-user__avatar">{{ initials }}</span>
            <span class="header-user__name">{{ firstName }}</span>
          </div>

          <button type="button" class="header-signout" @click="requestLogout">
            <FontAwesomeIcon :icon="faRightFromBracket" />
            <span>Sign out</span>
          </button>

          <button
            type="button"
            class="mobile-menu-button"
            :aria-expanded="mobileOpen"
            :aria-label="menuLabel"
            @click="mobileOpen = !mobileOpen"
          >
            <span class="hamburger" :class="{ 'hamburger--open': mobileOpen }">
              <i />
              <i />
              <i />
            </span>
          </button>
        </div>
      </div>

      <Transition name="mobile-menu">
        <div v-if="mobileOpen" class="mobile-menu">
          <button
            type="button"
            class="mobile-menu__book"
            @click="emit('book-time-off'); mobileOpen = false"
          >
            <FontAwesomeIcon :icon="faPlaneDeparture" />
            Book time off
          </button>

          <nav class="mobile-menu__nav" aria-label="Mobile navigation">
            <RouterLink
              v-for="item in navItems"
              :key="item.to"
              :to="item.to"
              class="mobile-menu__item"
              :class="{ 'mobile-menu__item--active': isActive(item) }"
            >
              <FontAwesomeIcon :icon="item.icon" />
              <span>{{ item.label }}</span>
            </RouterLink>

            <button
              type="button"
              class="mobile-menu__item mobile-menu__item--button"
              @click="requestLogout"
            >
              <FontAwesomeIcon :icon="faRightFromBracket" />
              <span>Sign out</span>
            </button>
          </nav>
        </div>
      </Transition>
    </div>
  </header>

  <Teleport to="body">
    <Transition name="mobile-overlay">
      <button
        v-if="mobileOpen"
        type="button"
        class="mobile-menu-backdrop"
        aria-label="Close menu"
        @click="mobileOpen = false"
      />
    </Transition>
  </Teleport>

  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="logoutConfirmOpen"
        class="modal-shell"
        role="dialog"
        aria-modal="true"
        aria-labelledby="logout-confirm-title"
      >
        <button
          class="modal-shell__backdrop"
          type="button"
          aria-label="Close sign out confirmation"
          @click="closeLogoutConfirm"
        />

        <section class="logout-confirm-modal">
          <header>
            <div>
              <div class="eyebrow">SIGN OUT</div>
              <h2 id="logout-confirm-title">Are you sure you want to log out?</h2>
            </div>
          </header>

          <div class="logout-confirm-modal__body">
            <p>You’ll need to sign in again to access Platform Holidays.</p>
          </div>

          <footer>
            <button
              class="button button--secondary"
              type="button"
              :disabled="loggingOut"
              @click="closeLogoutConfirm"
            >
              Cancel
            </button>

            <button
              class="button button--primary"
              type="button"
              :disabled="loggingOut"
              @click="logout"
            >
              <FontAwesomeIcon :icon="faRightFromBracket" />
              {{ loggingOut ? 'Signing out…' : 'Sign out' }}
            </button>
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>

</template>

<style scoped>
/* Patch 33 — desktop nav alignment + icon tooltips */

@media (min-width: 769px) {
  .app-header__left {
    display: flex;
    min-width: 0;
    flex: 1 1 auto;
    align-items: center;
  }

  .desktop-nav {
    margin-left: auto;
  }

  .app-header__right {
    margin-left: 20px;
  }
}

/*
 * At the responsive icon-only width, reveal the link name on hover/focus.
 * The normal full-width text labels are left untouched.
 */
@media (min-width: 769px) and (max-width: 1180px) {
  .desktop-nav__item {
    position: relative;
    overflow: visible;
  }

  .desktop-nav__item::after {
    position: absolute;
    z-index: 250;
    top: calc(100% + 9px);
    left: 50%;
    padding: 6px 8px;
    border: 1px solid #2b2b2b;
    background: #121212;
    color: #f5f3ef;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
    content: attr(data-label);
    font-size: 10px;
    font-weight: 600;
    line-height: 1;
    opacity: 0;
    pointer-events: none;
    transform: translate(-50%, -4px);
    transition:
      opacity 120ms ease,
      transform 120ms ease;
    white-space: nowrap;
  }

  .desktop-nav__item:hover::after,
  .desktop-nav__item:focus-visible::after {
    opacity: 1;
    transform: translate(-50%, 0);
  }
}
</style>

<style scoped>
/* Patch 33A — icon-only nav at 1380px */
@media (min-width: 769px) and (max-width: 1380px) {
  .desktop-nav__item {
    position: relative;
    min-width: 44px;
    justify-content: center;
    padding-right: 12px;
    padding-left: 12px;
    overflow: visible;
  }

  .desktop-nav__item > span {
    display: none;
  }

  .desktop-nav__item::after {
    position: absolute;
    z-index: 250;
    top: calc(100% + 9px);
    left: 50%;
    padding: 6px 8px;
    border: 1px solid #2b2b2b;
    background: #121212;
    color: #f5f3ef;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
    content: attr(data-label);
    font-size: 10px;
    font-weight: 600;
    line-height: 1;
    opacity: 0;
    pointer-events: none;
    transform: translate(-50%, -4px);
    transition:
      opacity 120ms ease,
      transform 120ms ease;
    white-space: nowrap;
  }

  .desktop-nav__item:hover::after,
  .desktop-nav__item:focus-visible::after {
    opacity: 1;
    transform: translate(-50%, 0);
  }
}


/* PATCH_54_LOGOUT_CONFIRM */
.logout-confirm-modal {
  position: relative;
  z-index: 2;
  width: min(460px, calc(100vw - 32px));
  border: 1px solid #2e2e2e;
  background: #111;
}

.logout-confirm-modal > header,
.logout-confirm-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 20px;
}

.logout-confirm-modal > header {
  border-bottom: 1px solid #262626;
}

.logout-confirm-modal > footer {
  justify-content: flex-end;
  border-top: 1px solid #262626;
}

.logout-confirm-modal h2 {
  margin: 3px 0 0;
  color: #eee9e3;
  font-size: 18px;
  line-height: 1.25;
}

.logout-confirm-modal__body {
  padding: 20px;
}

.logout-confirm-modal__body p {
  margin: 0;
  color: #918b85;
  font-size: 11px;
  line-height: 1.6;
}

@media (max-width: 480px) {
  .logout-confirm-modal > footer {
    display: grid;
    grid-template-columns: 1fr 1fr;
    padding: 14px;
  }

  .logout-confirm-modal > footer .button {
    width: 100%;
    justify-content: center;
  }
}

</style>
