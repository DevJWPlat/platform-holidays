<script setup>
import CompanyClosuresPanel from '@/components/settings/CompanyClosuresPanel.vue'
import { computed, onMounted, ref } from 'vue'
import {
  faBuilding,
  faCalendarDays,
  faCircleInfo,
  faFloppyDisk,
  faLandmark,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import CustomSelect from '@/components/ui/CustomSelect.vue'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const success = ref('')
const bankHolidays = ref([])
const companyClosuresPanel = ref(null)

const form = ref({
  name: 'Platform',
  timezone: 'Europe/London',
  leave_year_start_month: 1,
  leave_year_start_day: 1,
  default_bank_holiday_division: 'england-and-wales',
  exclude_bank_holidays_from_leave_duration: true,
})

const monthOptions = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
].map((label, index) => ({
  value: String(index + 1),
  label,
}))

const leaveYearPreview = computed(() => {
  const month = monthOptions.find(
    (option) =>
      Number(option.value) === Number(form.value.leave_year_start_month),
  )

  return `${form.value.leave_year_start_day} ${month?.label || ''}`
})

function formatDate(value) {
  if (!value) return ''

  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(`${value}T12:00:00`))
}

function bankHolidayDisplayTitle(holiday) {
  const title = String(holiday?.title || 'Bank holiday')

  if (/substitute day/i.test(title) || !holiday?.date) {
    return title
  }

  const [, month, day] = String(holiday.date).split('-').map(Number)
  const normalised = title.toLowerCase()

  const isSubstitute =
    (normalised.includes("new year") && !(month === 1 && day === 1))
    || (normalised.includes('christmas') && !(month === 12 && day === 25))
    || (normalised.includes('boxing day') && !(month === 12 && day === 26))

  return isSubstitute
    ? `${title} (substitute day)`
    : title
}

async function load() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/api/v1/organisation-settings')
    const data = response.data?.data || {}

    form.value = {
      name: data.name || 'Platform',
      timezone: data.timezone || 'Europe/London',
      leave_year_start_month: data.leave_year_start_month || 1,
      leave_year_start_day: data.leave_year_start_day || 1,
      default_bank_holiday_division:
        data.default_bank_holiday_division || 'england-and-wales',
      exclude_bank_holidays_from_leave_duration:
        data.exclude_bank_holidays_from_leave_duration !== false,
    }

    bankHolidays.value = data.bank_holidays || []
  } catch (requestError) {
    console.error(requestError)
    error.value =
      requestError.response?.data?.message
      || 'Could not load organisation settings.'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  success.value = ''

  try {
    await initialiseCsrf()

    await api.put('/api/v1/organisation-settings', {
      ...form.value,
      timezone: 'Europe/London',
      default_bank_holiday_division: 'england-and-wales',
      leave_year_start_month:
        Number(form.value.leave_year_start_month),
      leave_year_start_day:
        Number(form.value.leave_year_start_day),
    })

    if (companyClosuresPanel.value?.savePendingClosure) {
      const closureSaved =
        await companyClosuresPanel.value.savePendingClosure()

      if (closureSaved === false) {
        throw new Error('Festive Break could not be saved.')
      }
    }

    success.value = 'Settings saved.'
    await load()
    success.value = 'Settings saved.'
  } catch (requestError) {
    const errors = requestError.response?.data?.errors

    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message
        || 'Could not save settings.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="organisation-panel">
    <header class="organisation-panel__heading">
      <div>
        <div class="eyebrow">ORGANISATION</div>
        <h2>Organisation settings</h2>
        <p>
          Configure the leave year, timezone and UK bank-holiday calendar.
        </p>
      </div>

      <button
        class="button button--primary"
        type="button"
        :disabled="loading || saving"
        @click="save"
      >
        <FontAwesomeIcon :icon="faFloppyDisk" />
        {{ saving ? 'Saving…' : 'Save settings' }}
      </button>
    </header>

    <div v-if="error" class="organisation-alert organisation-alert--error">
      {{ error }}
    </div>

    <div v-if="success" class="organisation-alert organisation-alert--success">
      {{ success }}
    </div>

    <div v-if="loading" class="organisation-loading">
      Loading organisation settings…
    </div>

    <div v-else class="organisation-body">
      <section class="organisation-section">
        <div class="organisation-section__intro">
          <FontAwesomeIcon :icon="faBuilding" />
          <div>
            <h3>Company</h3>
            <p>General details used across Platform Holidays.</p>
            <div class="organisation-fixed-locale">
              Europe/London timezone · England & Wales bank holidays
            </div>
          </div>
        </div>

        <label class="organisation-field">
          <span>Organisation name</span>
          <div class="organisation-input">
            <input v-model="form.name" type="text" />
          </div>
        </label>
      </section>

      <section class="organisation-section">
        <div class="organisation-section__intro">
          <FontAwesomeIcon :icon="faCalendarDays" />
          <div>
            <h3>Leave year</h3>
            <p>
              Controls when annual allowance resets and carry-over is generated.
            </p>
          </div>
        </div>

        <div class="organisation-grid">
          <label class="organisation-field">
            <span>Start day</span>
            <div class="organisation-input">
              <input
                v-model="form.leave_year_start_day"
                type="number"
                min="1"
                max="31"
                step="1"
              />
            </div>
          </label>

          <label class="organisation-field">
            <span>Start month</span>
            <CustomSelect
              v-model="form.leave_year_start_month"
              :options="monthOptions"
            />
          </label>
        </div>

        <div class="organisation-summary">
          Leave year starts on <strong>{{ leaveYearPreview }}</strong>.
        </div>
      </section>

      <section class="organisation-section">
        <div class="organisation-section__intro">
          <FontAwesomeIcon :icon="faLandmark" />
          <div>
            <h3>Bank holidays</h3>
            <p>
              Uses the official GOV.UK bank-holiday feed for the selected division.
            </p>
          </div>
        </div>

        <label class="organisation-toggle">
          <input
            v-model="form.exclude_bank_holidays_from_leave_duration"
            type="checkbox"
          />
          <span>
            <strong>Do not deduct bank holidays from leave</strong>
            <small>
              If a holiday request crosses an official bank holiday, that day
              contributes 0 days to the booking duration.
            </small>
          </span>
        </label>

        <div class="bank-holiday-preview">
          <div class="bank-holiday-preview__heading">
            <strong>Upcoming bank holidays</strong>
            <span>From GOV.UK</span>
          </div>

          <div v-if="bankHolidays.length" class="bank-holiday-list">
            <div
              v-for="holiday in bankHolidays"
              :key="`${holiday.date}-${holiday.title}`"
              class="bank-holiday-row"
            >
              <span>{{ formatDate(holiday.date) }}</span>
              <strong>{{ bankHolidayDisplayTitle(holiday) }}</strong>
            </div>
          </div>

          <div v-else class="bank-holiday-empty">
            No bank holidays were returned. Booking still works normally if the
            GOV.UK feed is temporarily unavailable.
          </div>
        </div>
      </section>

      <div class="organisation-note">
        <FontAwesomeIcon :icon="faCircleInfo" />
        <span>
          Changing the leave-year start affects how future allowance years are
          calculated. Existing leave requests and ledger history are not deleted.
        </span>
      </div>
    </div>
    <CompanyClosuresPanel ref="companyClosuresPanel" />
  </div>
</template>

<style scoped>
.organisation-panel {
  min-width: 0;
}

.organisation-panel__heading {
  display: flex;
  min-height: 92px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px;
  border-bottom: 1px solid #292929;
}

.organisation-panel__heading h2 {
  margin: 4px 0;
  color: #eee9e3;
  font-size: 24px;
}

.organisation-panel__heading p {
  margin: 0;
  color: #8c8680;
  font-size: 12px;
  line-height: 1.5;
}

.organisation-body {
  display: grid;
}

.organisation-section {
  display: grid;
  gap: 16px;
  padding: 22px 18px;
  border-bottom: 1px solid #292929;
}

.organisation-section__intro {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr);
  gap: 11px;
  align-items: start;
}

.organisation-section__intro > svg {
  margin-top: 3px;
  color: #ef5b3f;
  font-size: 15px;
}

.organisation-section__intro h3 {
  margin: 0 0 5px;
  color: #ddd7d1;
  font-size: 18px;
}

.organisation-section__intro p {
  margin: 0;
  color: #8c8680;
  font-size: 12px;
  line-height: 1.5;
}

.organisation-grid {
  display: grid;
  grid-template-columns: 160px minmax(220px, 1fr);
  gap: 10px;
}

.organisation-field {
  display: grid;
  gap: 7px;
}

.organisation-field > span {
  color: #aaa39d;
  font-size: 12px;
  font-weight: 600;
}

.organisation-input {
  display: flex;
  min-height: 44px;
  align-items: center;
  border: 1px solid #373737;
  background: #151515;
}

.organisation-input input {
  min-width: 0;
  width: 100%;
  height: 42px;
  padding: 0 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 12px;
}

.organisation-toggle {
  display: flex;
  align-items: flex-start;
  gap: 11px;
  padding: 13px;
  border: 1px solid #303030;
  background: #151515;
  cursor: pointer;
}

.organisation-toggle input {
  margin-top: 2px;
  accent-color: #ef5b3f;
}

.organisation-toggle > span {
  display: grid;
  gap: 4px;
}

.organisation-toggle strong {
  color: #ddd7d1;
  font-size: 13px;
}

.organisation-toggle small {
  color: #8c8680;
  font-size: 11px;
  line-height: 1.45;
}

.organisation-summary,
.organisation-note {
  padding: 12px;
  border: 1px solid #39342d;
  background: #191713;
  color: #aaa08a;
  font-size: 11px;
  line-height: 1.5;
}

.organisation-note {
  display: flex;
  gap: 9px;
  margin: 18px;
}

.bank-holiday-preview {
  border: 1px solid #303030;
  background: #131313;
}

.bank-holiday-preview__heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border-bottom: 1px solid #303030;
}

.bank-holiday-preview__heading strong {
  color: #ddd7d1;
  font-size: 12px;
}

.bank-holiday-preview__heading span {
  color: #77716b;
  font-size: 9px;
}

.bank-holiday-list {
  display: grid;
}

.bank-holiday-row {
  display: grid;
  grid-template-columns: 140px minmax(0, 1fr);
  gap: 12px;
  min-height: 44px;
  align-items: center;
  padding: 0 14px;
  border-bottom: 1px solid #272727;
}

.bank-holiday-row:last-child {
  border-bottom: 0;
}

.bank-holiday-row span {
  color: #827c76;
  font-size: 10px;
}

.bank-holiday-row strong {
  color: #d6d0ca;
  font-size: 11px;
}

.bank-holiday-empty {
  padding: 14px;
  color: #77716b;
  font-size: 10px;
  line-height: 1.5;
}

.organisation-alert {
  margin: 16px 18px 0;
  padding: 11px 12px;
  font-size: 10px;
}

.organisation-alert--error {
  border: 1px solid rgba(239, 91, 63, 0.45);
  background: rgba(239, 91, 63, 0.08);
  color: #f3a393;
}

.organisation-alert--success {
  border: 1px solid rgba(159, 211, 86, 0.38);
  background: rgba(159, 211, 86, 0.06);
  color: #c9eaa1;
}

.organisation-loading {
  padding: 24px 18px;
  color: #77716b;
  font-size: 11px;
}

@media (max-width: 700px) {
  .organisation-panel__heading {
    align-items: flex-start;
    flex-direction: column;
  }

  .organisation-grid {
    grid-template-columns: 1fr;
  }

  .bank-holiday-row {
    grid-template-columns: 1fr;
    gap: 4px;
    padding: 10px 14px;
  }
}

.organisation-fixed-locale {
  margin-top: 7px;
  color: #77716b;
  font-size: 11px;
  line-height: 1.5;
}

/* Patch 30 info banner alignment */
.policy-note,
.organisation-note,
.notifications-note,
.notification-note,
.settings-note,
.info-banner {
  align-items: center !important;
}

.policy-note > svg,
.organisation-note > svg,
.notifications-note > svg,
.notification-note > svg,
.settings-note > svg,
.info-banner > svg {
  align-self: center !important;
  margin-top: 0 !important;
  flex: 0 0 auto;
}

</style>
