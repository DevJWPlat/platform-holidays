<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  faCalendarDay,
  faChevronDown,
  faChevronLeft,
  faChevronRight,
} from '@fortawesome/free-solid-svg-icons'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Choose date' },
  min: { type: String, default: '' },
  minDate: { type: String, default: '' },
  max: { type: String, default: '' },
  maxDate: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])

const root = ref(null)
const open = ref(false)
const monthMenuOpen = ref(false)
const yearMenuOpen = ref(false)
const yearList = ref(null)

const today = new Date()
today.setHours(0, 0, 0, 0)

function parseKey(value) {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(String(value))) return null
  const [year, month, day] = String(value).split('-').map(Number)
  const date = new Date(year, month - 1, day)
  date.setHours(0, 0, 0, 0)
  return date
}

function keyFor(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function displayDate(value) {
  const date = parseKey(value)
  if (!date) return ''
  return new Intl.DateTimeFormat('en-GB', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}

const effectiveMin = computed(() => props.minDate || props.min || '')
const effectiveMax = computed(() => props.maxDate || props.max || '')

const initialDate = () =>
  parseKey(props.modelValue)
  || parseKey(effectiveMin.value)
  || today

const viewDate = ref(
  new Date(initialDate().getFullYear(), initialDate().getMonth(), 1),
)

const months = [
  'January','February','March','April','May','June',
  'July','August','September','October','November','December',
]

const visibleMonth = computed(() => months[viewDate.value.getMonth()])
const visibleYear = computed(() => viewDate.value.getFullYear())

const minimumYear = computed(() => {
  const min = parseKey(effectiveMin.value)
  return min ? min.getFullYear() : 1900
})

const maximumYear = computed(() => {
  const max = parseKey(effectiveMax.value)
  return max ? max.getFullYear() : today.getFullYear() + 25
})

const years = computed(() => {
  const values = []
  for (let year = maximumYear.value; year >= minimumYear.value; year -= 1) {
    values.push(year)
  }
  return values
})

function addDays(date, amount) {
  const value = new Date(date)
  value.setDate(value.getDate() + amount)
  return value
}

const days = computed(() => {
  const year = viewDate.value.getFullYear()
  const month = viewDate.value.getMonth()
  const first = new Date(year, month, 1)
  const mondayOffset = first.getDay() === 0 ? 6 : first.getDay() - 1
  const start = addDays(first, -mondayOffset)

  return Array.from({ length: 42 }, (_, index) => {
    const date = addDays(start, index)
    const key = keyFor(date)
    return {
      key,
      day: date.getDate(),
      currentMonth: date.getMonth() === month,
      today: key === keyFor(today),
      selected: key === props.modelValue,
      disabled:
        Boolean(effectiveMin.value && key < effectiveMin.value)
        || Boolean(effectiveMax.value && key > effectiveMax.value),
    }
  })
})

function canMoveTo(date) {
  const first = keyFor(new Date(date.getFullYear(), date.getMonth(), 1))
  const last = keyFor(new Date(date.getFullYear(), date.getMonth() + 1, 0))
  if (effectiveMax.value && first > effectiveMax.value) return false
  if (effectiveMin.value && last < effectiveMin.value) return false
  return true
}

function moveMonth(amount) {
  const target = new Date(
    viewDate.value.getFullYear(),
    viewDate.value.getMonth() + amount,
    1,
  )
  if (canMoveTo(target)) viewDate.value = target
}

function chooseMonth(month) {
  const target = new Date(viewDate.value.getFullYear(), month, 1)
  if (canMoveTo(target)) viewDate.value = target
  monthMenuOpen.value = false
}

function chooseYear(year) {
  const target = new Date(year, viewDate.value.getMonth(), 1)
  if (canMoveTo(target)) {
    viewDate.value = target
  } else {
    const min = parseKey(effectiveMin.value)
    const max = parseKey(effectiveMax.value)
    if (min && year === min.getFullYear()) {
      viewDate.value = new Date(year, min.getMonth(), 1)
    } else if (max && year === max.getFullYear()) {
      viewDate.value = new Date(year, max.getMonth(), 1)
    }
  }
  yearMenuOpen.value = false
}

function chooseDay(day) {
  if (day.disabled) return
  emit('update:modelValue', day.key)
  emit('change', day.key)
  close()
}

async function toggleYearMenu() {
  yearMenuOpen.value = !yearMenuOpen.value
  monthMenuOpen.value = false
  if (yearMenuOpen.value) {
    await nextTick()
    yearList.value
      ?.querySelector('[data-current-year="true"]')
      ?.scrollIntoView({ block: 'center' })
  }
}

function toggleMonthMenu() {
  monthMenuOpen.value = !monthMenuOpen.value
  yearMenuOpen.value = false
}

function openPicker() {
  if (props.disabled) return
  const date = initialDate()
  viewDate.value = new Date(date.getFullYear(), date.getMonth(), 1)
  open.value = true
}

function close() {
  open.value = false
  monthMenuOpen.value = false
  yearMenuOpen.value = false
}

function onDocumentPointerDown(event) {
  if (root.value && !root.value.contains(event.target)) close()
}

function onKeydown(event) {
  if (event.key === 'Escape') close()
}

watch(
  () => props.modelValue,
  (value) => {
    const date = parseKey(value)
    if (date && !open.value) {
      viewDate.value = new Date(date.getFullYear(), date.getMonth(), 1)
    }
  },
)

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
  <div ref="root" class="custom-date-picker">
    <button
      class="custom-date-picker__trigger"
      type="button"
      :disabled="disabled"
      :aria-expanded="open"
      @click="open ? close() : openPicker()"
    >
      <span :class="{ 'custom-date-picker__placeholder': !modelValue }">
        {{ modelValue ? displayDate(modelValue) : placeholder }}
      </span>
      <FontAwesomeIcon :icon="faCalendarDay" />
    </button>

    <div v-if="open" class="custom-date-picker__popover">
      <div class="custom-date-picker__header">
        <button class="custom-date-picker__nav" type="button" aria-label="Previous month" @click="moveMonth(-1)">
          <FontAwesomeIcon :icon="faChevronLeft" />
        </button>

        <div class="custom-date-picker__jump">
          <div class="custom-date-picker__jump-control">
            <button class="custom-date-picker__jump-button" type="button" @click="toggleMonthMenu">
              <span>{{ visibleMonth }}</span>
              <FontAwesomeIcon :icon="faChevronDown" />
            </button>
            <div v-if="monthMenuOpen" class="custom-date-picker__menu">
              <button
                v-for="(month, index) in months"
                :key="month"
                type="button"
                :class="{ 'custom-date-picker__menu-item--active': index === viewDate.getMonth() }"
                @click="chooseMonth(index)"
              >
                {{ month }}
              </button>
            </div>
          </div>

          <div class="custom-date-picker__jump-control">
            <button class="custom-date-picker__jump-button" type="button" @click="toggleYearMenu">
              <span>{{ visibleYear }}</span>
              <FontAwesomeIcon :icon="faChevronDown" />
            </button>
            <div
              v-if="yearMenuOpen"
              ref="yearList"
              class="custom-date-picker__menu custom-date-picker__menu--years"
            >
              <button
                v-for="year in years"
                :key="year"
                type="button"
                :data-current-year="year === visibleYear ? 'true' : 'false'"
                :class="{ 'custom-date-picker__menu-item--active': year === visibleYear }"
                @click="chooseYear(year)"
              >
                {{ year }}
              </button>
            </div>
          </div>
        </div>

        <button class="custom-date-picker__nav" type="button" aria-label="Next month" @click="moveMonth(1)">
          <FontAwesomeIcon :icon="faChevronRight" />
        </button>
      </div>

      <div class="custom-date-picker__weekdays" aria-hidden="true">
        <span>Mo</span><span>Tu</span><span>We</span><span>Th</span>
        <span>Fr</span><span>Sa</span><span>Su</span>
      </div>

      <div class="custom-date-picker__grid">
        <button
          v-for="day in days"
          :key="day.key"
          type="button"
          class="custom-date-picker__day"
          :class="{
            'custom-date-picker__day--outside': !day.currentMonth,
            'custom-date-picker__day--today': day.today,
            'custom-date-picker__day--selected': day.selected,
            'custom-date-picker__day--disabled': day.disabled,
          }"
          :disabled="day.disabled"
          @click="chooseDay(day)"
        >
          {{ day.day }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.custom-date-picker { position: relative; width: 100%; }

.custom-date-picker__trigger {
  display: flex;
  width: 100%;
  min-height: 42px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 0 12px;
  border: 1px solid #343434;
  background: #151515;
  color: #eee9e3;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.custom-date-picker__trigger:hover,
.custom-date-picker__trigger:focus-visible {
  border-color: #4b4b4b;
  outline: 0;
}

.custom-date-picker__trigger:disabled { cursor: not-allowed; opacity: 0.55; }
.custom-date-picker__trigger svg { color: #aaa39d; }
.custom-date-picker__placeholder { color: #77716b; }

.custom-date-picker__popover {
  position: absolute;
  z-index: 1000;
  top: calc(100% + 6px);
  left: 0;
  width: 300px;
  padding: 12px;
  border: 1px solid #343434;
  background: #111;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.55);
}

.custom-date-picker__header {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr) 34px;
  gap: 7px;
  align-items: center;
  margin-bottom: 11px;
}

.custom-date-picker__nav {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  border: 1px solid #303030;
  background: #151515;
  color: #d8d3cd;
  cursor: pointer;
}

.custom-date-picker__nav:hover { border-color: #4b4b4b; color: #fff; }

.custom-date-picker__jump {
  display: flex;
  min-width: 0;
  align-items: center;
  justify-content: center;
  gap: 4px;
}

.custom-date-picker__jump-control { position: relative; min-width: 0; }

.custom-date-picker__jump-button {
  display: inline-flex;
  min-height: 34px;
  align-items: center;
  gap: 7px;
  padding: 0 8px;
  border: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.custom-date-picker__jump-button:hover { background: #181818; }
.custom-date-picker__jump-button svg { width: 9px; color: #817b75; }

.custom-date-picker__menu {
  position: absolute;
  z-index: 20;
  top: calc(100% + 4px);
  left: 50%;
  width: 142px;
  max-height: 228px;
  overflow-y: auto;
  transform: translateX(-50%);
  border: 1px solid #343434;
  background: #151515;
  box-shadow: 0 16px 34px rgba(0, 0, 0, 0.55);
}

.custom-date-picker__menu--years { width: 96px; }

.custom-date-picker__menu button {
  display: block;
  width: 100%;
  padding: 8px 10px;
  border: 0;
  border-bottom: 1px solid #292929;
  background: transparent;
  color: #aaa39d;
  font: inherit;
  font-size: 11px;
  text-align: left;
  cursor: pointer;
}

.custom-date-picker__menu button:last-child { border-bottom: 0; }

.custom-date-picker__menu button:hover,
.custom-date-picker__menu-item--active {
  background: #202020 !important;
  color: #f0ebe5 !important;
}

.custom-date-picker__weekdays,
.custom-date-picker__grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
}

.custom-date-picker__weekdays { margin-bottom: 4px; }

.custom-date-picker__weekdays span {
  display: grid;
  height: 25px;
  place-items: center;
  color: #68635e;
  font-size: 9px;
  font-weight: 700;
}

.custom-date-picker__day {
  display: grid;
  width: 100%;
  aspect-ratio: 1;
  place-items: center;
  border: 0;
  background: transparent;
  color: #e9e4de;
  font: inherit;
  font-size: 10px;
  cursor: pointer;
}

.custom-date-picker__day:hover:not(:disabled) { background: #202020; }

.custom-date-picker__day--outside,
.custom-date-picker__day--disabled { color: #55514d; }

.custom-date-picker__day--disabled { cursor: not-allowed; }

.custom-date-picker__day--today { box-shadow: inset 0 0 0 1px #57514c; }

.custom-date-picker__day--selected {
  background: #ef5b3f !important;
  color: #090909 !important;
  font-weight: 800;
}

@media (max-width: 480px) {
  .custom-date-picker__popover { width: min(300px, calc(100vw - 40px)); }
}
</style>
