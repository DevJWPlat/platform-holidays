<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  faCalendarDay,
  faChevronDown,
  faChevronLeft,
  faChevronRight,
  faClock,
  faFilter,
  faPlaneDeparture,
  faRotateRight,
  faXmark,
} from '@fortawesome/free-solid-svg-icons'
import api from '@/api/client'
import { getLeaveTypeIcon } from '@/utils/leaveTypeIcons'

const props = defineProps({
  requestRefreshKey: {
    type: Number,
    default: 0,
  },
})

defineEmits(['book-time-off'])

const people = ref([])
const departments = ref([])
const leaveRequests = ref([])
const loading = ref(true)
const leaveLoading = ref(false)
const error = ref('')
const filterOpen = ref(false)
const selectedDepartment = ref('all')
const bankHolidayMap = ref({})
const wallchartAllowances = ref({})
const selectedLeave = ref(null)
const calendarScroller = ref(null)
const dragging = ref(false)

let dragStartX = 0
let dragStartScrollLeft = 0
let snapTimer = null

const today = startOfDay(new Date())
const selectedYear = ref(today.getFullYear())
const visibleMonthLabel = ref('')
const DAY_WIDTH = 40

function startOfDay(date) {
  const value = new Date(date)
  value.setHours(0, 0, 0, 0)
  return value
}

function startOfWeek(date) {
  const value = startOfDay(date)
  const day = value.getDay()
  const difference = day === 0 ? -6 : 1 - day
  value.setDate(value.getDate() + difference)
  return value
}

function addDays(date, amount) {
  const value = new Date(date)
  value.setDate(value.getDate() + amount)
  return value
}

function sameDay(a, b) {
  return (
    a.getFullYear() === b.getFullYear()
    && a.getMonth() === b.getMonth()
    && a.getDate() === b.getDate()
  )
}

function dateKey(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function personInitials(name = '') {
  return String(name)
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || '?'
}



function primaryDepartment(person) {
  const personDepartments = Array.isArray(person?.departments) ? person.departments : []

  return personDepartments.find((department) => department?.pivot?.is_primary)
    || personDepartments[0]
    || null
}

function formatDate(value) {
  if (!value) return '—'

  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(`${value}T12:00:00`))
}

function leavePeriodText(item) {
  if (!item) return ''

  if (item.starts_on === item.ends_on) {
    if (item.start_session === 'morning' && item.end_session === 'morning') {
      return `${formatDate(item.starts_on)} · Morning`
    }

    if (item.start_session === 'afternoon') {
      return `${formatDate(item.starts_on)} · Afternoon`
    }

    return formatDate(item.starts_on)
  }

  return `${formatDate(item.starts_on)} — ${formatDate(item.ends_on)}`
}

const yearStart = computed(() => new Date(selectedYear.value, 0, 1))
const yearEnd = computed(() => new Date(selectedYear.value, 11, 31))

const dates = computed(() => {
  const totalDays = Math.round((yearEnd.value - yearStart.value) / 86400000) + 1

  return Array.from({ length: totalDays }, (_, index) => {
    const date = addDays(yearStart.value, index)
    const day = date.getDay()

    return {
      value: date,
      key: dateKey(date),
      day: new Intl.DateTimeFormat('en-GB', { weekday: 'narrow' }).format(date),
      date: date.getDate(),
      month: date.getMonth(),
      weekend: day === 0 || day === 6,
      today: sameDay(date, today),
    }
  })
})

const periodLabel = computed(() => String(selectedYear.value))

function updateVisibleMonthLabel() {
  const scroller = calendarScroller.value

  if (!scroller || !dates.value.length) {
    visibleMonthLabel.value = new Intl.DateTimeFormat('en-GB', {
      month: 'long',
    }).format(yearStart.value)
    return
  }

  const startIndex = Math.max(
    0,
    Math.min(
      dates.value.length - 1,
      Math.floor(scroller.scrollLeft / DAY_WIDTH),
    ),
  )

  const endIndex = Math.max(
    startIndex,
    Math.min(
      dates.value.length - 1,
      Math.floor((scroller.scrollLeft + scroller.clientWidth - 1) / DAY_WIDTH),
    ),
  )

  const startDate = dates.value[startIndex]?.value
  const endDate = dates.value[endIndex]?.value

  if (!startDate || !endDate) return

  const formatter = new Intl.DateTimeFormat('en-GB', { month: 'long' })
  const startMonth = formatter.format(startDate)
  const endMonth = formatter.format(endDate)

  visibleMonthLabel.value = startMonth === endMonth
    ? startMonth
    : `${startMonth} / ${endMonth}`
}

const filteredPeople = computed(() => {
  if (selectedDepartment.value === 'all') return people.value

  return people.value.filter((person) =>
    (person.departments || []).some(
      (department) => String(department.id) === String(selectedDepartment.value),
    ),
  )
})

const selectedDepartmentName = computed(() => {
  if (selectedDepartment.value === 'all') return 'All departments'

  return departments.value.find(
    (department) => String(department.id) === String(selectedDepartment.value),
  )?.name || 'Department'
})

const peopleMeta = computed(() => {
  if (loading.value) return 'Loading people…'
  if (error.value) return 'Unable to load people'

  const count = filteredPeople.value.length
  const total = people.value.length

  if (selectedDepartment.value !== 'all') {
    return `${count} of ${total} ${total === 1 ? 'person' : 'people'}`
  }

  return `${total} ${total === 1 ? 'person' : 'people'}`
})

function requestsForCell(personId, day) {
  return leaveRequests.value.filter((item) => {
    if (String(item.user_id) !== String(personId)) return false
    return day >= item.starts_on && day <= item.ends_on
  })
}

function segmentStyle(item, day) {
  let left = 0
  let right = 0

  if (day === item.starts_on && item.start_session === 'afternoon') {
    left = 50
  }

  if (day === item.ends_on && item.end_session === 'morning') {
    right = 50
  }

  return {
    '--leave-colour': item.leave_type?.colour || '#ef5b3f',
    '--leave-icon-colour': item.leave_type?.icon_colour === 'black' ? '#090909' : '#ffffff',
    left: `${left}%`,
    right: `${right}%`,
  }
}

function segmentClasses(item, day) {
  return {
    'wallchart-leave--pending': item.status === 'pending',
    'wallchart-leave--approved': item.status === 'approved',
    'wallchart-leave--starts': day === item.starts_on,
    'wallchart-leave--ends': day === item.ends_on,
  }
}

async function loadPeopleData() {
  loading.value = true
  error.value = ''

  try {
    const [peopleResponse, departmentsResponse] = await Promise.all([
      api.get('/api/v1/people', { timeout: 10000 }),
      api.get('/api/v1/departments', { timeout: 10000 }),
    ])

    people.value = Array.isArray(peopleResponse.data?.data)
      ? peopleResponse.data.data.map((person) => ({
          ...person,
          avatarFailed: false,
        }))
      : []

    departments.value = Array.isArray(departmentsResponse.data?.data)
      ? departmentsResponse.data.data
      : []
  } catch (requestError) {
    console.error('Failed to load wallchart people', requestError)
    error.value = 'We could not load the wallchart data. Please try again.'
  } finally {
    loading.value = false
  }
}

async function loadLeaveData() {
  leaveLoading.value = true

  try {
    const response = await api.get('/api/v1/wallchart/leave', {
      params: {
        from: dateKey(yearStart.value),
        to: dateKey(yearEnd.value),
      },
      timeout: 10000,
    })

    leaveRequests.value = Array.isArray(response.data?.data)
      ? response.data.data
      : []
  } catch (requestError) {
    console.error('Failed to load wallchart leave', requestError)
  } finally {
    leaveLoading.value = false
  }
}

function applyBankHolidayMap(items) {
  bankHolidayMap.value = Object.fromEntries((Array.isArray(items) ? items : []).map((item) => [String(item.date).slice(0, 10), item]))
}

async function loadBankHolidays() {
  try {
    const year = Number(selectedYear.value) || new Date().getFullYear()
    const response = await api.get('/api/v1/bank-holidays', {
      params: {
        from: String(year) + '-01-01',
        to: String(year) + '-12-31',
      },
      timeout: 10000,
    })

    bankHolidayMap.value = Object.fromEntries(
      (response.data?.data || []).map((item) => [
        String(item.date).slice(0, 10),
        item,
      ]),
    )
  } catch (requestError) {
    console.error('Could not load wallchart bank holidays', requestError)
    bankHolidayMap.value = {}
  }
}

function isBankHoliday(dateKey) {
  return Boolean(bankHolidayMap.value[String(dateKey).slice(0, 10)])
}

function wallchartAllowanceFor(personId) {
  const key = String(personId)

  if (!Object.prototype.hasOwnProperty.call(wallchartAllowances.value, key)) {
    return null
  }

  const value = Number(wallchartAllowances.value[key])

  return Number.isFinite(value) ? value : null
}

function formatWallchartAllowance(value) {
  const number = Number(value)

  if (!Number.isFinite(number)) return ''

  return Number.isInteger(number)
    ? String(number)
    : String(Number(number.toFixed(2)))
}

async function loadWallchartAllowances() {
  try {
    const year = Number(selectedYear.value) || new Date().getFullYear()

    const response = await api.get('/api/v1/wallchart/allowances', {
      params: {
        date: String(year) + '-01-01',
      },
      timeout: 10000,
    })

    wallchartAllowances.value = Object.fromEntries(
      (Array.isArray(response.data?.data) ? response.data.data : []).map((item) => [
        String(item.user_id),
        item.remaining_days,
      ]),
    )
  } catch (requestError) {
    console.error('Could not load wallchart allowances', requestError)
    wallchartAllowances.value = {}
  }
}


async function loadWallchartData() {
  await Promise.all([
    loadPeopleData(),
    loadLeaveData(),
    loadWallchartAllowances(),
  ])
}

async function scrollToDate(date, behaviour = 'auto', align = 'left') {
  await nextTick()

  const scroller = calendarScroller.value
  if (!scroller) return

  const targetKey = dateKey(date)
  const index = dates.value.findIndex((item) => item.key === targetKey)

  if (index < 0) {
    scroller.scrollTo({ left: 0, behavior: behaviour })
    updateVisibleMonthLabel()
    return
  }

  const targetLeft = align === 'center'
    ? Math.max(
        0,
        (index * DAY_WIDTH) - ((scroller.clientWidth - DAY_WIDTH) / 2),
      )
    : Math.max(0, index * DAY_WIDTH)

  scroller.scrollTo({
    left: targetLeft,
    behavior: behaviour,
  })

  window.setTimeout(updateVisibleMonthLabel, behaviour === 'smooth' ? 320 : 0)
}

function visibleAnchorDate() {
  const scroller = calendarScroller.value

  if (!scroller || !dates.value.length) {
    return new Date(selectedYear.value, 0, 1)
  }

  const index = Math.max(
    0,
    Math.min(
      dates.value.length - 1,
      Math.floor(scroller.scrollLeft / DAY_WIDTH),
    ),
  )

  return dates.value[index]?.value || new Date(selectedYear.value, 0, 1)
}

async function movePeriodByMonth(amount) {
  const current = visibleAnchorDate()
  const target = new Date(
    current.getFullYear(),
    current.getMonth() + amount,
    1,
  )

  if (target.getFullYear() !== selectedYear.value) {
    selectedYear.value = target.getFullYear()
    await nextTick()
  }

  await scrollToDate(target, 'smooth')
}

async function previousPeriod() {
  await movePeriodByMonth(-1)
}

async function nextPeriod() {
  await movePeriodByMonth(1)
}

async function goToToday() {
  selectedYear.value = today.getFullYear()
  await nextTick()
  await scrollToDate(today, 'smooth', 'left')
}

function setDepartment(id) {
  selectedDepartment.value = id
  filterOpen.value = false
}

function onPointerDown(event) {
  if (event.pointerType === 'mouse' && event.button !== 0) return
  if (event.target.closest('.wallchart-leave')) return

  const scroller = calendarScroller.value
  if (!scroller) return

  dragging.value = true
  dragStartX = event.clientX
  dragStartScrollLeft = scroller.scrollLeft
  scroller.setPointerCapture?.(event.pointerId)
}

function onPointerMove(event) {
  if (!dragging.value || !calendarScroller.value) return

  const distance = event.clientX - dragStartX
  calendarScroller.value.scrollLeft = dragStartScrollLeft - distance
  updateVisibleMonthLabel()
}

function snapCalendarToDay() {
  const scroller = calendarScroller.value
  if (!scroller) return

  const snappedLeft = Math.round(scroller.scrollLeft / DAY_WIDTH) * DAY_WIDTH

  scroller.scrollTo({
    left: snappedLeft,
    behavior: 'smooth',
  })

  window.setTimeout(updateVisibleMonthLabel, 220)
}

function scheduleSnap() {
  if (dragging.value) return

  if (snapTimer) {
    window.clearTimeout(snapTimer)
  }

  snapTimer = window.setTimeout(() => {
    snapCalendarToDay()
  }, 120)
}

function stopDragging(event) {
  if (!dragging.value) return

  dragging.value = false
  calendarScroller.value?.releasePointerCapture?.(event.pointerId)

  if (snapTimer) {
    window.clearTimeout(snapTimer)
  }

  snapTimer = window.setTimeout(() => {
    snapCalendarToDay()
  }, 80)
}

function openLeave(item) {
  selectedLeave.value = item
}

function closeLeave() {
  selectedLeave.value = null
}

watch(selectedYear, () => {
  loadLeaveData()
  loadWallchartAllowances()
  loadBankHolidays()
})

watch(
  () => props.requestRefreshKey,
  () => {
    loadLeaveData()
    loadWallchartAllowances()
  },
)

onMounted(async () => {
  await loadWallchartData()

  if (typeof loadBankHolidays === 'function') {
    loadBankHolidays()
  }

  await nextTick()

  if (selectedYear.value === today.getFullYear()) {
    await scrollToDate(today)
  } else {
    updateVisibleMonthLabel()
  }
})

onBeforeUnmount(() => {
  dragging.value = false

  if (snapTimer) {
    window.clearTimeout(snapTimer)
  }
})
</script>

<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <div class="eyebrow">TEAM AVAILABILITY</div>
        <h1>Wallchart</h1>
        <p>See who's away across Platform.</p>
      </div>

      <button
        class="button button--primary page-heading__action"
        type="button"
        @click="$emit('book-time-off')"
      >
        <FontAwesomeIcon :icon="faPlaneDeparture" />
        Book time off
      </button>
    </div>

    <section class="wallchart-panel">
      <div class="wallchart-toolbar">
        <div class="wallchart-toolbar__left">
          <div class="wallchart-filter">
            <button
              class="button button--secondary button--compact"
              type="button"
              :aria-expanded="filterOpen"
              @click="filterOpen = !filterOpen"
            >
              <FontAwesomeIcon :icon="faFilter" />
              Filters
              <FontAwesomeIcon :icon="faChevronDown" class="wallchart-filter__chevron" />
            </button>

            <div v-if="filterOpen" class="wallchart-filter__popover">
              <div class="wallchart-filter__header">
                <div>
                  <strong>Department</strong>
                  <span>Choose who appears on the wallchart.</span>
                </div>

                <button
                  class="wallchart-filter__close"
                  type="button"
                  aria-label="Close filters"
                  @click="filterOpen = false"
                >
                  <FontAwesomeIcon :icon="faXmark" />
                </button>
              </div>

              <button
                class="wallchart-filter__option"
                :class="{ 'wallchart-filter__option--active': selectedDepartment === 'all' }"
                type="button"
                @click="setDepartment('all')"
              >
                <span class="wallchart-filter__dot wallchart-filter__dot--all" />
                <span>
                  <strong>All departments</strong>
                  <small>{{ people.length }} {{ people.length === 1 ? 'person' : 'people' }}</small>
                </span>
              </button>

              <button
                v-for="department in departments"
                :key="department.id"
                class="wallchart-filter__option"
                :class="{
                  'wallchart-filter__option--active':
                    String(selectedDepartment) === String(department.id),
                }"
                type="button"
                @click="setDepartment(department.id)"
              >
                <span
                  class="wallchart-filter__dot"
                  :style="{ backgroundColor: department.colour || '#ef5b3f' }"
                />
                <span>
                  <strong>{{ department.name }}</strong>
                  <small>
                    {{
                      people.filter((person) =>
                        (person.departments || []).some(
                          (item) => String(item.id) === String(department.id),
                        ),
                      ).length
                    }}
                    people
                  </small>
                </span>
              </button>
            </div>
          </div>

          <span class="toolbar-meta">{{ peopleMeta }}</span>

          <button
            v-if="selectedDepartment !== 'all'"
            class="wallchart-filter__active"
            type="button"
            @click="setDepartment('all')"
          >
            {{ selectedDepartmentName }}
            <FontAwesomeIcon :icon="faXmark" />
          </button>

          <span v-if="leaveLoading" class="wallchart-leave-loading">
            Updating leave…
          </span>
        </div>

        <div class="wallchart-toolbar__right">
          <button
            class="button button--ghost button--compact"
            type="button"
            @click="goToToday"
          >
            Today
          </button>

          <div class="button-pair">
            <button
              class="icon-button"
              type="button"
              aria-label="Previous period"
              @click="previousPeriod"
            >
              <FontAwesomeIcon :icon="faChevronLeft" />
            </button>

            <button
              class="icon-button"
              type="button"
              aria-label="Next period"
              @click="nextPeriod"
            >
              <FontAwesomeIcon :icon="faChevronRight" />
            </button>
          </div>
        </div>
      </div>

      <div v-if="error" class="wallchart-error">
        <div>
          <strong>Wallchart unavailable</strong>
          <span>{{ error }}</span>
        </div>

        <button class="button button--secondary button--compact" type="button" @click="loadWallchartData">
          <FontAwesomeIcon :icon="faRotateRight" />
          Try again
        </button>
      </div>

      <div v-else class="wallchart">
        <aside class="wallchart__people">
          <div class="wallchart__people-month-spacer" aria-hidden="true" />

          <div class="wallchart__people-heading">
            <strong>People</strong>
            <span>{{ periodLabel }}</span>
          </div>

          <template v-if="loading">
            <div v-for="index in 4" :key="`loading-person-${index}`" class="wallchart-person wallchart-person--loading">
              <span class="wallchart-person__avatar wallchart-person__avatar--skeleton" />
              <span class="wallchart-person__copy">
                <span class="wallchart-person__line wallchart-person__line--name" />
                <span class="wallchart-person__line wallchart-person__line--meta" />
              </span>
            </div>
          </template>

          <template v-else-if="filteredPeople.length">
            <div v-for="person in filteredPeople" :key="person.id" class="wallchart-person">
              <div class="wallchart-person__avatar">
                <img
                  v-if="person.avatar_url && !person.avatarFailed"
                  :src="person.avatar_url"
                  :alt="person.name"
                  @error="person.avatarFailed = true"
                />
                <span v-else>{{ personInitials(person.name) }}</span>
              </div>

              <div class="wallchart-person__copy">
                <div class="wallchart-person__name-row">
                  <strong>{{ person.name }}</strong>

                  <span
                    v-if="wallchartAllowanceFor(person.id) !== null"
                    class="wallchart-person__allowance"
                    :title="`${formatWallchartAllowance(wallchartAllowanceFor(person.id))} holiday days remaining`"
                  >
                    {{ formatWallchartAllowance(wallchartAllowanceFor(person.id)) }}d left
                  </span>
                </div>

                <span>
                  {{ primaryDepartment(person)?.name || person.job_title || 'Platform' }}
                </span>
              </div>
            </div>
          </template>

          <div v-else class="wallchart-empty-person">
            <div class="wallchart-empty-person__avatar">—</div>
            <div>
              <strong>No people found</strong>
              <span v-if="selectedDepartment !== 'all'">
                Nobody is assigned to {{ selectedDepartmentName }}.
              </span>
              <span v-else>People will appear here once they are added.</span>
            </div>
          </div>
        </aside>

        <div class="wallchart__calendar-column">
          <div class="wallchart-month-heading">
            <strong>{{ visibleMonthLabel || 'January' }}</strong>
            <span>{{ selectedYear }}</span>
          </div>

          <div
            ref="calendarScroller"
            class="wallchart__calendar wallchart__calendar--draggable"
            :style="{ '--wallchart-days': dates.length }"
            :class="{ 'wallchart__calendar--dragging': dragging }"
            @scroll="() => { updateVisibleMonthLabel(); scheduleSnap() }"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="stopDragging"
            @pointercancel="stopDragging"
            @pointerleave="stopDragging"
          >
            <div class="wallchart__calendar-inner">
            <div class="wallchart__date-header">
              <div v-for="date in dates"
                :key="date.key"
                class="wallchart-date"
                :class="{
                  'wallchart-date--weekend': date.weekend,
                  'wallchart-date--today': date.today,
                  'wallchart-date--bank-holiday': isBankHoliday(date.key),
                }"
              >
                <span>{{ date.day }}</span>
                <strong>{{ date.date }}</strong>
              </div>
            </div>

            <template v-if="loading">
              <div
                v-for="row in 4"
                :key="`loading-row-${row}`"
                class="wallchart-grid-row wallchart-grid-row--person"
              >
                <div
                  v-for="date in dates"
                  :key="`loading-${row}-${date.key}`"
                  class="wallchart-grid-cell"
                  :class="{
                    'wallchart-grid-cell--weekend': date.weekend,
                    'wallchart-grid-cell--today': date.today,
                    'wallchart-grid-cell--bank-holiday': isBankHoliday(date.key),
}"
                >
                <div
                  v-if="isBankHoliday(date.key)"
                  class="wallchart-bank-holiday"
                  :title="bankHolidayMap[date.key]?.title || 'Bank holiday'"
                >
                  <span class="wallchart-bank-holiday__icon">BH</span>
                </div>
              </div>
              </div>
            </template>

            <template v-else-if="filteredPeople.length">
              <div
                v-for="person in filteredPeople"
                :key="`calendar-${person.id}`"
                class="wallchart-grid-row wallchart-grid-row--person"
              >
                <div v-for="date in dates"
                  :key="`${person.id}-${date.key}`"
                  class="wallchart-grid-cell wallchart-grid-cell--leave"
                  :class="{
                    'wallchart-grid-cell--weekend': date.weekend,
                    'wallchart-grid-cell--today': date.today,
                    'wallchart-grid-cell--bank-holiday': isBankHoliday(date.key),
}"
                >
                  <button
                    v-for="item in requestsForCell(person.id, date.key)"
                    :key="`${item.id}-${date.key}`"
                    class="wallchart-leave"
                    :class="segmentClasses(item, date.key)"
                    :style="segmentStyle(item, date.key)"
                    type="button"
                    :title="`${item.leave_type?.label || 'Time off'} · ${item.status}`"
                    @click.stop="openLeave(item)"
                  >
                    <FontAwesomeIcon
                      class="wallchart-leave__icon"
                      :icon="getLeaveTypeIcon(item.leave_type?.icon)"
                    />
                  </button>
                </div>
              </div>
            </template>

            <div v-else class="wallchart-grid-row wallchart-grid-row--empty">
              <div
                v-for="date in dates"
                :key="`empty-${date.key}`"
                class="wallchart-grid-cell"
                :class="{
                  'wallchart-grid-cell--weekend': date.weekend,
                  'wallchart-grid-cell--today': date.today,
                    'wallchart-grid-cell--bank-holiday': isBankHoliday(date.key),
}"
              >
                <div
                  v-if="isBankHoliday(date.key)"
                  class="wallchart-bank-holiday"
                  :title="bankHolidayMap[date.key]?.title || 'Bank holiday'"
                >
                  <span class="wallchart-bank-holiday__icon">BH</span>
                </div>
              </div>
            </div>
            </div>
          </div>
        </div>
      </div>

      <div class="wallchart-legend">
        <span>
          <i class="wallchart-legend__sample wallchart-legend__sample--approved" />
          Approved
        </span>
        <span>
          <i class="wallchart-legend__sample wallchart-legend__sample--pending" />
          Pending
        </span>
        <span>
          <i class="wallchart-legend__sample wallchart-legend__sample--bank-holiday" />
          Bank holiday
        </span>
        <span class="wallchart-legend__hint">
          Drag the calendar left or right to move across the visible dates.
        </span>
      </div>
    </section>

    <Teleport to="body">
      <Transition name="modal">
        <div
          v-if="selectedLeave"
          class="modal-shell"
          role="dialog"
          aria-modal="true"
          aria-labelledby="wallchart-leave-title"
        >
          <button
            class="modal-shell__backdrop"
            type="button"
            aria-label="Close leave details"
            @click="closeLeave"
          />

          <section class="wallchart-leave-modal">
            <header>
              <div>
                <div class="eyebrow">TIME OFF</div>
                <h2 id="wallchart-leave-title">
                  {{ selectedLeave.leave_type?.label || 'Time off' }}
                </h2>
              </div>

              <button class="icon-button" type="button" aria-label="Close" @click="closeLeave">
                <FontAwesomeIcon :icon="faXmark" />
              </button>
            </header>

            <div class="wallchart-leave-modal__body">
              <div class="wallchart-leave-modal__status">
                <span
                  class="wallchart-leave-modal__colour"
                  :style="{ backgroundColor: selectedLeave.leave_type?.colour || '#ef5b3f' }"
                />
                <strong>{{ selectedLeave.leave_type?.label }}</strong>
                <span
                  class="wallchart-leave-modal__badge"
                  :class="`wallchart-leave-modal__badge--${selectedLeave.status}`"
                >
                  <FontAwesomeIcon :icon="selectedLeave.status === 'pending' ? faClock : faCalendarDay" />
                  {{ selectedLeave.status === 'pending' ? 'Pending approval' : 'Approved' }}
                </span>
              </div>

              <dl>
                <div>
                  <dt>Dates</dt>
                  <dd>{{ leavePeriodText(selectedLeave) }}</dd>
                </div>
                <div>
                  <dt>Duration</dt>
                  <dd>
                    {{ selectedLeave.duration_days }}
                    {{ selectedLeave.duration_days === 1 ? 'day' : 'days' }}
                  </dd>
                </div>
              </dl>
            </div>
          </section>
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<style scoped>
.wallchart-filter {
  position: relative;
}

.wallchart-filter__chevron {
  margin-left: 2px;
  font-size: 9px;
  opacity: 0.7;
}

.wallchart-filter__popover {
  position: absolute;
  z-index: 40;
  top: calc(100% + 8px);
  left: 0;
  width: 310px;
  padding: 8px;
  border: 1px solid var(--border, #2d2d2d);
  background: #111111;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
}

.wallchart-filter__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 10px 10px 12px;
  border-bottom: 1px solid #262626;
}

.wallchart-filter__header > div {
  display: grid;
  gap: 3px;
}

.wallchart-filter__header strong {
  color: #f4f1ed;
  font-size: 12px;
}

.wallchart-filter__header span {
  color: #868686;
  font-size: 10px;
  line-height: 1.45;
}

.wallchart-filter__close {
  display: grid;
  width: 28px;
  height: 28px;
  flex: 0 0 28px;
  place-items: center;
  border: 0;
  background: transparent;
  color: #858585;
  cursor: pointer;
}

.wallchart-filter__close:hover {
  color: #f4f1ed;
}

.wallchart-filter__option {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border: 0;
  background: transparent;
  color: #d9d5d0;
  text-align: left;
  cursor: pointer;
}

.wallchart-filter__option:hover,
.wallchart-filter__option--active {
  background: #1a1a1a;
}

.wallchart-filter__option--active {
  box-shadow: inset 2px 0 0 #ef5b3f;
}

.wallchart-filter__option > span:last-child {
  display: grid;
  gap: 2px;
}

.wallchart-filter__option strong {
  font-size: 11px;
  font-weight: 600;
}

.wallchart-filter__option small {
  color: #737373;
  font-size: 9px;
}

.wallchart-filter__dot {
  width: 8px;
  height: 8px;
  flex: 0 0 8px;
  border-radius: 50%;
}

.wallchart-filter__dot--all {
  background: #ef5b3f;
}

.wallchart-filter__active {
  display: inline-flex;
  min-height: 28px;
  align-items: center;
  gap: 6px;
  padding: 0 9px;
  border: 1px solid #343434;
  background: #171717;
  color: #b8b3ad;
  font: inherit;
  font-size: 9px;
  cursor: pointer;
}

.wallchart-leave-loading {
  color: #666;
  font-size: 9px;
}

.wallchart-error {
  display: flex;
  min-height: 120px;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 24px;
  border-top: 1px solid #262626;
}

.wallchart-error > div {
  display: grid;
  gap: 5px;
}

.wallchart-error strong {
  color: #f3efea;
  font-size: 12px;
}

.wallchart-error span {
  color: #777;
  font-size: 10px;
}

.wallchart-person,
.wallchart-person--loading {
  display: flex;
  min-height: 64px;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-top: 1px solid #242424;
}

.wallchart-person__avatar {
  display: grid;
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  overflow: hidden;
  place-items: center;
  border: 1px solid #343434;
  background: #1a1a1a;
  color: #d7d2cc;
  font-size: 9px;
  font-weight: 700;
}

.wallchart-person__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.wallchart-person__copy {
  display: grid;
  min-width: 0;
  gap: 3px;
}

.wallchart-person__copy strong {
  overflow: hidden;
  color: #e7e2dc;
  font-size: 10px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.wallchart-person__copy > span {
  overflow: hidden;
  color: #6f6f6f;
  font-size: 9px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.wallchart-grid-row--person {
  min-height: 64px;
}

.wallchart-person__avatar--skeleton,
.wallchart-person__line {
  animation: wallchart-pulse 1.4s ease-in-out infinite;
  background: #202020;
}

.wallchart-person__line {
  display: block;
  height: 7px;
}

.wallchart-person__line--name {
  width: 90px;
}

.wallchart-person__line--meta {
  width: 60px;
}

.wallchart__calendar--draggable {
  overflow-x: auto;
  overscroll-behavior-x: contain;
  cursor: grab;
  scrollbar-width: thin;
  scrollbar-color: #333 #111;
  touch-action: pan-y;
  user-select: none;
}

.wallchart__calendar--dragging {
  cursor: grabbing;
}

.wallchart__calendar--dragging * {
  cursor: grabbing !important;
}

.wallchart__calendar-column {
  min-width: 0;
  overflow: hidden;
}

.wallchart-month-heading {
  display: flex;
  height: 54px;
  align-items: baseline;
  gap: 10px;
  padding: 12px 16px 8px;
  border-bottom: 1px solid #242424;
  background: #101010;
}

.wallchart-month-heading strong {
  color: #f1ece6;
  font-size: 24px;
  font-weight: 600;
  letter-spacing: -0.5px;
}

.wallchart-month-heading span {
  color: #666;
  font-size: 11px;
}

.wallchart__calendar-inner {
  width: max-content;
  min-width: 100%;
}

.wallchart__date-header,
.wallchart-grid-row {
  grid-template-columns: repeat(var(--wallchart-days, 365), 40px);
}

.wallchart-grid-cell--leave {
  position: relative;
  overflow: visible;
}

.wallchart-leave {
  position: absolute;
  z-index: 4;
  top: 12px;
  bottom: 12px;
  min-width: 4px;
  overflow: hidden;
  padding: 0;
  border: 0;
  background: var(--leave-colour);
  color: #101010;
  cursor: pointer;
}

.wallchart-leave--starts {
  margin-left: 2px;
}

.wallchart-leave--ends {
  margin-right: 2px;
}

.wallchart-leave--pending {
  background:
    repeating-linear-gradient(
      -45deg,
      var(--leave-colour),
      var(--leave-colour) 5px,
      color-mix(in srgb, var(--leave-colour) 42%, #111) 5px,
      color-mix(in srgb, var(--leave-colour) 42%, #111) 10px
    );
  opacity: 0.78;
}

.wallchart-leave:hover {
  z-index: 6;
  outline: 1px solid rgba(255, 255, 255, 0.5);
  filter: brightness(1.08);
}

.wallchart-leave__label {
  position: absolute;
  top: 50%;
  left: 7px;
  max-width: 90px;
  overflow: hidden;
  transform: translateY(-50%);
  font-size: 8px;
  font-weight: 700;
  line-height: 1;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.wallchart-legend {
  display: flex;
  min-height: 42px;
  align-items: center;
  gap: 16px;
  padding: 0 14px;
  border-top: 1px solid #242424;
  color: #777;
  font-size: 9px;
}

.wallchart-legend > span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.wallchart-legend__sample {
  width: 18px;
  height: 8px;
  background: #9fd356;
}

.wallchart-legend__sample--pending {
  background:
    repeating-linear-gradient(
      -45deg,
      #9fd356,
      #9fd356 4px,
      #42582a 4px,
      #42582a 8px
    );
  opacity: 0.8;
}

.wallchart-legend__hint {
  margin-left: auto;
}

.wallchart-leave-modal {
  position: relative;
  z-index: 2;
  width: min(460px, calc(100vw - 32px));
  border: 1px solid #2d2d2d;
  background: #111;
  box-shadow: 0 28px 80px rgba(0, 0, 0, 0.55);
}

.wallchart-leave-modal > header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 20px;
  border-bottom: 1px solid #252525;
}

.wallchart-leave-modal h2 {
  margin: 3px 0 0;
  color: #eee9e3;
  font-size: 18px;
}

.wallchart-leave-modal__body {
  display: grid;
  gap: 18px;
  padding: 20px;
}

.wallchart-leave-modal__status {
  display: flex;
  align-items: center;
  gap: 9px;
}

.wallchart-leave-modal__colour {
  width: 9px;
  height: 9px;
}

.wallchart-leave-modal__status strong {
  color: #e7e1da;
  font-size: 12px;
}

.wallchart-leave-modal__badge {
  display: inline-flex;
  min-height: 24px;
  align-items: center;
  gap: 5px;
  margin-left: auto;
  padding: 0 7px;
  border: 1px solid #343434;
  background: #181818;
  color: #9fd356;
  font-size: 9px;
  font-weight: 600;
}

.wallchart-leave-modal__badge--pending {
  color: #e6bd67;
}

.wallchart-leave-modal dl {
  display: grid;
  gap: 1px;
  margin: 0;
  background: #292929;
}

.wallchart-leave-modal dl > div {
  display: grid;
  grid-template-columns: 90px 1fr;
  gap: 18px;
  padding: 12px;
  background: #151515;
}

.wallchart-leave-modal dt {
  color: #727272;
  font-size: 9px;
}

.wallchart-leave-modal dd {
  margin: 0;
  color: #c8c2bc;
  font-size: 10px;
}

@keyframes wallchart-pulse {
  0%,
  100% {
    opacity: 0.45;
  }

  50% {
    opacity: 0.9;
  }
}

@media (max-width: 720px) {
  .wallchart-filter__popover {
    position: fixed;
    top: auto;
    right: 16px;
    bottom: 16px;
    left: 16px;
    width: auto;
  }

  .wallchart-month-heading {
    height: 48px;
    padding: 10px 12px 7px;
  }

  .wallchart-month-heading strong {
    font-size: 20px;
  }

  .wallchart-legend {
    align-items: flex-start;
    flex-wrap: wrap;
    padding-top: 10px;
    padding-bottom: 10px;
  }

  .wallchart-legend__hint {
    width: 100%;
    margin-left: 0;
  }
}

/* Patch 10B — keep the fixed People column aligned with the full-year calendar */
.wallchart__people-month-spacer {
  height: 54px;
  flex: 0 0 54px;
  border-bottom: 1px solid #242424;
  background: #101010;
}

/*
 * Force the date header and every person row onto the exact same 40px day
 * track. This prevents the header/grid lines drifting apart as the year is
 * scrolled.
 */
.wallchart__date-header,
.wallchart-grid-row {
  display: grid !important;
  grid-template-columns: repeat(var(--wallchart-days, 365), 40px) !important;
  grid-auto-columns: 40px !important;
  width: max-content;
  min-width: 100%;
}

.wallchart-date,
.wallchart-grid-cell {
  width: 40px !important;
  min-width: 40px !important;
  max-width: 40px !important;
  box-sizing: border-box;
  box-shadow: inset -1px 0 0 #2a2a2a;
}

/* Weekend shading must never remove the day divider. */
.wallchart-date--weekend,
.wallchart-grid-cell--weekend {
  box-shadow: inset -1px 0 0 #2a2a2a !important;
}

/* Keep the horizontal row boundaries lined up on both halves of the chart. */
.wallchart__date-header {
  border-bottom: 1px solid #2a2a2a;
}

.wallchart-grid-row--person {
  border-bottom: 1px solid #242424;
}

@media (max-width: 720px) {
  .wallchart__people-month-spacer {
    height: 48px;
    flex-basis: 48px;
  }
}


/* Patch 10C — always settle the horizontal position on a whole-day boundary. */
.wallchart__calendar--draggable {
  scroll-snap-type: x proximity;
}

.wallchart-date,
.wallchart-grid-cell {
  scroll-snap-align: start;
}


.wallchart-leave__icon{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:var(--leave-icon-colour,#fff);font-size:13px;pointer-events:none}.wallchart-leave__label{display:none!important}

/* Patch 20G wallchart alignment */
.wallchart-person,
.wallchart-person--loading,
.wallchart-grid-row--person {
  height: 64px !important;
  min-height: 64px !important;
  max-height: 64px !important;
  box-sizing: border-box;
}

.wallchart-person,
.wallchart-person--loading {
  border-top: 0;
  border-bottom: 1px solid #242424;
}

.wallchart-grid-row--person {
  border-bottom: 1px solid #242424;
}

.wallchart-grid-cell {
  height: 64px !important;
  min-height: 64px !important;
  max-height: 64px !important;
  box-sizing: border-box;
}

/* Fill the whole booking cell while preserving the grid lines. */
.wallchart-leave {
  top: 0 !important;
  bottom: 0 !important;
  height: 100% !important;
  min-height: 100% !important;
  margin-top: 0 !important;
  margin-bottom: 0 !important;
}

/* Keep leave segments aligned to the 40px day tracks. */
.wallchart-leave--starts {
  margin-left: 0 !important;
}

.wallchart-leave--ends {
  margin-right: 0 !important;
}

/* Keep the grid divider visible above filled leave blocks. */
.wallchart-grid-cell--leave {
  box-shadow:
    inset -1px 0 0 #2a2a2a,
    inset 0 -1px 0 #242424 !important;
}


/* Patch 22A bank holidays */
.wallchart-grid-cell {
  position: relative;
  overflow: hidden;
}

.wallchart-bank-holiday {
  position: absolute;
  inset: 1px;
  display: flex;
  align-items: center;
  justify-content: center;
  background:
    repeating-linear-gradient(-45deg, rgba(201, 201, 201, 0.2) 0, rgba(201, 201, 201, 0.2) 4px, rgba(234, 234, 234, 0.08) 4px, rgba(234, 234, 234, 0.08) 8px),
    rgba(190, 190, 190, 0.08);
  border: 1px solid rgba(180, 180, 180, 0.12);
  pointer-events: none;
  z-index: 1;
}

.wallchart-bank-holiday__icon {
  color: #d9d6d2;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
}


/* Patch 22B bank holiday cell fallback */
.wallchart-grid-cell--bank-holiday {
  position: relative;
  background:
    repeating-linear-gradient(
      -45deg,
      rgba(205, 205, 205, 0.18) 0,
      rgba(205, 205, 205, 0.18) 4px,
      rgba(255, 255, 255, 0.05) 4px,
      rgba(255, 255, 255, 0.05) 8px
    ) !important;
}

.wallchart-grid-cell--bank-holiday::after {
  content: '▦';
  position: absolute;
  z-index: 2;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  color: #ddd8d2;
  font-size: 15px;
  line-height: 1;
  pointer-events: none;
}


/* Patch 22D bank holiday visibility */
.wallchart-date--bank-holiday {
  background: repeating-linear-gradient(-45deg, rgba(205,205,205,0.16) 0, rgba(205,205,205,0.16) 4px, rgba(255,255,255,0.04) 4px, rgba(255,255,255,0.04) 8px);
}

.wallchart-grid-cell--bank-holiday {
  position: relative;
  background: repeating-linear-gradient(-45deg, rgba(205,205,205,0.20) 0, rgba(205,205,205,0.20) 4px, rgba(255,255,255,0.055) 4px, rgba(255,255,255,0.055) 8px) !important;
}

.wallchart-grid-cell--bank-holiday::after {
  content: 'BH';
  position: absolute;
  z-index: 1;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  color: #d7d2cc;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.08em;
  pointer-events: none;
}

.wallchart-leave {
  z-index: 2;
}

.wallchart-legend__sample--bank-holiday {
  background: repeating-linear-gradient(-45deg, #8d8d8d 0, #8d8d8d 4px, #4d4d4d 4px, #4d4d4d 8px);
}


/* Patch 25 core wallchart fixes */
.wallchart-person__allowance {
  display: block;
  margin-top: 1px;
  color: #9c958e;
  font-size: 9px;
  line-height: 1.3;
}

.wallchart-leave--pending {
  opacity: 1 !important;
  background:
    repeating-linear-gradient(
      -45deg,
      var(--leave-colour),
      var(--leave-colour) 6px,
      color-mix(in srgb, var(--leave-colour) 78%, white) 6px,
      color-mix(in srgb, var(--leave-colour) 78%, white) 11px
    ) !important;
}

.wallchart-leave__icon {
  display: block !important;
  z-index: 3;
}

.wallchart-date--bank-holiday,
.wallchart-grid-cell--bank-holiday {
  background:
    repeating-linear-gradient(
      -45deg,
      rgba(190, 190, 190, 0.22) 0,
      rgba(190, 190, 190, 0.22) 4px,
      rgba(245, 245, 245, 0.06) 4px,
      rgba(245, 245, 245, 0.06) 8px
    ) !important;
}

.wallchart-grid-cell--bank-holiday::after {
  content: 'BH';
  position: absolute;
  z-index: 1;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  color: #d6d1cb;
  font-size: 9px;
  font-weight: 800;
  letter-spacing: 0.06em;
  pointer-events: none;
}

.wallchart-grid-cell--bank-holiday .wallchart-leave {
  z-index: 4;
}





/* Patch 31B wallchart remaining balance */
.wallchart-person__name-row {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 6px;
}

.wallchart-person__name-row > strong {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.wallchart-person__allowance {
  display: inline-flex !important;
  flex: 0 0 auto;
  margin-top: 0 !important;
  padding: 2px 5px;
  background: rgba(159, 211, 86, 0.08);
  color: #9fd356 !important;
  font-size: 8px !important;
  font-weight: 700;
  line-height: 1.2 !important;
  white-space: nowrap;
}

</style>
