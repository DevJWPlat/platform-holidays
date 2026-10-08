<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  faBell,
  faBirthdayCake,
  faCalendarCheck,
  faCircleInfo,
  faEnvelope,
  faFloppyDisk,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const success = ref('')
const people = ref([])

const form = ref({
  birthday_enabled: true,
  birthday_days_before: [7, 0],
  anniversary_enabled: true,
  anniversary_days_before: [7, 0],
  recipient_user_ids: [],
  send_hour: 9,
  leave_request_submitted_email: true,
  leave_request_approved_email: true,
  leave_request_rejected_email: true,
})

const birthdayOffsets = computed({
  get: () => form.value.birthday_days_before.join(', '),
  set: (value) => {
    form.value.birthday_days_before = parseOffsets(value)
  },
})

const anniversaryOffsets = computed({
  get: () => form.value.anniversary_days_before.join(', '),
  set: (value) => {
    form.value.anniversary_days_before = parseOffsets(value)
  },
})

function parseOffsets(value) {
  const parsed = String(value)
    .split(',')
    .map((part) => Number(part.trim()))
    .filter((number) => Number.isInteger(number) && number >= 0 && number <= 365)

  return [...new Set(parsed)].sort((a, b) => b - a)
}

function toggleRecipient(id) {
  const numeric = Number(id)
  const existing = form.value.recipient_user_ids.indexOf(numeric)

  if (existing >= 0) {
    form.value.recipient_user_ids.splice(existing, 1)
  } else {
    form.value.recipient_user_ids.push(numeric)
  }
}

async function load() {
  loading.value = true
  error.value = ''

  try {
    const [settingsResponse, peopleResponse] = await Promise.all([
      api.get('/api/v1/notification-settings'),
      api.get('/api/v1/people'),
    ])

    form.value = {
      ...form.value,
      ...(settingsResponse.data?.data || {}),
      recipient_user_ids: (settingsResponse.data?.data?.recipient_user_ids || [])
        .map(Number),
    }

    people.value = peopleResponse.data?.data || []
  } catch (requestError) {
    console.error(requestError)
    error.value =
      requestError.response?.data?.message
      || 'Could not load notification settings.'
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!form.value.recipient_user_ids.length) {
    error.value = 'Choose at least one person to receive reminders.'
    return
  }

  saving.value = true
  error.value = ''
  success.value = ''

  try {
    await initialiseCsrf()

    await api.put('/api/v1/notification-settings', {
      ...form.value,
      birthday_days_before:
        form.value.birthday_days_before.length
          ? form.value.birthday_days_before
          : [0],
      anniversary_days_before:
        form.value.anniversary_days_before.length
          ? form.value.anniversary_days_before
          : [0],
      send_hour: Number(form.value.send_hour),
    })

    success.value = 'Notification settings saved.'
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message
        || 'Could not save notification settings.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="notifications-panel">
    <header class="notifications-panel__heading">
      <div>
        <div class="eyebrow">NOTIFICATIONS</div>
        <h2>Notifications & reminders</h2>
        <p>
          Choose who gets birthday, work-anniversary and leave emails, and when.
        </p>
      </div>

      <button
        class="button button--primary"
        type="button"
        :disabled="loading || saving"
        @click="save"
      >
        <FontAwesomeIcon :icon="faFloppyDisk" />
        {{ saving ? 'Saving…' : 'Save notifications' }}
      </button>
    </header>

    <div v-if="error" class="notifications-alert notifications-alert--error">
      {{ error }}
    </div>

    <div v-if="success" class="notifications-alert notifications-alert--success">
      {{ success }}
    </div>

    <div v-if="loading" class="notifications-loading">
      Loading notification settings…
    </div>

    <div v-else class="notifications-body">
      <section class="notifications-section">
        <div class="notifications-section__intro">
          <FontAwesomeIcon :icon="faBirthdayCake" />
          <div>
            <h3>Birthday reminders</h3>
            <p>
              Email selected people before or on an employee's birthday.
            </p>
          </div>
        </div>

        <label class="notification-toggle">
          <input v-model="form.birthday_enabled" type="checkbox" />
          <span>
            <strong>Enable birthday reminders</strong>
            <small>Uses the Date of birth stored against each person.</small>
          </span>
        </label>

        <label v-if="form.birthday_enabled" class="notification-field">
          <span>Send reminders this many days before</span>
          <div class="notification-input">
            <input
              v-model="birthdayOffsets"
              type="text"
              placeholder="7, 0"
            />
          </div>
          <small>Example: <strong>7, 0</strong> sends one week before and again on the day.</small>
        </label>
      </section>

      <section class="notifications-section">
        <div class="notifications-section__intro">
          <FontAwesomeIcon :icon="faCalendarCheck" />
          <div>
            <h3>Work anniversary reminders</h3>
            <p>
              Uses each person's employment start date.
            </p>
          </div>
        </div>

        <label class="notification-toggle">
          <input v-model="form.anniversary_enabled" type="checkbox" />
          <span>
            <strong>Enable work anniversary reminders</strong>
            <small>Only reminders after their first completed year are sent.</small>
          </span>
        </label>

        <label v-if="form.anniversary_enabled" class="notification-field">
          <span>Send reminders this many days before</span>
          <div class="notification-input">
            <input
              v-model="anniversaryOffsets"
              type="text"
              placeholder="7, 0"
            />
          </div>
          <small>Separate multiple reminders with commas.</small>
        </label>
      </section>

      <section class="notifications-section">
        <div class="notifications-section__intro">
          <FontAwesomeIcon :icon="faEnvelope" />
          <div>
            <h3>Reminder recipients</h3>
            <p>
              Choose the people who should receive birthday and anniversary reminders.
            </p>
          </div>
        </div>

        <div class="recipient-grid">
          <button
            v-for="person in people"
            :key="person.id"
            class="recipient-option"
            :class="{
              'recipient-option--selected':
                form.recipient_user_ids.includes(Number(person.id)),
            }"
            type="button"
            @click="toggleRecipient(person.id)"
          >
            <span class="recipient-option__check">
              {{ form.recipient_user_ids.includes(Number(person.id)) ? '✓' : '' }}
            </span>

            <span>
              <strong>{{ person.name }}</strong>
              <small>{{ person.email }}</small>
            </span>
          </button>
        </div>

        <label class="notification-field notification-field--time">
          <span>Send reminder emails at</span>
          <div class="notification-input">
            <input v-model="form.send_hour" type="number" min="0" max="23" step="1" />
            <span>:00</span>
          </div>
          <small>Uses the organisation timezone.</small>
        </label>
      </section>

      <section class="notifications-section">
        <div class="notifications-section__intro">
          <FontAwesomeIcon :icon="faBell" />
          <div>
            <h3>Leave emails</h3>
            <p>
              Control the email events used by the leave request workflow.
            </p>
          </div>
        </div>

        <div class="leave-email-options">
          <label class="notification-toggle">
            <input v-model="form.leave_request_submitted_email" type="checkbox" />
            <span>
              <strong>New request submitted</strong>
              <small>Email approvers when a leave request needs reviewing.</small>
            </span>
          </label>

          <label class="notification-toggle">
            <input v-model="form.leave_request_approved_email" type="checkbox" />
            <span>
              <strong>Request approved</strong>
              <small>Email the employee when their request is approved.</small>
            </span>
          </label>

          <label class="notification-toggle">
            <input v-model="form.leave_request_rejected_email" type="checkbox" />
            <span>
              <strong>Request rejected</strong>
              <small>Email the employee when their request is rejected.</small>
            </span>
          </label>
        </div>
      </section>

      <div class="notifications-note">
        <FontAwesomeIcon :icon="faCircleInfo" />
        <span>
          The reminder runner is scheduled hourly, but sends only during the configured hour and records each dispatch to prevent duplicate emails.
        </span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.notifications-panel {
  min-width: 0;
}

.notifications-panel__heading {
  display: flex;
  min-height: 92px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px;
  border-bottom: 1px solid #292929;
}

.notifications-panel__heading h2 {
  margin: 4px 0;
  color: #eee9e3;
  font-size: 24px;
}

.notifications-panel__heading p {
  margin: 0;
  color: #8c8680;
  font-size: 12px;
  line-height: 1.5;
}

.notifications-body {
  display: grid;
}

.notifications-section {
  display: grid;
  gap: 16px;
  padding: 22px 18px;
  border-bottom: 1px solid #292929;
}

.notifications-section__intro {
  display: grid;
  grid-template-columns: 24px minmax(0, 1fr);
  gap: 11px;
  align-items: start;
}

.notifications-section__intro > svg {
  margin-top: 3px;
  color: #ef5b3f;
  font-size: 15px;
}

.notifications-section__intro h3 {
  margin: 0 0 5px;
  color: #ddd7d1;
  font-size: 18px;
}

.notifications-section__intro p {
  margin: 0;
  color: #8c8680;
  font-size: 12px;
  line-height: 1.5;
}

.notification-toggle {
  display: flex;
  align-items: flex-start;
  gap: 11px;
  padding: 13px;
  border: 1px solid #303030;
  background: #151515;
  cursor: pointer;
}

.notification-toggle input {
  margin-top: 2px;
  accent-color: #ef5b3f;
}

.notification-toggle > span {
  display: grid;
  gap: 4px;
}

.notification-toggle strong {
  color: #ddd7d1;
  font-size: 13px;
}

.notification-toggle small {
  color: #8c8680;
  font-size: 11px;
  line-height: 1.45;
}

.notification-field {
  display: grid;
  gap: 7px;
}

.notification-field > span {
  color: #aaa39d;
  font-size: 12px;
  font-weight: 600;
}

.notification-field > small {
  color: #77716b;
  font-size: 10px;
}

.notification-input {
  display: flex;
  min-height: 44px;
  align-items: center;
  border: 1px solid #373737;
  background: #151515;
}

.notification-input input {
  min-width: 0;
  flex: 1;
  height: 42px;
  padding: 0 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 12px;
}

.notification-input > span {
  padding-right: 12px;
  color: #77716b;
  font-size: 11px;
}

.notification-field--time {
  max-width: 260px;
}

.recipient-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.recipient-option {
  display: grid;
  grid-template-columns: 26px minmax(0, 1fr);
  gap: 9px;
  min-height: 58px;
  align-items: center;
  padding: 10px 12px;
  border: 1px solid #303030;
  background: #151515;
  color: #a9a39d;
  text-align: left;
  cursor: pointer;
}

.recipient-option:hover {
  border-color: #494949;
}

.recipient-option--selected {
  border-color: #ef5b3f;
  background: #1b1513;
}

.recipient-option__check {
  display: grid;
  width: 22px;
  height: 22px;
  place-items: center;
  border: 1px solid #484848;
  color: #fff;
  font-size: 11px;
}

.recipient-option--selected .recipient-option__check {
  border-color: #ef5b3f;
  background: #ef5b3f;
  color: #090909;
}

.recipient-option > span:last-child {
  display: grid;
  gap: 3px;
}

.recipient-option strong {
  color: #ddd7d1;
  font-size: 11px;
}

.recipient-option small {
  overflow: hidden;
  color: #77716b;
  font-size: 9px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.leave-email-options {
  display: grid;
  gap: 8px;
}

.notifications-note {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  margin: 18px;
  padding: 12px;
  border: 1px solid #3c372e;
  background: #191713;
  color: #a99d85;
  font-size: 11px;
  line-height: 1.5;
}

.notifications-alert {
  margin: 16px 18px 0;
  padding: 11px 12px;
  font-size: 10px;
}

.notifications-alert--error {
  border: 1px solid rgba(239, 91, 63, 0.45);
  background: rgba(239, 91, 63, 0.08);
  color: #f3a393;
}

.notifications-alert--success {
  border: 1px solid rgba(159, 211, 86, 0.38);
  background: rgba(159, 211, 86, 0.06);
  color: #c9eaa1;
}

.notifications-loading {
  padding: 24px 18px;
  color: #77716b;
  font-size: 11px;
}

@media (max-width: 700px) {
  .notifications-panel__heading {
    align-items: flex-start;
    flex-direction: column;
  }

  .recipient-grid {
    grid-template-columns: 1fr;
  }
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
