<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  faCalendarDay,
  faChevronDown,
  faExclamationTriangle,
  faPlaneDeparture,
  faXmark,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import CustomSelect from '@/components/ui/CustomSelect.vue'
import LeaveTypeSelect from '@/components/leave/LeaveTypeSelect.vue'
import CustomDatePicker from '@/components/ui/CustomDatePicker.vue'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close', 'created'])
const auth = useAuthStore()

const leaveTypes = ref([])
const allowance = ref(null)
const loadingSetup = ref(false)
const previewing = ref(false)
const submitting = ref(false)
const bookingAvatarFailed = ref(false)
const error = ref('')
const success = ref('')

const leaveTypeId = ref('')
const startsOn = ref('')
const startSession = ref('morning')
const endsOn = ref('')
const endSession = ref('afternoon')
const reason = ref('')
const preview = ref(null)
const overrideStaffing = ref(false)
const overrideReason = ref('')

const leaveTypeOptions = computed(() => leaveTypes.value.map((type) => ({ ...type, value: String(type.id) })))

const sessionStartOptions = [
  { value: 'morning', label: 'Morning' },
  { value: 'afternoon', label: 'Afternoon' },
]

const sessionEndOptions = [
  { value: 'morning', label: 'Afternoon' },
  { value: 'afternoon', label: 'End of day' },
]

const selectedLeaveType = computed(() =>
  leaveTypes.value.find((type) => String(type.id) === String(leaveTypeId.value)) || null,
)

const isHoliday = computed(() =>
  Boolean(selectedLeaveType.value && selectedLeaveType.value.allow_half_days === false),
)

const staffingConflicts = computed(() => preview.value?.staffing?.conflicts || [])
const hasStaffingConflicts = computed(() => staffingConflicts.value.length > 0)
const canOverrideStaffing = computed(() =>
  Boolean(preview.value?.staffing?.can_override || auth.user?.permissions?.override_staffing_limits),
)

const userInitials = computed(() => {
  const name = auth.user?.name || ''
  return name
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || '?'
})

const primaryDepartment = computed(() => {
  const departments = auth.user?.departments || []
  return departments[0]?.name || auth.user?.job_title || 'Platform'
})

const impactText = computed(() => {
  if (!selectedLeaveType.value) return 'Choose a leave type to see its allowance impact.'

  if (!selectedLeaveType.value.is_protected_holiday) {
    return 'This leave type does not affect your holiday allowance.'
  }

  if (previewing.value) return 'Calculating allowance impact…'

  const years = preview.value?.allowance?.years || []

  if (!years.length) {
    const remaining = allowance.value?.remaining_days
    return remaining == null
      ? 'Choose your dates to calculate the allowance impact.'
      : `You currently have ${remaining} days remaining.`
  }

  if (years.length === 1) {
    const year = years[0]
    return `${year.requested_days} days requested. ${year.remaining_before_days} → ${year.remaining_after_days} days remaining.`
  }

  return `${preview.value.allowance.requested_days} days requested across ${years.length} leave years.`
})

const canSubmit = computed(() => {
  const base = Boolean(
    leaveTypeId.value
    && startsOn.value
    && endsOn.value
    && !previewing.value
    && !submitting.value
    && preview.value,
  )

  if (!base) return false
  if (!hasStaffingConflicts.value) return true
  if (!canOverrideStaffing.value) return false

  return overrideStaffing.value && Boolean(overrideReason.value.trim())
})

function todayString() {
  const now = new Date()
  const offset = now.getTimezoneOffset()
  return new Date(now.getTime() - offset * 60000).toISOString().slice(0, 10)
}

function enforceHolidaySessions() {
  if (!isHoliday.value) return

  startSession.value = 'morning'
  endSession.value = 'afternoon'
}

function resetForm() {
  const today = todayString()
  leaveTypeId.value = ''
  startsOn.value = today
  endsOn.value = today
  startSession.value = 'morning'
  endSession.value = 'afternoon'
  reason.value = ''
  preview.value = null
  error.value = ''
  success.value = ''
  overrideStaffing.value = false
  overrideReason.value = ''
}

async function loadSetup() {
  loadingSetup.value = true
  error.value = ''

  try {
    const [typesResponse, allowanceResponse] = await Promise.all([
      api.get('/api/v1/leave-types'),
      api.get('/api/v1/allowance'),
    ])

    leaveTypes.value = typesResponse.data?.data || []
    allowance.value = allowanceResponse.data?.data || null

    const holiday = leaveTypes.value.find((type) => type.is_protected_holiday)
    leaveTypeId.value = String(holiday?.id || leaveTypes.value[0]?.id || '')
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load booking details. Please try again.'
  } finally {
    loadingSetup.value = false
  }
}

async function updatePreview() {
  preview.value = null
  error.value = ''
  overrideStaffing.value = false
  overrideReason.value = ''

  if (!leaveTypeId.value || !startsOn.value || !endsOn.value) return

  previewing.value = true

  try {
    await initialiseCsrf()

    const response = await api.post('/api/v1/leave-requests/preview', {
      leave_type_id: Number(leaveTypeId.value),
      starts_on: startsOn.value,
      start_session: startSession.value,
      ends_on: endsOn.value,
      end_session: endSession.value,
    })

    preview.value = response.data?.data || null
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value =
      errors
        ? Object.values(errors).flat()[0]
        : requestError.response?.data?.message || 'Could not calculate this request.'
  } finally {
    previewing.value = false
  }
}

async function submitRequest() {
  if (!canSubmit.value) return

  submitting.value = true
  error.value = ''
  success.value = ''

  try {
    await initialiseCsrf()

    const response = await api.post('/api/v1/leave-requests', {
      leave_type_id: Number(leaveTypeId.value),
      starts_on: startsOn.value,
      start_session: startSession.value,
      ends_on: endsOn.value,
      end_session: endSession.value,
      reason: reason.value || null,
      override_staffing_conflicts: overrideStaffing.value,
      override_reason: overrideStaffing.value ? overrideReason.value.trim() : null,
    })

    success.value = response.data?.data?.status === 'approved'
      ? 'Time off booked.'
      : 'Request submitted for approval.'

    emit('created', response.data?.data)

    const allowanceResponse = await api.get('/api/v1/allowance')
    allowance.value = allowanceResponse.data?.data || allowance.value

    window.setTimeout(() => close(), 650)
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value =
      errors
        ? Object.values(errors).flat()[0]
        : requestError.response?.data?.message || 'Could not submit this request.'
  } finally {
    submitting.value = false
  }
}

function close() {
  if (submitting.value) return
  emit('close')
}

function onKeydown(event) {
  if (event.key === 'Escape' && props.open) close()
}

watch(
  () => props.open,
  async (open) => {
    document.body.classList.toggle('modal-open', open)

    if (open) {
      bookingAvatarFailed.value = false
      resetForm()
      await loadSetup()
      await updatePreview()
    }
  },
)

watch(isHoliday, (holiday) => {
  if (holiday) {
    enforceHolidaySessions()
  }
})

watch(
  [leaveTypeId, startsOn, startSession, endsOn, endSession],
  () => {
    if (props.open && !loadingSetup.value) updatePreview()
  },
)

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.classList.remove('modal-open')
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="open"
        class="modal-shell"
        role="dialog"
        aria-modal="true"
        aria-labelledby="book-time-off-title"
      >
        <button
          class="modal-shell__backdrop"
          type="button"
          aria-label="Close booking modal"
          @click="close"
        />

        <section class="booking-modal">
          <header class="booking-modal__header">
            <div>
              <div class="eyebrow">NEW REQUEST</div>
              <h2 id="book-time-off-title">Book time off</h2>
            </div>

            <button class="icon-button" type="button" aria-label="Close" @click="close">
              <FontAwesomeIcon :icon="faXmark" />
            </button>
          </header>

          <div class="booking-person">
            <div class="booking-person__avatar">
              <img
                v-if="auth.user?.avatar_url && !bookingAvatarFailed"
                :src="auth.user.avatar_url"
                :alt="auth.user.name"
                @error="bookingAvatarFailed = true"
              />
              <span v-else>{{ userInitials }}</span>
            </div>
            <div>
              <strong>{{ auth.user?.name || 'You' }}</strong>
              <span>{{ primaryDepartment }}</span>
            </div>
          </div>

          <div class="booking-modal__body">
            <div v-if="error" class="booking-alert booking-alert--error">
              {{ error }}
            </div>

            <div v-if="success" class="booking-alert booking-alert--success">
              {{ success }}
            </div>

            <label class="field">
              <span class="field__label">Type</span>
              <LeaveTypeSelect v-model="leaveTypeId" :options="leaveTypeOptions" />
            </label>

            <div v-if="isHoliday" class="holiday-full-day-note">
              Holidays are booked as full days only.
            </div>

            <div class="booking-dates">
              <div class="booking-date-group">
                <span class="field__label">Starting</span>

                <div class="booking-date-group__controls">
                  <CustomDatePicker
                    v-model="startsOn"
                    :allow-clear="false"
                  />

                  <div v-if="isHoliday" class="holiday-full-day-control">
                    Morning
                  </div>

                  <CustomSelect
                    v-else
                    v-model="startSession"
                    :options="sessionStartOptions"
                  />
                </div>
              </div>

              <div class="booking-date-group">
                <span class="field__label">Ending</span>

                <div class="booking-date-group__controls">
                  <CustomDatePicker
                    v-model="endsOn"
                    :min="startsOn"
                    :allow-clear="false"
                  />

                  <div v-if="isHoliday" class="holiday-full-day-control">
                    End of day
                  </div>

                  <CustomSelect
                    v-else
                    v-model="endSession"
                    :options="sessionEndOptions"
                  />
                </div>
              </div>
            </div>

            <div v-if="hasStaffingConflicts" class="staffing-conflict">
              <div class="staffing-conflict__title">
                <FontAwesomeIcon :icon="faExclamationTriangle" />
                <strong>Staffing conflict</strong>
              </div>

              <p v-for="conflict in staffingConflicts" :key="`${conflict.type}-${conflict.rule_id}-${conflict.date}`">
                {{ conflict.message }}
              </p>

              <template v-if="canOverrideStaffing">
                <label class="staffing-override-check">
                  <input v-model="overrideStaffing" type="checkbox" />
                  <span>Override this staffing rule and continue</span>
                </label>

                <label v-if="overrideStaffing" class="field">
                  <span class="field__label">Override reason</span>
                  <span class="textarea-control">
                    <textarea
                      v-model="overrideReason"
                      rows="2"
                      placeholder="Explain why this exception is needed..."
                    />
                  </span>
                </label>
              </template>

              <p v-else class="staffing-conflict__blocked">
                This request cannot be submitted while the staffing limit is exceeded.
              </p>
            </div>

            <label class="field">
              <span class="field__label">Reason <small>Optional</small></span>
              <span class="textarea-control">
                <textarea
                  v-model="reason"
                  rows="3"
                  placeholder="Add a reason..."
                />
              </span>
            </label>

            <div class="allowance-impact">
              <div class="eyebrow">ALLOWANCE</div>
              <p>{{ impactText }}</p>
              <small v-if="preview?.duration">
                Working time: {{ preview.duration.days }} {{ preview.duration.days === 1 ? 'day' : 'days' }}
              </small>
            </div>
          </div>

          <footer class="booking-modal__footer">
            <button class="button button--secondary" type="button" @click="close">
              Cancel
            </button>

            <button
              class="button button--primary"
              type="button"
              :disabled="!canSubmit"
              @click="submitRequest"
            >
              {{ submitting ? 'Submitting…' : hasStaffingConflicts && overrideStaffing ? 'Override & request' : 'Request time off' }}
            </button>
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.booking-person__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.booking-native-control {
  position: relative;
}

.booking-native-control input,
.booking-native-control select {
  width: 100%;
  border: 0;
  outline: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.booking-native-control input::-webkit-calendar-picker-indicator {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  cursor: pointer;
}

.booking-native-control select {
  appearance: none;
  padding-right: 22px;
}

.booking-alert {
  padding: 10px 12px;
  border: 1px solid #343434;
  background: #171717;
  font-size: 11px;
  line-height: 1.45;
}

.booking-alert--error {
  border-color: rgba(239, 91, 63, 0.45);
  color: #f5b1a3;
}

.booking-alert--success {
  border-color: rgba(159, 211, 86, 0.45);
  color: #cfe9aa;
}

.allowance-impact small {
  display: block;
  margin-top: 6px;
  color: #777;
  font-size: 9px;
}

.staffing-conflict {
  display: grid;
  gap: 10px;
  padding: 12px;
  border: 1px solid rgba(230, 189, 103, 0.38);
  background: rgba(230, 189, 103, 0.06);
}

.staffing-conflict__title {
  display: flex;
  align-items: center;
  gap: 7px;
  color: #e6bd67;
  font-size: 11px;
}

.staffing-conflict p {
  margin: 0;
  color: #b6aa95;
  font-size: 10px;
  line-height: 1.5;
}

.staffing-conflict__blocked {
  color: #d9a49a !important;
}

.staffing-override-check {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #d6d0ca;
  font-size: 10px;
  cursor: pointer;
}

.staffing-override-check input {
  accent-color: #ef5b3f;
}

/* Patch 14A — custom booking controls */
.booking-date-group__controls {
  grid-template-columns: minmax(0, 1fr) 220px;
}

@media (max-width: 760px) {
  .booking-date-group__controls {
    grid-template-columns: 1fr;
  }
}


/* Patch 17A — full-day holidays */
.holiday-full-day-note {
  padding: 10px 12px;
  border: 1px solid #303030;
  background: #151515;
  color: #8f8982;
  font-size: 10px;
  line-height: 1.45;
}

.holiday-full-day-control {
  display: flex;
  min-height: 44px;
  align-items: center;
  padding: 0 12px;
  border: 1px solid #373737;
  background: #151515;
  color: #9d9791;
  font-size: 11px;
}

</style>
