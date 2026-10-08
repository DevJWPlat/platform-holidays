<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  faArrowTrendDown,
  faCalendarCheck,
  faClock,
  faFileLines,
  faDownload,
  faRotateRight,
  faUsers,
} from '@fortawesome/free-solid-svg-icons'
import api from '@/api/client'
import CustomDatePicker from '@/components/ui/CustomDatePicker.vue'
import CustomSelect from '@/components/ui/CustomSelect.vue'

const report = ref(null)
const departments = ref([])
const people = ref([])
const leaveTypes = ref([])
const loading = ref(true)
const error = ref('')
const auditFilter = ref('all')

const currentYear = new Date().getFullYear()

const filters = ref({
  from: `${currentYear}-01-01`,
  to: `${currentYear}-12-31`,
  department_id: '',
  person_id: '',
  leave_type_id: '',
})

const departmentOptions = computed(() => [
  { value: '', label: 'All departments' },
  ...departments.value.map((department) => ({
    value: String(department.id),
    label: department.name,
  })),
])

const leaveTypeOptions = computed(() => [
  { value: '', label: 'All leave types' },
  ...leaveTypes.value.map((type) => ({
    value: String(type.id),
    label: type.label,
  })),
])

const personOptions = computed(() => [
  { value: '', label: 'All people' },
  ...people.value.map((person) => ({
    value: String(person.id),
    label: person.name,
  })),
])

const filteredActivity = computed(() => {
  const items = report.value?.activity || []

  if (auditFilter.value === 'all') {
    return items
  }

  return items.filter((item) =>
    String(item.action || '').startsWith(auditFilter.value),
  )
})

function primaryDepartment(person) {
  return (person.departments || []).find((item) => item.is_primary)
    || person.departments?.[0]
    || null
}

function initials(name = '') {
  return String(name)
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || '?'
}

function csvValue(value) {
  const text = String(value ?? '')

  if (/[",\n]/.test(text)) {
    return '"' + text.replaceAll('"', '""') + '"'
  }

  return text
}

function exportCsv() {
  const rows = report.value?.people || []

  const header = [
    'Name',
    'Email',
    'Department',
    'Job title',
    'Holiday taken (days)',
    'Sickness (days)',
    'Allowance (days)',
    'Remaining (days)',
    'Pending requests',
  ]

  const lines = [
    header,
    ...rows.map((person) => [
      person.name,
      people.value.find((item) => Number(item.id) === Number(person.id))?.email || '',
      primaryDepartment(person)?.name || '',
      person.job_title || '',
      person.holiday_days,
      person.sick_days,
      person.allowance?.granted_days ?? '',
      person.allowance?.remaining_days ?? '',
      person.pending_requests ?? 0,
    ]),
  ].map((row) => row.map(csvValue).join(','))

  const blob = new Blob([lines.join('\n')], {
    type: 'text/csv;charset=utf-8',
  })

  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'platform-holidays-report-' + filters.value.from + '-to-' + filters.value.to + '.csv'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

function formatTimestamp(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

async function loadOptions() {
  const [departmentResponse, peopleResponse, leaveTypesResponse] = await Promise.all([
    api.get('/api/v1/departments'),
    api.get('/api/v1/people'),
    api.get('/api/v1/leave-types'),
  ])

  departments.value = departmentResponse.data?.data || []
  people.value = peopleResponse.data?.data || []
  leaveTypes.value = leaveTypesResponse.data?.data || []
}

async function loadReport() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/api/v1/reports', {
      params: {
        from: filters.value.from,
        to: filters.value.to,
        department_id: filters.value.department_id || undefined,
        person_id: filters.value.person_id || undefined,
        leave_type_id: filters.value.leave_type_id || undefined,
      },
    })

    auditVisibleCount.value = 5
    report.value = response.data?.data || null
  } catch (requestError) {
    console.error(requestError)
    error.value = requestError.response?.data?.message || 'Could not load reports.'
  } finally {
    loading.value = false
  }
}

async function initialise() {
  try {
    await loadOptions()
    await loadReport()
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load report filters.'
    loading.value = false
  }
}

onMounted(initialise)

const auditVisibleCount = ref(5)
function loadMoreAudit() {
  auditVisibleCount.value += 5
}

function setAuditFilter(value) {
  auditFilter.value = value
  auditVisibleCount.value = 5
}
</script>

<template>
  <section class="page reports-page">
    <div class="page-heading reports-heading">
      <div>
        <div class="eyebrow">REPORTING</div>
        <h1>Reports</h1>
        <p>Holiday usage, sickness, allowances and activity across Platform.</p>
      </div>

      <div class="reports-heading__actions">
        <button
          class="button button--secondary button--compact"
          type="button"
          :disabled="loading || !report"
          @click="exportCsv"
        >
          <FontAwesomeIcon :icon="faDownload" />
          Export CSV
        </button>

        <button
          class="button button--secondary button--compact"
          type="button"
          :disabled="loading"
          @click="loadReport"
        >
          <FontAwesomeIcon :icon="faRotateRight" />
          Refresh
        </button>
      </div>
    </div>

    <section class="reports-filters">
      <label class="field">
        <span class="field__label">From</span>
        <CustomDatePicker v-model="filters.from" :allow-clear="false" />
      </label>

      <label class="field">
        <span class="field__label">To</span>
        <CustomDatePicker v-model="filters.to" :min="filters.from" :allow-clear="false" />
      </label>

      <label class="field">
        <span class="field__label">Department</span>
        <CustomSelect v-model="filters.department_id" :options="departmentOptions" />
      </label>

      <label class="field">
        <span class="field__label">Person</span>
        <CustomSelect v-model="filters.person_id" :options="personOptions" />
      </label>

      <label class="field">
        <span class="field__label">Leave type</span>
        <CustomSelect v-model="filters.leave_type_id" :options="leaveTypeOptions" />
      </label>

      <button class="button button--primary reports-apply" type="button" :disabled="loading" @click="loadReport">
        Apply filters
      </button>
    </section>

    <div v-if="error" class="reports-error">{{ error }}</div>

    <template v-if="report">
      <section class="report-cards">
        <article>
          <FontAwesomeIcon :icon="faUsers" />
          <span>People</span>
          <strong>{{ report.summary.people }}</strong>
        </article>

        <article>
          <FontAwesomeIcon :icon="faCalendarCheck" />
          <span>Holiday taken</span>
          <strong>{{ report.summary.holiday_days }} <small>days</small></strong>
        </article>

        <article>
          <FontAwesomeIcon :icon="faArrowTrendDown" />
          <span>Sickness</span>
          <strong>{{ report.summary.sick_days }} <small>days</small></strong>
        </article>

        <article>
          <FontAwesomeIcon :icon="faCalendarCheck" />
          <span>Approved requests</span>
          <strong>{{ report.summary.approved_requests }}</strong>
        </article>

        <article>
          <FontAwesomeIcon :icon="faClock" />
          <span>Pending</span>
          <strong>{{ report.summary.pending_requests }}</strong>
        </article>

        <article>
          <FontAwesomeIcon :icon="faFileLines" />
          <span>Manual entries</span>
          <strong>{{ report.summary.manual_entries }}</strong>
        </article>
      </section>

      <section class="report-insights">
        <article>
          <div class="eyebrow">ALLOWANCE INSIGHT</div>
          <span>Most remaining</span>
          <strong>{{ report.summary.allowance_highest_remaining?.name || '—' }}</strong>
          <small v-if="report.summary.allowance_highest_remaining">
            {{ report.summary.allowance_highest_remaining.allowance.remaining_days }} days
          </small>
        </article>

        <article>
          <div class="eyebrow">ALLOWANCE INSIGHT</div>
          <span>Least remaining</span>
          <strong>{{ report.summary.allowance_lowest_remaining?.name || '—' }}</strong>
          <small v-if="report.summary.allowance_lowest_remaining">
            {{ report.summary.allowance_lowest_remaining.allowance.remaining_days }} days
          </small>
        </article>
      </section>

      <section class="report-panel">
        <header class="report-panel__heading">
          <div>
            <div class="eyebrow">ALLOWANCES</div>
            <h2>People</h2>
          </div>
        </header>

        <div class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th>Person</th>
                <th>Department</th>
                <th>Holiday taken</th>
                <th>Sickness</th>
                <th>Allowance</th>
                <th>Remaining</th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="person in report.people" :key="person.id">
                <td>
                  <div class="report-person">
                    <div class="report-avatar">
                      <img v-if="person.avatar_url" :src="person.avatar_url" :alt="person.name" />
                      <span v-else>{{ initials(person.name) }}</span>
                    </div>
                    <div>
                      <strong>{{ person.name }}</strong>
                      <span>{{ person.job_title || 'Platform' }}</span>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="report-department" :style="{ '--dept-colour': primaryDepartment(person)?.colour || '#666' }">
                    {{ primaryDepartment(person)?.name || 'No department' }}
                  </span>
                </td>
                <td>{{ person.holiday_days }} days</td>
                <td>{{ person.sick_days }} days</td>
                <td>{{ person.allowance.granted_days }} days</td>
                <td><strong class="report-remaining">{{ person.allowance.remaining_days }} days</strong></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="reports-two-column">
        <section class="report-panel">
          <header class="report-panel__heading">
            <div>
              <div class="eyebrow">TEAMS</div>
              <h2>By department</h2>
            </div>
          </header>

          <div class="report-list">
            <article v-for="department in report.departments" :key="department.id" class="report-list__row">
              <span class="report-list__colour" :style="{ backgroundColor: department.colour || '#666' }" />
              <div>
                <strong>{{ department.name }}</strong>
                <small>{{ department.people }} people</small>
              </div>
              <span>{{ department.holiday_days }} holiday days</span>
              <strong>{{ department.approved_days }} total</strong>
            </article>

            <div v-if="!report.departments.length" class="report-empty">No department data in this period.</div>
          </div>
        </section>

        <section class="report-panel">
          <header class="report-panel__heading">
            <div>
              <div class="eyebrow">LEAVE TYPES</div>
              <h2>Breakdown</h2>
            </div>
          </header>

          <div class="report-list">
            <article v-for="type in report.leave_types" :key="type.id || type.label" class="report-list__row report-list__row--type">
              <span class="report-list__colour" :style="{ backgroundColor: type.colour || '#666' }" />
              <div>
                <strong>{{ type.label }}</strong>
                <small>{{ type.requests }} requests</small>
              </div>
              <strong>{{ type.days }} days</strong>
            </article>

            <div v-if="!report.leave_types.length" class="report-empty">No approved leave in this period.</div>
          </div>
        </section>
      </div>

      <section class="report-panel">
        <header class="report-panel__heading report-panel__heading--between">
          <div>
            <div class="eyebrow">AUDIT</div>
            <h2>Activity</h2>
            <p>Audit logging records changes made in Platform Holidays.</p>
          </div>

          <div class="audit-filter">
            <button
              v-for="option in [
                { value: 'all', label: 'All' },
                { value: 'leave.', label: 'Leave' },
                { value: 'allowance.', label: 'Allowance' },
                { value: 'person.', label: 'People' },
                { value: 'organisation.', label: 'Settings' },
              ]"
              :key="option.value"
              type="button"
              :class="{ 'audit-filter__button--active': auditFilter === option.value }"
              class="audit-filter__button"
              @click="setAuditFilter(option.value)"
            >
              {{ option.label }}
            </button>
          </div>
        </header>

        <div class="activity-list">
          <article
            v-for="activity in filteredActivity.slice(0, auditVisibleCount)"
            :key="activity.id"
            class="activity-row"
          >
            <span class="activity-row__dot" />
            <div>
              <strong>{{ activity.description }}</strong>
              <small>{{ activity.actor?.name || 'System' }} · {{ formatTimestamp(activity.created_at) }}</small>
            </div>
            <span class="activity-row__action">{{ activity.action.replaceAll('.', ' ') }}</span>
          </article>

          <div
            v-if="auditVisibleCount < filteredActivity.length"
            class="reports-audit-load-more"
          >
            <button
              class="button button--secondary"
              type="button"
              @click="loadMoreAudit"
            >
              Load more
            </button>
          </div>

          <div v-if="!filteredActivity.length" class="report-empty">No audit activity recorded in this period yet.</div>
        </div>
      </section>
    </template>

    <div v-else-if="loading" class="reports-loading">Loading reports…</div>
  </section>
</template>

<style scoped>
.reports-heading { align-items: flex-end; }

.reports-filters {
  display: grid;
  grid-template-columns: 170px 170px minmax(180px, 1fr) minmax(180px, 1fr) auto;
  gap: 10px;
  align-items: end;
  margin-bottom: 18px;
  padding: 14px;
  border: 1px solid #292929;
  background: #101010;
}

.reports-apply { min-height: 44px; }

.reports-error {
  margin-bottom: 18px;
  padding: 12px;
  border: 1px solid rgba(239, 91, 63, 0.42);
  background: rgba(239, 91, 63, 0.08);
  color: #f4a898;
  font-size: 11px;
}

.report-cards {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 1px;
  margin-bottom: 18px;
  border: 1px solid #282828;
  background: #282828;
}

.report-cards article {
  display: grid;
  grid-template-columns: 20px 1fr;
  gap: 7px 10px;
  min-height: 112px;
  align-content: center;
  padding: 16px;
  background: #101010;
}

.report-cards svg { grid-row: 1 / span 2; color: #777; }
.report-cards span { color: #77716b; font-size: 10px; }
.report-cards strong { color: #eee9e3; font-size: 24px; font-weight: 600; }
.report-cards small { color: #777; font-size: 9px; }

.report-panel {
  overflow: hidden;
  margin-bottom: 18px;
  border: 1px solid #282828;
  background: #101010;
}

.report-panel__heading {
  display: flex;
  min-height: 68px;
  align-items: center;
  padding: 14px 16px;
  border-bottom: 1px solid #282828;
}

.report-panel__heading h2 { margin: 3px 0 0; color: #eee9e3; font-size: 18px; }
.report-panel__heading p { margin: 4px 0 0; color: #706c67; font-size: 9px; }

.report-table-wrap { overflow-x: auto; }

.report-table {
  width: 100%;
  min-width: 900px;
  border-collapse: collapse;
}

.report-table th,
.report-table td {
  padding: 14px 16px;
  border-bottom: 1px solid #242424;
  text-align: left;
}

.report-table th {
  background: #0e0e0e;
  color: #686868;
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
}

.report-table td { color: #aaa49e; font-size: 11px; }
.report-table tbody tr:last-child td { border-bottom: 0; }

.report-person { display: flex; align-items: center; gap: 10px; }

.report-avatar {
  display: grid;
  width: 36px;
  height: 36px;
  flex: 0 0 36px;
  overflow: hidden;
  place-items: center;
  border: 1px solid #343434;
  background: #1a1a1a;
  color: #ddd7d1;
  font-size: 9px;
  font-weight: 700;
}

.report-avatar img { width: 100%; height: 100%; object-fit: cover; }
.report-person > div:last-child { display: grid; gap: 3px; }
.report-person strong { color: #e4ded8; font-size: 11px; }
.report-person span { color: #6d6964; font-size: 9px; }

.report-department { display: inline-flex; align-items: center; gap: 7px; }

.report-department::before {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--dept-colour);
  content: '';
}

.report-remaining { color: #9fd356; }

.reports-two-column {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
}

.report-list { display: grid; }

.report-list__row {
  display: grid;
  grid-template-columns: 4px minmax(0, 1fr) auto auto;
  min-height: 64px;
  align-items: center;
  gap: 13px;
  padding-right: 16px;
  border-bottom: 1px solid #242424;
}

.report-list__row:last-child { border-bottom: 0; }
.report-list__row--type { grid-template-columns: 4px minmax(0, 1fr) auto; }
.report-list__colour { align-self: stretch; }
.report-list__row > div { display: grid; gap: 3px; }
.report-list__row strong { color: #dcd6d0; font-size: 11px; }
.report-list__row small,
.report-list__row > span:not(.report-list__colour) { color: #77716b; font-size: 9px; }

.activity-list { display: grid; }

.activity-row {
  display: grid;
  grid-template-columns: 8px minmax(0, 1fr) auto;
  min-height: 62px;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
  border-bottom: 1px solid #242424;
}

.activity-row:last-child { border-bottom: 0; }
.activity-row__dot { width: 7px; height: 7px; border-radius: 50%; background: #ef5b3f; }
.activity-row > div { display: grid; gap: 4px; }
.activity-row strong { color: #dcd6d0; font-size: 10px; }
.activity-row small { color: #706b66; font-size: 9px; }
.activity-row__action { color: #77716b; font-size: 8px; text-transform: uppercase; }

.report-empty,
.reports-loading { padding: 22px 16px; color: #77716b; font-size: 10px; }

@media (max-width: 1100px) {
  .reports-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .reports-apply { grid-column: span 2; }
  .report-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .reports-two-column { grid-template-columns: 1fr; }
}

@media (max-width: 620px) {
  .reports-filters,
  .report-cards { grid-template-columns: 1fr; }

  .reports-apply { grid-column: auto; }

  .activity-row {
    grid-template-columns: 8px 1fr;
    padding: 10px 12px;
  }

  .activity-row__action { grid-column: 2; }
}

/* Patch 24 reports polish */
.reports-heading__actions {
  display: flex;
  gap: 8px;
  align-items: center;
}

.reports-filters {
  grid-template-columns:
    155px
    155px
    minmax(150px, 1fr)
    minmax(150px, 1fr)
    minmax(150px, 1fr)
    auto !important;
}

.report-cards {
  grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
}

.report-insights {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 18px;
}

.report-insights article {
  display: grid;
  gap: 5px;
  padding: 16px;
  border: 1px solid #282828;
  background: #101010;
}

.report-insights span {
  color: #77716b;
  font-size: 11px;
}

.report-insights strong {
  color: #eee9e3;
  font-size: 18px;
}

.report-insights small {
  color: #9fd356;
  font-size: 11px;
}

.report-table td {
  font-size: 12px !important;
}

.report-person strong {
  font-size: 12px !important;
}

.report-person span {
  font-size: 10px !important;
}

.report-panel__heading--between {
  justify-content: space-between;
  gap: 16px;
}

.audit-filter {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.audit-filter__button {
  min-height: 30px;
  padding: 0 10px;
  border: 1px solid #303030;
  background: #151515;
  color: #817b75;
  cursor: pointer;
  font-size: 9px;
}

.audit-filter__button:hover,
.audit-filter__button--active {
  border-color: #ef5b3f;
  color: #eee9e3;
}

@media (max-width: 1250px) {
  .reports-filters {
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
  }

  .reports-apply {
    grid-column: auto !important;
  }

  .report-cards {
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
  }
}

@media (max-width: 720px) {
  .reports-heading__actions {
    width: 100%;
    align-items: stretch;
    flex-direction: column;
  }

  .reports-filters,
  .report-cards,
  .report-insights {
    grid-template-columns: 1fr !important;
  }

  .report-panel__heading--between {
    align-items: flex-start;
    flex-direction: column;
  }
}


/* Patch 29 reports polish */
.reports-audit-load-more {
  display: flex;
  justify-content: center;
  padding: 18px;
  border-top: 1px solid #292929;
}

.reports-audit-load-more .button {
  min-width: 120px;
  justify-content: center;
}

@media (max-width: 720px) {
  .reports-heading {
    align-items: stretch !important;
    flex-direction: column;
    gap: 24px;
  }

  .reports-heading > div:first-child {
    width: 100%;
  }

  .reports-heading__actions,
  .reports-heading > div:last-child:not(:first-child) {
    width: 100%;
  }

  .reports-heading__actions {
    display: grid;
    gap: 10px;
  }

  .reports-heading__actions .button,
  .reports-heading > .button,
  .reports-heading > div:last-child:not(:first-child) .button {
    width: 100%;
    min-height: 48px;
    justify-content: center;
  }
}

</style>
