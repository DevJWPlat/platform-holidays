<script setup>
const bankHolidayMap = ref({})
import { computed, onMounted, ref, watch } from 'vue'
import {
  faCakeCandles,
  faCalendarDay,
  faChevronLeft,
  faChevronRight,
  faClock,
  faPlaneDeparture,
  faRotateRight,
  faXmark,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import { getLeaveTypeIcon } from '@/utils/leaveTypeIcons'

const props = defineProps({
  requestRefreshKey: {
    type: Number,
    default: 0,
  },
})

defineEmits(['book-time-off'])

const requests = ref([])
const people = ref([])
const allowance = ref(null)
const loading = ref(true)
const error = ref('')
const selectedRequest = ref(null)
const actionId = ref(null)
const calendarMode = ref('month')

const today = startOfDay(new Date())
const currentMonth = ref(new Date(today.getFullYear(), today.getMonth(), 1))

function startOfDay(date) {
  const value = new Date(date)
  value.setHours(0, 0, 0, 0)
  return value
}

function addDays(date, amount) {
  const value = new Date(date)
  value.setDate(value.getDate() + amount)
  return value
}

function addMonths(date, amount) {
  return new Date(date.getFullYear(), date.getMonth() + amount, 1)
}

function dateKey(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function sameDay(a, b) {
  return (
    a.getFullYear() === b.getFullYear()
    && a.getMonth() === b.getMonth()
    && a.getDate() === b.getDate()
  )
}

function formatLongDate(value) {
  if (!value) return '—'

  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date(String(value) + 'T12:00:00'))
}

function formatDate(value) {
  if (!value) return '—'

  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(`${value}T12:00:00`))
}

function periodText(item) {
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

const monthLabel = computed(() =>
  new Intl.DateTimeFormat('en-GB', {
    month: 'long',
    year: 'numeric',
  }).format(currentMonth.value),
)

const monthDays = computed(() => {
  const year = currentMonth.value.getFullYear()
  const month = currentMonth.value.getMonth()
  const first = new Date(year, month, 1)
  const last = new Date(year, month + 1, 0)

  const mondayIndex = first.getDay() === 0 ? 6 : first.getDay() - 1
  const gridStart = addDays(first, -mondayIndex)

  const sundayIndex = last.getDay() === 0 ? 0 : 7 - last.getDay()
  const gridEnd = addDays(last, sundayIndex)

  const totalDays = Math.round((gridEnd - gridStart) / 86400000) + 1

  return Array.from({ length: totalDays }, (_, index) => {
    const date = addDays(gridStart, index)

    return {
      value: date,
      key: dateKey(date),
      day: date.getDate(),
      currentMonth: date.getMonth() === month,
      weekend: date.getDay() === 0 || date.getDay() === 6,
      today: sameDay(date, today),
    }
  })
})

const yearMonths = computed(() =>
  Array.from({ length: 12 }, (_, monthIndex) => {
    const year = currentMonth.value.getFullYear()
    const first = new Date(year, monthIndex, 1)
    const last = new Date(year, monthIndex + 1, 0)
    const mondayIndex = first.getDay() === 0 ? 6 : first.getDay() - 1
    const cells = []

    for (let index = 0; index < mondayIndex; index += 1) {
      cells.push(null)
    }

    for (let day = 1; day <= last.getDate(); day += 1) {
      const date = new Date(year, monthIndex, day)

      cells.push({
        key: dateKey(date),
        day,
        weekend: date.getDay() === 0 || date.getDay() === 6,
        today: sameDay(date, today),
      })
    }

    return {
      key: String(year) + '-' + String(monthIndex + 1),
      label: new Intl.DateTimeFormat('en-GB', {
        month: 'long',
      }).format(first),
      monthIndex,
      cells,
    }
  }),
)

const nonHolidayStats = computed(() => {
  const grouped = new Map()

  for (const item of activeRequests.value) {
    if (item.leave_type?.is_protected_holiday) continue

    const label = item.leave_type?.label || 'Time off'

    if (label.toLowerCase() === 'public holidays') continue

    const current = grouped.get(label) || {
      key: item.leave_type?.key || label,
      label,
      colour: item.leave_type?.colour || '#ef5b3f',
      icon: item.leave_type?.icon || 'calendar',
      iconColour: item.leave_type?.icon_colour === 'black' ? '#090909' : '#ffffff',
      days: 0,
    }

    current.days += Number(item.duration_days || 0)
    grouped.set(label, current)
  }

  const publicHolidayDays = Object.keys(bankHolidayMap.value || {})
    .filter((date) => date.startsWith(String(currentMonth.value.getFullYear()) + '-'))
    .length

  const items = []

  if (publicHolidayDays > 0) {
    items.push({
      key: 'public-holidays',
      label: 'Public Holidays',
      colour: '#7b7b7b',
      icon: 'calendar',
      days: publicHolidayDays,
      publicHoliday: true,
    })
  }

  return [
    ...items,
    ...Array.from(grouped.values())
      .filter((item) => item.days > 0)
      .sort((a, b) => b.days - a.days || a.label.localeCompare(b.label)),
  ]
})

const monthlyTimeOff = computed(() => {
  const year = currentMonth.value.getFullYear()
  const totals = Array.from({ length: 12 }, () => 0)

  for (const item of activeRequests.value) {
    if (item.leave_type?.is_protected_holiday) continue

    const cursor = new Date(String(item.starts_on) + 'T12:00:00')
    const end = new Date(String(item.ends_on) + 'T12:00:00')

    while (cursor <= end) {
      if (cursor.getFullYear() === year) {
        totals[cursor.getMonth()] += 1
      }

      cursor.setDate(cursor.getDate() + 1)
    }
  }

  const max = Math.max(1, ...totals)

  return totals.map((value, monthIndex) => ({
    label: new Intl.DateTimeFormat('en-GB', {
      month: 'narrow',
    }).format(new Date(year, monthIndex, 1)),
    value,
    height: Math.max(value > 0 ? 8 : 2, Math.round((value / max) * 68)),
  }))
})

const activeRequests = computed(() =>
  requests.value.filter((item) => ['pending', 'approved'].includes(item.status)),
)

const upcomingRequests = computed(() => {
  const todayKey = dateKey(today)

  return activeRequests.value
    .filter((item) => item.ends_on >= todayKey)
    .sort((a, b) => a.starts_on.localeCompare(b.starts_on))
    .slice(0, 6)
})

const pendingDays = computed(() =>
  requests.value
    .filter((item) => item.status === 'pending')
    .reduce((total, item) => total + Number(item.duration_days || 0), 0),
)

function birthdaysForDay(day) {
  const monthDay = String(day || '').slice(5, 10)
  if (!monthDay) return []

  return people.value.filter(
    (person) => person?.date_of_birth
      && String(person.date_of_birth).slice(5, 10) === monthDay,
  )
}

function birthdayNames(day) {
  return birthdaysForDay(day).map((person) => person.name).join(', ')
}

function requestsForDay(day) {
  return activeRequests.value.filter(
    (item) => day >= item.starts_on && day <= item.ends_on,
  )
}

function requestStyle(item, day) {
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
    left: `${left}%`,
    right: `${right}%`,
  }
}

function requestClasses(item, day) {
  return {
    'calendar-leave--pending': item.status === 'pending',
    'calendar-leave--approved': item.status === 'approved',
    'calendar-leave--starts': day === item.starts_on,
    'calendar-leave--ends': day === item.ends_on,
  }
}

async function loadData() {
  loading.value = true
  error.value = ''

  try {
    const [requestsResponse, allowanceResponse, peopleResponse] = await Promise.all([
      api.get('/api/v1/leave-requests'),
      api.get('/api/v1/allowance', {
        params: {
          date: dateKey(currentMonth.value),
        },
      }),
      api.get('/api/v1/people'),
    ])

    requests.value = requestsResponse.data?.data || []
    allowance.value = allowanceResponse.data?.data || null
    people.value = peopleResponse.data?.data || []
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load your calendar.'
  } finally {
    loading.value = false
  }
}

async function loadAllowanceOnly() {
  try {
    const response = await api.get('/api/v1/allowance', {
      params: {
        date: dateKey(currentMonth.value),
      },
    })

    allowance.value = response.data?.data || null
  } catch (requestError) {
    console.error(requestError)
  }
}

async function previousMonth() {
  currentMonth.value = addMonths(currentMonth.value, -1)
  await loadAllowanceOnly()
}

async function nextMonth() {
  currentMonth.value = addMonths(currentMonth.value, 1)
  await loadAllowanceOnly()
}

async function goToToday() {
  currentMonth.value = new Date(today.getFullYear(), today.getMonth(), 1)
  await loadAllowanceOnly()
}

function openRequest(item) {
  selectedRequest.value = item
}

function closeRequest() {
  if (actionId.value) return
  selectedRequest.value = null
}

async function cancelRequest(item) {
  if (!item?.can_cancel) return

  if (!window.confirm('Cancel this time-off request?')) return

  actionId.value = item.id
  error.value = ''

  try {
    await initialiseCsrf()
    await api.post(`/api/v1/leave-requests/${item.id}/cancel`)
    selectedRequest.value = null
    await loadData()
  } catch (requestError) {
    const errors = requestError.response?.data?.errors

    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not cancel this request.'
  } finally {
    actionId.value = null
  }
}

watch(
  () => props.requestRefreshKey,
  () => loadData(),
)


function myCalendarLeaveClass(item) {
  if (!item || item.starts_on !== item.ends_on) {
    return []
  }

  const morningOnly =
    item.start_session === 'morning'
    && item.end_session === 'morning'

  const afternoonOnly =
    item.start_session === 'afternoon'
    && item.end_session === 'afternoon'

  if (morningOnly) {
    return ['my-calendar-leave--half', 'my-calendar-leave--morning']
  }

  if (afternoonOnly) {
    return ['my-calendar-leave--half', 'my-calendar-leave--afternoon']
  }

  return []
}

function applyBankHolidayMap(items) {
  bankHolidayMap.value = Object.fromEntries((Array.isArray(items) ? items : []).map((item) => [String(item.date).slice(0, 10), item]))
}

async function loadBankHolidays() {
  try {
    const year = new Date().getFullYear()
    const response = await api.get('/api/v1/bank-holidays', {
      params: {
        from: String(year - 1) + '-01-01',
        to: String(year + 2) + '-12-31',
      },
    })

    applyBankHolidayMap(response.data?.data || [])
  } catch (requestError) {
    console.error('Could not load bank holidays', requestError)
    bankHolidayMap.value = {}
  }
}

function isBankHoliday(dateKey) {
  return Boolean(bankHolidayMap.value[String(dateKey || '').slice(0, 10)])
}

function bankHolidayTitle(dateKey) {
  return bankHolidayMap.value[String(dateKey || '').slice(0, 10)]?.title || 'Bank holiday'
}

onMounted(loadData)

function myCalendarLeaveStyle(item) {
  if (!item || item.starts_on !== item.ends_on) {
    return {
      width: '100%',
      left: '0',
      right: '0',
      marginLeft: '0',
      marginRight: '0',
    }
  }

  const morningOnly =
    item.start_session === 'morning'
    && item.end_session === 'morning'

  const afternoonOnly =
    item.start_session === 'afternoon'
    && item.end_session === 'afternoon'

  if (morningOnly) {
    return {
      width: '50%',
      left: '0',
      right: 'auto',
      marginLeft: '0',
      marginRight: 'auto',
    }
  }

  if (afternoonOnly) {
    return {
      width: '50%',
      left: '50%',
      right: '0',
      marginLeft: 'auto',
      marginRight: '0',
    }
  }

  return {
    width: '100%',
    left: '0',
    right: '0',
    marginLeft: '0',
    marginRight: '0',
  }
}


onMounted(loadBankHolidays)
</script>

<template>
  <section class="page">
    <div class="page-heading calendar-heading">
      <div>
        <div class="eyebrow">YOUR TIME OFF</div>
        <h1>My calendar</h1>
        <p>See your allowance, upcoming leave and booked time off.</p>
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

    <div v-if="error" class="calendar-error">
      <span>{{ error }}</span>

      <button
        class="button button--secondary button--compact"
        type="button"
        @click="loadData"
      >
        <FontAwesomeIcon :icon="faRotateRight" />
        Retry
      </button>
    </div>

    <section class="allowance-summary">
      <article class="allowance-card allowance-card--primary">
        <div class="eyebrow">REMAINING</div>
        <strong>
          {{ allowance?.remaining_days ?? '—' }}
          <small>days</small>
        </strong>
        <span>
          {{ formatLongDate(allowance?.leave_year?.start) }}
          —
          {{ formatLongDate(allowance?.leave_year?.end) }}
        </span>
      </article>

      <article class="allowance-card">
        <div class="eyebrow">TOTAL ALLOWANCE</div>
        <strong>
          {{ allowance?.granted_days ?? '—' }}
          <small>days</small>
        </strong>
        <span>Current leave year</span>
      </article>

      <article class="allowance-card">
        <div class="eyebrow">BOOKED / RESERVED</div>
        <strong>
          {{ allowance?.used_days ?? '—' }}
          <small>days</small>
        </strong>
        <span>Approved + pending holiday</span>
      </article>

      <article class="allowance-card">
        <div class="eyebrow">PENDING</div>
        <strong>
          {{ pendingDays }}
          <small>days</small>
        </strong>
        <span>Waiting for approval</span>
      </article>
    </section>

    <div class="calendar-layout">
      <section class="calendar-panel">
        <header class="calendar-toolbar">
          <div>
            <div class="calendar-toolbar__eyebrow-row">
              <div class="eyebrow">{{ calendarMode === 'month' ? 'MONTH' : 'YEAR' }}</div>

              <div class="calendar-view-switch" role="group" aria-label="Calendar view">
                <button
                  type="button"
                  :class="{ 'calendar-view-switch__button--active': calendarMode === 'month' }"
                  class="calendar-view-switch__button"
                  @click="calendarMode = 'month'"
                >
                  Month
                </button>

                <button
                  type="button"
                  :class="{ 'calendar-view-switch__button--active': calendarMode === 'year' }"
                  class="calendar-view-switch__button"
                  @click="calendarMode = 'year'"
                >
                  Year
                </button>
              </div>
            </div>

            <h2>
              {{ calendarMode === 'month' ? monthLabel : currentMonth.getFullYear() }}
            </h2>
          </div>

          <div class="calendar-toolbar__actions">
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
                aria-label="Previous month"
                @click="previousMonth"
              >
                <FontAwesomeIcon :icon="faChevronLeft" />
              </button>

              <button
                class="icon-button"
                type="button"
                aria-label="Next month"
                @click="nextMonth"
              >
                <FontAwesomeIcon :icon="faChevronRight" />
              </button>
            </div>
          </div>
        </header>

        <template v-if="calendarMode === 'month'">
        <div class="calendar-weekdays" aria-hidden="true">
          <span>Mon</span>
          <span>Tue</span>
          <span>Wed</span>
          <span>Thu</span>
          <span>Fri</span>
          <span>Sat</span>
          <span>Sun</span>
        </div>

        <div v-if="loading" class="calendar-loading">
          Loading calendar…
        </div>

        <div v-else class="calendar-grid">
          <div
            v-for="day in monthDays"
            :key="day.key"
            class="calendar-day"
            :class="[{
              'calendar-day--outside': !day.currentMonth,
              'calendar-day--weekend': day.weekend,
              'calendar-day--today': day.today,
            }, { 'my-calendar-day--bank-holiday': isBankHoliday(day.key || day.date_key || day.full_date) }]"
          >
            <div class="calendar-day__number">
              <span>{{ day.day }}</span>
              <small v-if="day.today">Today</small>
            </div>

            <div class="calendar-day__leave">
              <div
                v-for="person in birthdaysForDay(day.key)"
                :key="`birthday-${person.id}-${day.key}`"
                class="calendar-birthday-event"
                :title="`${person.name}'s birthday`"
              >
                <FontAwesomeIcon :icon="faCakeCandles" />
                <span>{{ person.name }}</span>
              </div>

              <button
                v-for="item in requestsForDay(day.key)"
                :key="`${item.id}-${day.key}`"
                class="calendar-leave my-calendar-leave-event"
                :class="requestClasses(item, day.key)"
                :style="[requestStyle(item, day.key), myCalendarLeaveStyle(item)]"
                type="button"
                @click="openRequest(item)"
              >
                <span>
                  {{ item.leave_type?.label || 'Time off' }}
                </span>
              </button>
            </div>
          </div>
        </div>

        </template>

        <div v-else class="calendar-year-grid">
          <section
            v-for="month in yearMonths"
            :key="month.key"
            class="year-month"
          >
            <h3>{{ month.label }}</h3>

            <div class="year-month__weekdays" aria-hidden="true">
              <span>M</span>
              <span>T</span>
              <span>W</span>
              <span>T</span>
              <span>F</span>
              <span>S</span>
              <span>S</span>
            </div>

            <div class="year-month__grid">
              <template
                v-for="(day, index) in month.cells"
                :key="day?.key || month.key + '-blank-' + index"
              >
                <span
                  v-if="!day"
                  class="year-day year-day--blank"
                />

                <button
                  v-else
                  class="year-day"
                  :class="{
                    'year-day--weekend': day.weekend,
                    'year-day--today': day.today,
                    'year-day--bank-holiday': isBankHoliday(day.key),
                    'year-day--birthday': birthdaysForDay(day.key).length,
                    'year-day--leave': requestsForDay(day.key).length,
                    'year-day--pending': requestsForDay(day.key).some((item) => item.status === 'pending'),
                  }"
                  :style="requestsForDay(day.key)[0]
                    ? {
                        '--year-leave-colour': requestsForDay(day.key)[0].leave_type?.colour || '#ef5b3f',
                        '--year-icon-colour': requestsForDay(day.key)[0].leave_type?.icon_colour === 'black'
                          ? '#090909'
                          : '#ffffff',
                      }
                    : undefined"
                  type="button"
                  :title="requestsForDay(day.key)[0]?.leave_type?.label || (birthdaysForDay(day.key).length ? `Birthday: ${birthdayNames(day.key)}` : bankHolidayTitle(day.key))"
                  @click="requestsForDay(day.key)[0] && openRequest(requestsForDay(day.key)[0])"
                >
                  <span>{{ day.day }}</span>

                  <FontAwesomeIcon
                    v-if="requestsForDay(day.key)[0]"
                    class="year-day__icon"
                    :icon="getLeaveTypeIcon(requestsForDay(day.key)[0].leave_type?.icon)"
                  />

                  <FontAwesomeIcon
                    v-else-if="birthdaysForDay(day.key).length"
                    class="year-day__birthday-icon"
                    :icon="faCakeCandles"
                  />

                  <span
                    v-else-if="isBankHoliday(day.key)"
                    class="year-day__bh"
                  >
                    BH
                  </span>
                </button>
              </template>
            </div>
          </section>
        </div>
      </section>

      <div class="calendar-sidebar">
        <aside class="upcoming-panel">
        <header class="upcoming-panel__header">
          <div>
            <div class="eyebrow">COMING UP</div>
            <h2>Upcoming leave</h2>
          </div>
        </header>

        <div v-if="loading" class="upcoming-empty">
          Loading…
        </div>

        <div v-else-if="!upcomingRequests.length" class="upcoming-empty">
          <FontAwesomeIcon :icon="faCalendarDay" />
          <strong>No upcoming leave</strong>
          <span>Your future time off will appear here.</span>
        </div>

        <div v-else class="upcoming-list">
          <button
            v-for="item in upcomingRequests"
            :key="item.id"
            class="upcoming-item"
            type="button"
            @click="openRequest(item)"
          >
            <span
              class="upcoming-item__colour"
              :style="{ backgroundColor: item.leave_type?.colour || '#ef5b3f' }"
            />

            <span class="upcoming-item__copy">
              <strong>{{ item.leave_type?.label || 'Time off' }}</strong>
              <small>{{ periodText(item) }}</small>
            </span>

            <span
              class="upcoming-item__status"
              :class="`upcoming-item__status--${item.status}`"
            >
              {{ item.status === 'pending' ? 'Pending' : 'Approved' }}
            </span>
          </button>
        </div>
      </aside>

      <div class="calendar-sidebar-stats">
        <section class="calendar-stats-panel">
          <header class="calendar-stats-panel__header">
            <div>
              <div class="eyebrow">STATS</div>
              <h2>Non-deductible leave</h2>
            </div>
          </header>

          <div
            v-if="!nonHolidayStats.length"
            class="calendar-stats-empty"
          >
            No non-holiday leave recorded this year.
          </div>

          <div v-else class="calendar-stats-list">
            <div
              v-for="item in nonHolidayStats"
              :key="item.key"
              class="calendar-stats-item"
            >
              <span
                class="calendar-stats-item__icon"
                :style="{
                  backgroundColor: item.colour,
                  color: item.iconColour || '#ffffff',
                }"
              >
                <span v-if="item.publicHoliday">BH</span>
                <FontAwesomeIcon
                  v-else
                  :icon="getLeaveTypeIcon(item.icon)"
                />
              </span>

              <strong>{{ item.label }}</strong>
              <span>
                {{ item.days }}
                {{ Number(item.days) === 1 ? 'day' : 'days' }}
              </span>
            </div>
          </div>
        </section>

        <section class="calendar-stats-panel">
          <header class="calendar-stats-panel__header">
            <div>
              <div class="eyebrow">YEAR</div>
              <h2>Time off</h2>
            </div>
          </header>

          <div class="calendar-time-chart">
            <div
              v-for="month in monthlyTimeOff"
              :key="month.label"
              class="calendar-time-chart__month"
            >
              <div class="calendar-time-chart__bar-wrap">
                <span
                  class="calendar-time-chart__bar"
                  :style="{ height: month.height + 'px' }"
                  :title="month.value + ' days'"
                />
              </div>
              <strong>{{ month.label }}</strong>
            </div>
          </div>
        </section>
      </div>
      </div>
    </div>

    <Teleport to="body">
      <Transition name="modal">
        <div
          v-if="selectedRequest"
          class="modal-shell"
          role="dialog"
          aria-modal="true"
          aria-labelledby="calendar-request-title"
        >
          <button
            class="modal-shell__backdrop"
            type="button"
            aria-label="Close request details"
            @click="closeRequest"
          />

          <section class="calendar-request-modal">
            <header>
              <div>
                <div class="eyebrow">TIME OFF</div>
                <h2 id="calendar-request-title">
                  {{ selectedRequest.leave_type?.label || 'Time off' }}
                </h2>
              </div>

              <button
                class="icon-button"
                type="button"
                aria-label="Close"
                @click="closeRequest"
              >
                <FontAwesomeIcon :icon="faXmark" />
              </button>
            </header>

            <div class="calendar-request-modal__body">
              <div class="calendar-request-modal__status">
                <span
                  class="calendar-request-modal__colour"
                  :style="{ backgroundColor: selectedRequest.leave_type?.colour || '#ef5b3f' }"
                />

                <strong>
                  {{ selectedRequest.status === 'pending' ? 'Pending approval' : 'Approved' }}
                </strong>

                <FontAwesomeIcon
                  :icon="selectedRequest.status === 'pending' ? faClock : faCalendarDay"
                />
              </div>

              <dl>
                <div>
                  <dt>Dates</dt>
                  <dd>{{ periodText(selectedRequest) }}</dd>
                </div>

                <div>
                  <dt>Duration</dt>
                  <dd>
                    {{ selectedRequest.duration_days }}
                    {{ selectedRequest.duration_days === 1 ? 'day' : 'days' }}
                  </dd>
                </div>

                <div v-if="selectedRequest.reason">
                  <dt>Reason</dt>
                  <dd>{{ selectedRequest.reason }}</dd>
                </div>
              </dl>
            </div>

            <footer>
              <button
                class="button button--secondary"
                type="button"
                @click="closeRequest"
              >
                Close
              </button>

              <button
                v-if="selectedRequest.can_cancel"
                class="button button--primary"
                type="button"
                :disabled="actionId === selectedRequest.id"
                @click="cancelRequest(selectedRequest)"
              >
                {{ actionId === selectedRequest.id ? 'Cancelling…' : 'Cancel request' }}
              </button>
            </footer>
          </section>
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<style scoped>
.calendar-heading {
  align-items: flex-end;
}

.calendar-error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
  padding: 11px 12px;
  border: 1px solid rgba(239, 91, 63, 0.4);
  background: rgba(239, 91, 63, 0.08);
  color: #f4a898;
  font-size: 10px;
}

.allowance-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1px;
  margin-bottom: 18px;
  border: 1px solid #262626;
  background: #262626;
}

.allowance-card {
  display: grid;
  gap: 7px;
  min-height: 122px;
  align-content: center;
  padding: 18px;
  background: #101010;
}

.allowance-card--primary {
  background: #151311;
}

.allowance-card strong {
  color: #f0ebe5;
  font-size: 27px;
  font-weight: 600;
  letter-spacing: -0.6px;
}

.allowance-card strong small {
  color: #6f6b66;
  font-size: 10px;
  font-weight: 500;
  letter-spacing: 0;
}

.allowance-card > span {
  color: #6e6a65;
  font-size: 9px;
}

.calendar-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 310px;
  gap: 18px;
  align-items: start;
}

.calendar-panel,
.upcoming-panel {
  border: 1px solid #262626;
  background: #101010;
}

.calendar-toolbar,
.upcoming-panel__header {
  display: flex;
  min-height: 72px;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 16px;
  border-bottom: 1px solid #262626;
}

.calendar-toolbar h2,
.upcoming-panel__header h2 {
  margin: 3px 0 0;
  color: #eee9e3;
  font-size: 19px;
  font-weight: 600;
}

.calendar-toolbar__actions {
  display: flex;
  align-items: center;
  gap: 9px;
}

.calendar-weekdays,
.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
}

.calendar-weekdays {
  min-height: 38px;
  border-bottom: 1px solid #272727;
}

.calendar-weekdays span {
  display: grid;
  place-items: center;
  border-right: 1px solid #242424;
  color: #656565;
  font-size: 8px;
  font-weight: 600;
  text-transform: uppercase;
}

.calendar-weekdays span:last-child {
  border-right: 0;
}

.calendar-grid {
  background: #242424;
  gap: 1px;
}

.calendar-day {
  position: relative;
  min-height: 118px;
  overflow: hidden;
  background: #101010;
}

.calendar-day--weekend {
  background: #0d0d0d;
}

.calendar-day--outside {
  background: #0b0b0b;
  opacity: 0.55;
}

.calendar-day--today {
  box-shadow: inset 0 0 0 1px #ef5b3f;
}

.calendar-day__number {
  display: flex;
  min-height: 32px;
  align-items: center;
  justify-content: space-between;
  padding: 7px 8px 5px;
}

.calendar-day__number > span {
  color: #85817c;
  font-size: 10px;
  font-weight: 600;
}

.calendar-day--today .calendar-day__number > span {
  color: #f2c0b5;
}

.calendar-day__number small {
  color: #ef5b3f;
  font-size: 7px;
  font-weight: 700;
  text-transform: uppercase;
}

.calendar-day__leave {
  position: relative;
  display: grid;
  gap: 4px;
  min-height: 74px;
  padding: 2px 4px 6px;
}

.calendar-leave {
  position: relative;
  min-height: 24px;
  overflow: hidden;
  padding: 0 5px;
  border: 0;
  background: var(--leave-colour);
  color: #111;
  text-align: left;
  cursor: pointer;
}

.calendar-leave--pending {
  background:
    repeating-linear-gradient(
      -45deg,
      var(--leave-colour),
      var(--leave-colour) 5px,
      color-mix(in srgb, var(--leave-colour) 42%, #111) 5px,
      color-mix(in srgb, var(--leave-colour) 42%, #111) 10px
    );
  opacity: 0.8;
}

.calendar-leave span {
  display: block;
  overflow: hidden;
  font-size: 8px;
  font-weight: 700;
  line-height: 24px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.upcoming-panel {
  overflow: hidden;
}

.upcoming-list {
  display: grid;
}

.upcoming-item {
  display: grid;
  grid-template-columns: 4px minmax(0, 1fr) auto;
  min-height: 74px;
  align-items: center;
  gap: 11px;
  padding: 0 13px 0 0;
  border: 0;
  border-bottom: 1px solid #232323;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.upcoming-item:last-child {
  border-bottom: 0;
}

.upcoming-item:hover {
  background: #151515;
}

.upcoming-item__colour {
  align-self: stretch;
}

.upcoming-item__copy {
  display: grid;
  min-width: 0;
  gap: 4px;
}

.upcoming-item__copy strong {
  color: #ddd7d1;
  font-size: 10px;
  font-weight: 600;
}

.upcoming-item__copy small {
  overflow: hidden;
  color: #74716d;
  font-size: 8px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.upcoming-item__status {
  padding: 4px 6px;
  border: 1px solid #333;
  background: #181818;
  color: #9fd356;
  font-size: 8px;
  font-weight: 600;
}

.upcoming-item__status--pending {
  color: #e6bd67;
}

.upcoming-empty,
.calendar-loading {
  display: grid;
  min-height: 240px;
  place-items: center;
  align-content: center;
  gap: 8px;
  padding: 24px;
  color: #686868;
  text-align: center;
  font-size: 9px;
}

.upcoming-empty svg {
  font-size: 20px;
}

.upcoming-empty strong {
  color: #cac4be;
  font-size: 11px;
}

.calendar-request-modal {
  position: relative;
  z-index: 2;
  width: min(500px, calc(100vw - 32px));
  border: 1px solid #2e2e2e;
  background: #111;
  box-shadow: 0 28px 80px rgba(0, 0, 0, 0.55);
}

.calendar-request-modal > header,
.calendar-request-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 20px;
}

.calendar-request-modal > header {
  border-bottom: 1px solid #252525;
}

.calendar-request-modal > footer {
  justify-content: flex-end;
  border-top: 1px solid #252525;
}

.calendar-request-modal h2 {
  margin: 3px 0 0;
  color: #eee9e3;
  font-size: 18px;
}

.calendar-request-modal__body {
  display: grid;
  gap: 18px;
  padding: 20px;
}

.calendar-request-modal__status {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #aaa49e;
}

.calendar-request-modal__colour {
  width: 9px;
  height: 9px;
}

.calendar-request-modal__status strong {
  color: #ddd7d1;
  font-size: 11px;
}

.calendar-request-modal__status svg {
  margin-left: auto;
  color: #777;
}

.calendar-request-modal dl {
  display: grid;
  gap: 1px;
  margin: 0;
  background: #292929;
}

.calendar-request-modal dl > div {
  display: grid;
  grid-template-columns: 85px 1fr;
  gap: 16px;
  padding: 12px;
  background: #151515;
}

.calendar-request-modal dt {
  color: #727272;
  font-size: 9px;
}

.calendar-request-modal dd {
  margin: 0;
  color: #c8c2bc;
  font-size: 10px;
  line-height: 1.5;
}

@media (max-width: 980px) {
  .allowance-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .calendar-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 680px) {
  .allowance-summary {
    grid-template-columns: 1fr;
  }

  .calendar-toolbar {
    align-items: flex-start;
    flex-direction: column;
  }

  .calendar-toolbar__actions {
    width: 100%;
    justify-content: space-between;
  }

  .calendar-weekdays span {
    font-size: 7px;
  }

  .calendar-day {
    min-height: 86px;
  }

  .calendar-day__number {
    padding: 5px;
  }

  .calendar-day__number small {
    display: none;
  }

  .calendar-day__leave {
    min-height: 48px;
    padding: 2px;
  }

  .calendar-leave {
    min-height: 18px;
    padding: 0 3px;
  }

  .calendar-leave span {
    font-size: 7px;
    line-height: 18px;
  }
}

.my-calendar-leave__icon{font-size:15px;pointer-events:none}

/* Patch 20C calendar leave text */
.my-calendar-leave-label {
  display: flex;
  width: 100%;
  height: 100%;
  min-height: 42px;
  align-items: center;
  justify-content: center;
  padding: 4px 6px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.1;
  text-align: center;
  box-sizing: border-box;
}

/* Covers existing calendar-event wrappers regardless of the exact class name. */
.month-calendar button:has(.my-calendar-leave-label),
.calendar-grid button:has(.my-calendar-leave-label),
.calendar-month button:has(.my-calendar-leave-label),
.my-calendar button:has(.my-calendar-leave-label) {
  display: flex;
  align-items: center;
  justify-content: center;
}


/* Patch 20H half-day calendar blocks */
.my-calendar-leave--half {
  width: 50% !important;
  max-width: 50% !important;
  min-width: 0 !important;
  box-sizing: border-box;
}

.my-calendar-leave--morning {
  margin-right: auto !important;
  margin-left: 0 !important;
}

.my-calendar-leave--afternoon {
  margin-right: 0 !important;
  margin-left: auto !important;
}

.my-calendar-leave-label {
  display: flex !important;
  width: 100%;
  height: 100%;
  min-height: 48px;
  align-items: center;
  justify-content: center;
  padding: 5px 8px;
  box-sizing: border-box;
  font-size: 14px !important;
  font-weight: 700 !important;
  line-height: 1.15;
  text-align: center;
}

.my-calendar-leave--half,
.my-calendar-leave--half * {
  font-size: 13px !important;
  font-weight: 700;
  text-align: center;
}


/* Patch 20I actual month-grid half days */
.my-calendar-leave-event {
  box-sizing: border-box !important;
  min-width: 0 !important;
  overflow: hidden;
  display: flex !important;
  align-items: center;
  justify-content: center;
}

.my-calendar-leave-event__label,
.my-calendar-leave-event {
  font-size: 14px !important;
  font-weight: 700 !important;
  line-height: 1.15 !important;
  text-align: center !important;
}

.my-calendar-leave-event__label {
  display: flex;
  width: 100%;
  min-width: 0;
  align-items: center;
  justify-content: center;
  padding: 6px;
  box-sizing: border-box;
}


/* Patch 22A bank holidays */
.my-calendar-day--bank-holiday {
  position: relative;
}

.my-calendar-day--bank-holiday::before {
  content: '';
  position: absolute;
  top: 34px;
  left: 6px;
  right: 6px;
  height: 22px;
  background:
    repeating-linear-gradient(-45deg, rgba(201, 201, 201, 0.20) 0, rgba(201, 201, 201, 0.20) 4px, rgba(234, 234, 234, 0.08) 4px, rgba(234, 234, 234, 0.08) 8px),
    rgba(190, 190, 190, 0.08);
  border: 1px solid rgba(180, 180, 180, 0.12);
  pointer-events: none;
}

.my-calendar-bank-holiday-badge {
  display: inline-flex;
  min-width: 24px;
  height: 18px;
  align-items: center;
  justify-content: center;
  margin-left: 6px;
  padding: 0 6px;
  border-radius: 999px;
  background: rgba(190, 190, 190, 0.12);
  color: #ddd8d2;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.08em;
  vertical-align: middle;
}


/* Patch 22D larger bank holidays */
.my-calendar-day--bank-holiday::before {
  top: 36px !important;
  left: 5px !important;
  right: 5px !important;
  height: 34px !important;
  background: repeating-linear-gradient(-45deg, rgba(205,205,205,0.26) 0, rgba(205,205,205,0.26) 5px, rgba(255,255,255,0.08) 5px, rgba(255,255,255,0.08) 10px) !important;
  border: 1px solid rgba(180,180,180,0.18) !important;
}

.my-calendar-bank-holiday-badge {
  min-width: 34px !important;
  height: 22px !important;
  padding: 0 8px !important;
  font-size: 11px !important;
}


/* Patch 26 year view + stats */
.calendar-toolbar__eyebrow-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.calendar-view-switch {
  display: inline-flex;
  border: 1px solid #303030;
  background: #151515;
}

.calendar-view-switch__button {
  min-height: 28px;
  padding: 0 9px;
  border: 0;
  border-right: 1px solid #303030;
  background: transparent;
  color: #77716b;
  cursor: pointer;
  font: inherit;
  font-size: 9px;
  font-weight: 600;
}

.calendar-view-switch__button:last-child {
  border-right: 0;
}

.calendar-view-switch__button:hover {
  color: #eee9e3;
}

.calendar-view-switch__button--active {
  background: #ef5b3f;
  color: #090909;
}

.calendar-sidebar {
  display: grid;
  gap: 18px;
  align-self: start;
}

.calendar-sidebar-stats {
  display: grid;
  gap: 18px;
}

.calendar-stats-panel {
  overflow: hidden;
  border: 1px solid #262626;
  background: #101010;
}

.calendar-stats-panel__header {
  padding: 14px 16px;
  border-bottom: 1px solid #262626;
}

.calendar-stats-panel__header h2 {
  margin: 3px 0 0;
  color: #eee9e3;
  font-size: 18px;
  font-weight: 600;
}

.calendar-stats-list {
  display: grid;
  padding: 8px 0;
}

.calendar-stats-item {
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr) auto;
  min-height: 44px;
  align-items: center;
  gap: 10px;
  padding: 5px 13px;
}

.calendar-stats-item__icon {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
}

.calendar-stats-item strong {
  color: #d9d3cd;
  font-size: 11px;
  font-weight: 500;
}

.calendar-stats-item > span:last-child {
  color: #aaa39d;
  font-size: 11px;
}

.calendar-stats-empty {
  padding: 18px 16px;
  color: #77716b;
  font-size: 10px;
  line-height: 1.5;
}

.calendar-time-chart {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 5px;
  align-items: end;
  min-height: 128px;
  padding: 18px 14px 12px;
}

.calendar-time-chart__month {
  display: grid;
  gap: 7px;
  justify-items: center;
}

.calendar-time-chart__bar-wrap {
  display: flex;
  height: 72px;
  align-items: flex-end;
}

.calendar-time-chart__bar {
  display: block;
  width: 12px;
  min-height: 2px;
  background: #ef5b3f;
}

.calendar-time-chart__month strong {
  color: #77716b;
  font-size: 8px;
  font-weight: 600;
}

.calendar-year-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 34px 28px;
  padding: 28px;
}

.year-month {
  min-width: 0;
}

.year-month h3 {
  margin: 0 0 12px;
  color: #ddd7d1;
  font-size: 17px;
  font-weight: 500;
  text-align: center;
}

.year-month__weekdays,
.year-month__grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
}

.year-month__weekdays {
  margin-bottom: 4px;
}

.year-month__weekdays span {
  display: grid;
  height: 22px;
  place-items: center;
  color: #68635e;
  font-size: 8px;
  font-weight: 600;
}

.year-day {
  position: relative;
  display: grid;
  aspect-ratio: 1;
  min-width: 0;
  place-items: center;
  border: 0;
  background: transparent;
  color: #a9a39d;
  cursor: default;
  font: inherit;
  font-size: 9px;
}

.year-day--weekend {
  background: #171717;
}

.year-day--today {
  box-shadow: inset 0 0 0 1px #ef5b3f;
}

.year-day--leave {
  background: var(--year-leave-colour);
  color: #0a0a0a;
  cursor: pointer;
}

.year-day--pending {
  background:
    repeating-linear-gradient(
      -45deg,
      var(--year-leave-colour),
      var(--year-leave-colour) 4px,
      color-mix(in srgb, var(--year-leave-colour) 50%, #111) 4px,
      color-mix(in srgb, var(--year-leave-colour) 50%, #111) 8px
    );
}

.year-day--bank-holiday:not(.year-day--leave) {
  background:
    repeating-linear-gradient(
      -45deg,
      rgba(180, 180, 180, 0.3) 0,
      rgba(180, 180, 180, 0.3) 4px,
      rgba(255, 255, 255, 0.08) 4px,
      rgba(255, 255, 255, 0.08) 8px
    );
}

.year-day > span:first-child {
  position: absolute;
  top: 3px;
  left: 4px;
  font-size: 7px;
}

.year-day__icon {
  font-size: 11px;
}

.year-day__bh {
  font-size: 8px;
  font-weight: 800;
  letter-spacing: 0.04em;
}

.year-day--blank {
  pointer-events: none;
}

@media (max-width: 1050px) {
  .calendar-year-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .calendar-toolbar {
    align-items: flex-start;
    flex-direction: column;
  }

  .calendar-toolbar__actions {
    width: 100%;
    justify-content: space-between;
  }

  .calendar-toolbar__eyebrow-row {
    align-items: flex-start;
    flex-direction: column;
  }

  .calendar-year-grid {
    grid-template-columns: 1fr;
    gap: 28px;
    padding: 18px;
  }

  .calendar-time-chart {
    gap: 3px;
    padding-inline: 10px;
  }

  .calendar-time-chart__bar {
    width: 9px;
  }
}


/* Patch 26A sidebar + icon colour */
.calendar-sidebar {
  display: grid;
  min-width: 0;
  align-self: start;
  gap: 18px;
}

.calendar-sidebar-stats {
  display: grid;
  gap: 18px;
}

.year-day__icon {
  color: var(--year-icon-colour, #ffffff) !important;
}

.calendar-stats-item__icon {
  color: inherit;
}



/* Patch 39 — recurring staff birthdays */
.calendar-birthday-event {
  display: flex;
  min-height: 24px;
  align-items: center;
  gap: 5px;
  padding: 0 6px;
  border: 1px solid rgba(239, 91, 63, 0.48);
  background: #201310;
  color: #ef765f;
  font-size: 8px;
  font-weight: 700;
  line-height: 1.2;
}

.calendar-birthday-event svg {
  flex: 0 0 auto;
  font-size: 9px;
}

.calendar-birthday-event span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.year-day--birthday:not(.year-day--leave) {
  background: #201310;
  color: #ef765f;
  cursor: default;
}

.year-day__birthday-icon {
  color: #ef5b3f;
  font-size: 10px;
}

@media (max-width: 680px) {
  .calendar-birthday-event {
    min-height: 18px;
    padding: 0 3px;
    font-size: 7px;
  }

  .calendar-birthday-event svg {
    display: none;
  }
}
</style>
