<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['update:modelValue'])

const day = ref('')
const month = ref('')
const year = ref('')

const error = computed(() => {
  if (!day.value && !month.value && !year.value) return ''

  if (!day.value || !month.value || !year.value) {
    return 'Enter day, month and year.'
  }

  const numericDay = Number(day.value)
  const numericMonth = Number(month.value)
  const numericYear = Number(year.value)

  if (
    !Number.isInteger(numericDay)
    || !Number.isInteger(numericMonth)
    || !Number.isInteger(numericYear)
  ) {
    return 'Enter a valid date.'
  }

  if (numericYear < 1900 || numericYear > new Date().getFullYear()) {
    return 'Enter a valid year.'
  }

  const date = new Date(
    numericYear,
    numericMonth - 1,
    numericDay,
  )

  const valid =
    date.getFullYear() === numericYear
    && date.getMonth() === numericMonth - 1
    && date.getDate() === numericDay

  return valid ? '' : 'Enter a valid date.'
})

function hydrate(value) {
  if (!value) {
    day.value = ''
    month.value = ''
    year.value = ''
    return
  }

  const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})$/)

  if (!match) return

  year.value = String(Number(match[1]))
  month.value = String(Number(match[2]))
  day.value = String(Number(match[3]))
}

function emitValue() {
  if (!day.value && !month.value && !year.value) {
    emit('update:modelValue', '')
    return
  }

  if (error.value) return

  const paddedMonth = String(month.value).padStart(2, '0')
  const paddedDay = String(day.value).padStart(2, '0')

  emit(
    'update:modelValue',
    `${year.value}-${paddedMonth}-${paddedDay}`,
  )
}

function clamp(field) {
  if (field === 'day' && day.value) {
    day.value = String(Math.min(31, Math.max(1, Number(day.value) || 1)))
  }

  if (field === 'month' && month.value) {
    month.value = String(Math.min(12, Math.max(1, Number(month.value) || 1)))
  }

  emitValue()
}

watch(
  () => props.modelValue,
  (value) => hydrate(value),
  { immediate: true },
)
</script>

<template>
  <div class="birth-date-input">
    <div class="birth-date-input__grid">
      <label>
        <input
          v-model="day"
          type="number"
          inputmode="numeric"
          min="1"
          max="31"
          placeholder="DD"
          @blur="clamp('day')"
          @input="emitValue"
        />
      </label>

      <label>
        <input
          v-model="month"
          type="number"
          inputmode="numeric"
          min="1"
          max="12"
          placeholder="MM"
          @blur="clamp('month')"
          @input="emitValue"
        />
      </label>

      <label>
        <input
          v-model="year"
          type="number"
          inputmode="numeric"
          min="1900"
          :max="new Date().getFullYear()"
          placeholder="YYYY"
          @input="emitValue"
        />
      </label>
    </div>

    <small v-if="error" class="birth-date-input__error">
      {{ error }}
    </small>
  </div>
</template>

<style scoped>
.birth-date-input {
  display: grid;
  gap: 6px;
}

.birth-date-input__grid {
  display: grid;
  grid-template-columns: 90px 110px minmax(130px, 1fr);
  gap: 8px;
}

.birth-date-input label {
  display: grid;
  gap: 5px;
}

.birth-date-input label > span {
  color: #77716b;
  font-size: 9px;
  font-weight: 600;
}

.birth-date-input input {
  width: 100%;
  height: 44px;
  padding: 0 12px;
  border: 1px solid #373737;
  outline: 0;
  background: #151515;
  color: #eee9e3;
  font: inherit;
  font-size: 11px;
  box-sizing: border-box;
}

.birth-date-input input:focus {
  border-color: #ef5b3f;
}

.birth-date-input input::-webkit-outer-spin-button,
.birth-date-input input::-webkit-inner-spin-button {
  margin: 0;
  appearance: none;
}

.birth-date-input__error {
  color: #ef8a75;
  font-size: 9px;
}

@media (max-width: 560px) {
  .birth-date-input__grid {
    grid-template-columns: 1fr 1fr 1.35fr;
  }
}
</style>
