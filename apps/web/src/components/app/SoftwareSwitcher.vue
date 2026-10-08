<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close'])
const root = ref(null)

function close() {
  emit('close')
}

function onDocumentClick(event) {
  if (!props.open) return
  if (root.value?.contains(event.target)) return

  const trigger = event.target.closest?.('.brand-lockup__product')
  if (trigger) return

  close()
}

function onKeydown(event) {
  if (event.key === 'Escape' && props.open) {
    close()
  }
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Transition name="software-switcher-menu">
    <div
      v-if="open"
      ref="root"
      class="software-switcher__menu"
      role="menu"
    >
      <p class="software-switcher__eyebrow">
        Platform software
      </p>

      <a
        href="https://tools.platform.team"
        class="software-switcher__item"
        role="menuitem"
        @click="close"
      >
        <svg
          class="software-switcher__item-icon"
          viewBox="0 0 20 20"
          aria-hidden="true"
        >
          <rect x="2.5" y="2.5" width="15" height="15" rx="1" fill="none" stroke="currentColor" stroke-width="2" />
          <path d="M10 3.5V16.5M3.5 10H16.5" fill="none" stroke="currentColor" stroke-width="2" />
        </svg>

        <span>Platform Tools</span>
      </a>

      <a
        href="https://forms.platform.team/dashboard"
        class="software-switcher__item"
        role="menuitem"
        @click="close"
      >
        <svg
          class="software-switcher__item-icon"
          viewBox="0 0 20 20"
          aria-hidden="true"
        >
          <rect x="2.5" y="3.5" width="15" height="13" rx="1" fill="none" stroke="currentColor" stroke-width="2" />
          <rect x="5.5" y="6.5" width="2.5" height="2.5" fill="currentColor" />
          <path d="M10 7.75H14.5M5.5 12.25H14.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>

        <span>Platform Forms</span>
      </a>
    </div>
  </Transition>
</template>

<style scoped>
.software-switcher__menu {
  position: absolute;
  z-index: 100;
  top: calc(100% + 12px);
  left: 0;
  width: 230px;
  padding: 8px;
  border: 1px solid #2b2b2b;
  background: #121212;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
}

.software-switcher__eyebrow {
  margin: 0;
  padding: 4px 12px 8px;
  color: #73706c;
  font-size: 11px;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.software-switcher__item {
  display: flex;
  min-height: 48px;
  align-items: center;
  gap: 12px;
  padding: 0 12px;
  color: #f5f3ef;
  font-size: 14px;
  font-weight: 600;
  line-height: 1;
  text-decoration: none;
  cursor: pointer;
  transition:
    background-color 150ms ease,
    color 150ms ease;
}

.software-switcher__item:hover,
.software-switcher__item:focus-visible {
  background: #0d0d0d;
  color: #ef5b3f;
  outline: none;
}

.software-switcher__item-icon {
  width: 18px;
  height: 18px;
  flex: 0 0 18px;
  color: #ef5b3f;
}

.software-switcher-menu-enter-active,
.software-switcher-menu-leave-active {
  transition:
    opacity 150ms ease,
    transform 150ms ease;
}

.software-switcher-menu-enter-from,
.software-switcher-menu-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
</style>
