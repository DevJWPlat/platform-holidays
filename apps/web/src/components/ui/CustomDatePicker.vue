<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import {
  faCalendarDays,
  faChevronLeft,
  faChevronRight,
} from '@fortawesome/free-solid-svg-icons'

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Choose date',
  },
  minDate: {
    type: String,
    default: '',
  },
  maxDate: {
    type: String,
    default: '',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  placement: {
    type: String,
    default: 'below',
    validator: (value) => ['below', 'above'].includes(value),
  },
})

const emit = defineEmits(['update:modelValue'])

const root = ref(null)
const open = ref(false)

function parseYmd(value) {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null

  const [year, month, day] = value.split('-').map(Number)
  return new Date(year, month - 1, day, 12, 0, 0, 0)
}

function formatYmd(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return year + '-' + month + '-' + day
}

function sameDay(a, b) {
  return !!a && !!b
    && a.getFullYear() === b.getFullYear()
    && a.getMonth() === b.getMonth()
    && a.getDate() === b.getDate()
}

const selectedDate = computed(() => parseYmd(props.modelValue))
const minDateObj = computed(() => parseYmd(props.minDate))
const maxDateObj = computed(() => parseYmd(props.maxDate))
const today = computed(() => {
  const now = new Date()
  return new Date(now.getFullYear(), now.getMonth(), now.getDate(), 12, 0, 0, 0)
})

const viewDate = ref(selectedDate.value || today.value)

watch(selectedDate, (value) => {
  if (value) {
    viewDate.value = value
  }
})

const displayValue = computed(() => {
  if (!selectedDate.value) return ''

  return new Intl.DateTimeFormat('en-GB', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(selectedDate.value)
})

const monthLabel = computed(() => new Intl.DateTimeFormat('en-GB', {
  month: 'long',
  year: 'numeric',
}).format(viewDate.value))

const weekdayLabels = ['M', 'T', 'W', 'T', 'F', 'S', 'S']

function startOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth(), 1, 12, 0, 0, 0)
}

function endOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth() + 1, 0, 12, 0, 0, 0)
}

function addMonths(date, amount) {
  return new Date(date.getFullYear(), date.getMonth() + amount, 1, 12, 0, 0, 0)
}

function isBeforeDay(a, b) {
  return a.getTime() < b.getTime()
}

function isAfterDay(a, b) {
  return a.getTime() > b.getTime()
}

function isDisabled(date) {
  if (minDateObj.value && isBeforeDay(date, minDateObj.value)) return true
  if (maxDateObj.value && isAfterDay(date, maxDateObj.value)) return true
  return false
}

const days = computed(() => {
  const monthStart = startOfMonth(viewDate.value)
  const firstDay = monthStart.getDay() === 0 ? 7 : monthStart.getDay()
  const gridStart = new Date(monthStart)
  gridStart.setDate(monthStart.getDate() - (firstDay - 1))

  const result = []

  for (let index = 0; index < 42; index += 1) {
    const date = new Date(gridStart)
    date.setDate(gridStart.getDate() + index)

    result.push({
      key: formatYmd(date),
      label: date.getDate(),
      date,
      currentMonth: date.getMonth() === viewDate.value.getMonth(),
      today: sameDay(date, today.value),
      selected: sameDay(date, selectedDate.value),
      disabled: isDisabled(date),
    })
  }

  return result
})

function openPicker() {
  if (props.disabled) return
  open.value = true
  viewDate.value = selectedDate.value || today.value
}

function closePicker() {
  open.value = false
}

function togglePicker() {
  if (open.value) {
    closePicker()
  } else {
    openPicker()
  }
}

function selectDate(day) {
  if (day.disabled) return

  emit('update:modelValue', day.key)
  closePicker()
}

function clearDate() {
  emit('update:modelValue', '')
  closePicker()
}

function selectToday() {
  if (isDisabled(today.value)) return

  emit('update:modelValue', formatYmd(today.value))
  viewDate.value = today.value
  closePicker()
}

function previousMonth() {
  viewDate.value = addMonths(viewDate.value, -1)
}

function nextMonth() {
  viewDate.value = addMonths(viewDate.value, 1)
}

function onDocumentClick(event) {
  if (!root.value) return
  if (root.value.contains(event.target)) return
  closePicker()
}

function onEscape(event) {
  if (event.key === 'Escape') {
    closePicker()
  }
}

onMounted(() => {
  document.addEventListener('mousedown', onDocumentClick)
  document.addEventListener('keydown', onEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocumentClick)
  document.removeEventListener('keydown', onEscape)
})
</script>

<template>
  <div ref="root" class="custom-date-picker" :class="{
      'is-open': open,
      'is-disabled': disabled,
      'custom-date-picker--above': placement === 'above',
    }">
    <button
      class="custom-date-picker__trigger"
      type="button"
      :disabled="disabled"
      @click="togglePicker"
    >
      <span :class="['custom-date-picker__value', { 'is-placeholder': !displayValue }]">
        {{ displayValue || placeholder }}
      </span>

      <FontAwesomeIcon
        class="custom-date-picker__icon"
        :icon="faCalendarDays"
      />
    </button>

    <div v-if="open" class="custom-date-picker__panel" role="dialog" aria-modal="false">
      <div class="custom-date-picker__header">
        <div class="custom-date-picker__title">{{ monthLabel }}</div>

        <div class="custom-date-picker__nav">
          <button type="button" class="custom-date-picker__nav-button" @click="previousMonth">
            <FontAwesomeIcon :icon="faChevronLeft" />
          </button>

          <button type="button" class="custom-date-picker__nav-button" @click="nextMonth">
            <FontAwesomeIcon :icon="faChevronRight" />
          </button>
        </div>
      </div>

      <div class="custom-date-picker__weekdays">
        <span v-for="label in weekdayLabels" :key="label">{{ label }}</span>
      </div>

      <div class="custom-date-picker__grid">
        <button
          v-for="day in days"
          :key="day.key"
          type="button"
          :disabled="day.disabled"
          :class="[
            'custom-date-picker__day',
            {
              'is-other-month': !day.currentMonth,
              'is-today': day.today,
              'is-selected': day.selected,
            },
          ]"
          @click="selectDate(day)"
        >
          {{ day.label }}
        </button>
      </div>

      <div class="custom-date-picker__footer">
        <button type="button" class="custom-date-picker__footer-button" @click="clearDate">
          Clear
        </button>

        <button
          type="button"
          class="custom-date-picker__footer-button custom-date-picker__footer-button--accent"
          :disabled="isDisabled(today)"
          @click="selectToday"
        >
          Today
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.custom-date-picker {
  position: relative;
}

.custom-date-picker__trigger {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  min-height: 52px;
  padding: 0 16px;
  border: 1px solid #353535;
  background: #0c0c0d;
  color: #f3efe9;
  text-align: left;
  cursor: pointer;
}

.custom-date-picker__trigger:hover {
  border-color: #4a4a4a;
}

.custom-date-picker__value {
  font-size: 16px;
  line-height: 1.2;
}

.custom-date-picker__value.is-placeholder {
  color: #7f7b77;
}

.custom-date-picker__icon {
  color: #a9a29a;
  font-size: 16px;
  flex: 0 0 auto;
}

.custom-date-picker__panel {
  position: absolute;
  left: 0;
  right: auto;
  top: calc(100% + 8px);
  bottom: auto;
  z-index: 50;
  width: min(100%, 430px);
  min-width: 320px;
  padding: 18px 18px 14px;
  border: 1px solid #353535;
  background: #141414;
  box-shadow: 0 24px 50px rgba(0, 0, 0, 0.45);
}

.custom-date-picker__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.custom-date-picker__title {
  color: #f3efe9;
  font-size: 18px;
  font-weight: 600;
}

.custom-date-picker__nav {
  display: flex;
  gap: 8px;
}

.custom-date-picker__nav-button {
  display: grid;
  place-items: center;
  width: 46px;
  height: 46px;
  border: 1px solid #353535;
  background: #121212;
  color: #d7d1ca;
  cursor: pointer;
}

.custom-date-picker__weekdays,
.custom-date-picker__grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 8px;
}

.custom-date-picker__weekdays {
  margin-bottom: 10px;
}

.custom-date-picker__weekdays span {
  display: grid;
  place-items: center;
  min-height: 24px;
  color: #6f6b68;
  font-size: 13px;
  font-weight: 600;
}

.custom-date-picker__day {
  display: grid;
  place-items: center;
  min-height: 44px;
  border: 1px solid transparent;
  background: transparent;
  color: #e7e1da;
  font-size: 15px;
  cursor: pointer;
}

.custom-date-picker__day:hover:not(:disabled) {
  border-color: #4b4039;
  background: rgba(255, 108, 76, 0.08);
}

.custom-date-picker__day.is-other-month {
  color: #595653;
}

.custom-date-picker__day.is-today {
  border-color: #6d564c;
}

.custom-date-picker__day.is-selected {
  border-color: #ff6c4c;
  background: rgba(255, 108, 76, 0.14);
  color: #ffb09d;
}

.custom-date-picker__day:disabled {
  color: #464341;
  cursor: not-allowed;
}

.custom-date-picker__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid #2c2c2c;
}

.custom-date-picker__footer-button {
  border: 0;
  background: transparent;
  color: #ff8a71;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
}

.custom-date-picker__footer-button:disabled {
  color: #575350;
  cursor: not-allowed;
}

.custom-date-picker.is-disabled .custom-date-picker__trigger {
  opacity: 0.65;
  cursor: not-allowed;
}

@media (max-width: 640px) {
  .custom-date-picker__panel {
    left: 0;
    right: 0;
    width: auto;
    min-width: 0;
    padding: 16px;
  }

  .custom-date-picker__nav-button {
    width: 42px;
    height: 42px;
  }

  .custom-date-picker__day {
    min-height: 40px;
  }
}

.custom-date-picker--above .custom-date-picker__panel {
  top: auto;
  bottom: calc(100% + 8px);
}

</style>
