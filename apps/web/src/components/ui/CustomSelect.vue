<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  faCheck,
  faChevronDown,
} from '@fortawesome/free-solid-svg-icons'

const props = defineProps({
  modelValue: {
    type: [String, Number],
    default: '',
  },
  options: {
    type: Array,
    default: () => [],
  },
  placeholder: {
    type: String,
    default: 'Select…',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue'])
const root = ref(null)
const open = ref(false)

const selected = computed(() =>
  props.options.find((option) => String(option.value) === String(props.modelValue)) || null,
)

function toggle() {
  if (props.disabled) return
  open.value = !open.value
}

function choose(option) {
  emit('update:modelValue', option.value)
  open.value = false
}

function onDocumentPointerDown(event) {
  if (!root.value?.contains(event.target)) {
    open.value = false
  }
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    open.value = false
  }
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="root" class="custom-select" :class="{ 'custom-select--open': open }">
    <button
      class="custom-select__button"
      type="button"
      :disabled="disabled"
      @click="toggle"
    >
      <span :class="{ 'custom-select__placeholder': !selected }">
        {{ selected?.label || placeholder }}
      </span>

      <FontAwesomeIcon
        class="custom-select__chevron"
        :icon="faChevronDown"
      />
    </button>

    <Transition name="dropdown">
      <div v-if="open" class="custom-select__menu">
        <button
          v-for="option in options"
          :key="String(option.value)"
          class="custom-select__option"
          :class="{
            'custom-select__option--selected':
              String(option.value) === String(modelValue),
          }"
          type="button"
          @click="choose(option)"
        >
          <span>{{ option.label }}</span>

          <FontAwesomeIcon
            v-if="String(option.value) === String(modelValue)"
            :icon="faCheck"
          />
        </button>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.custom-select {
  position: relative;
  width: 100%;
}

.custom-select__button {
  display: flex;
  width: 100%;
  min-height: 44px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 0 12px;
  border: 1px solid #373737;
  background: #151515;
  color: #eee9e3;
  font: inherit;
  font-size: 11px;
  text-align: left;
  cursor: pointer;
}

.custom-select__button:hover {
  border-color: #4a4a4a;
}

.custom-select--open .custom-select__button {
  border-color: #5a5a5a;
}

.custom-select__button:disabled {
  opacity: 0.5;
  cursor: default;
}

.custom-select__placeholder {
  color: #666;
}

.custom-select__chevron {
  color: #777;
  font-size: 10px;
  transition: transform 140ms ease;
}

.custom-select--open .custom-select__chevron {
  transform: rotate(180deg);
}

.custom-select__menu {
  position: absolute;
  z-index: 120;
  top: calc(100% + 6px);
  left: 0;
  width: 100%;
  max-height: 260px;
  overflow-y: auto;
  border: 1px solid #3b3b3b;
  background: #171717;
  box-shadow: 0 18px 45px rgba(0, 0, 0, 0.48);
}

.custom-select__option {
  display: flex;
  width: 100%;
  min-height: 42px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 0 12px;
  border: 0;
  border-bottom: 1px solid #292929;
  background: transparent;
  color: #d9d4ce;
  font: inherit;
  font-size: 11px;
  text-align: left;
  cursor: pointer;
}

.custom-select__option:last-child {
  border-bottom: 0;
}

.custom-select__option:hover,
.custom-select__option--selected {
  background: #202020;
}

.custom-select__option--selected {
  color: #f2ede7;
}

.custom-select__option svg {
  color: #ef5b3f;
  font-size: 9px;
}

.dropdown-enter-active,
.dropdown-leave-active {
  transition: opacity 120ms ease, transform 120ms ease;
}

.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
