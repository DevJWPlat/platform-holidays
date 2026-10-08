<script setup>
import { computed, ref, watch } from 'vue'
import {
  faCircleInfo,
  faTriangleExclamation,
  faXmark,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import CustomDatePicker from '@/components/ui/CustomDatePicker.vue'
import CustomSelect from '@/components/ui/CustomSelect.vue'

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  person: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'created'])

const leaveTypes = ref([])
const loading = ref(false)
const previewing = ref(false)
const saving = ref(false)
const error = ref('')
const preview = ref(null)

const form = ref({
  leave_type_id: '',
  starts_on: '',
  start_session: 'morning',
  ends_on: '',
  end_session: 'afternoon',
  reason: '',
})

const leaveTypeOptions = computed(() =>
  leaveTypes.value.map((type) => ({
    value: String(type.id),
    label: type.label,
  })),
)

const startSessionOptions = [
  { value: 'morning', label: 'Morning' },
  { value: 'afternoon', label: 'Afternoon' },
]

const endSessionOptions = [
  { value: 'morning', label: 'Morning' },
  { value: 'afternoon', label: 'End of day' },
]

const selectedLeaveType = computed(() =>
  leaveTypes.value.find(
    (type) => String(type.id) === String(form.value.leave_type_id),
  ) || null,
)

const isHoliday = computed(() =>
  Boolean(selectedLeaveType.value && selectedLeaveType.value.allow_half_days === false),
)

const allowanceText = computed(() => {
  if (!selectedLeaveType.value) return 'Choose a leave type.'

  if (!selectedLeaveType.value.is_protected_holiday) {
    return 'This leave type does not deduct holiday allowance.'
  }

  if (!preview.value?.allowance?.years?.length) {
    return 'Choose dates to calculate the holiday allowance impact.'
  }

  const year = preview.value.allowance.years[0]

  if (preview.value.allowance.years.length === 1) {
    return `${year.requested_days} days. ${year.remaining_before_days} → ${year.remaining_after_days} days remaining.`
  }

  return `${preview.value.allowance.requested_days} days across multiple leave years.`
})

const canSave = computed(() =>
  Boolean(
    props.person?.id
    && form.value.leave_type_id
    && form.value.starts_on
    && form.value.ends_on
    && preview.value
    && !previewing.value
    && !saving.value,
  ),
)

function todayKey() {
  const now = new Date()
  const offset = now.getTimezoneOffset()
  return new Date(now.getTime() - offset * 60000).toISOString().slice(0, 10)
}

function enforceHolidaySessions() {
  if (!isHoliday.value) return

  form.value.start_session = 'morning'
  form.value.end_session = 'afternoon'
}

function reset() {
  const today = todayKey()

  form.value = {
    leave_type_id: '',
    starts_on: today,
    start_session: 'morning',
    ends_on: today,
    end_session: 'afternoon',
    reason: '',
  }

  error.value = ''
  preview.value = null
}

async function loadSetup() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/api/v1/leave-types')
    leaveTypes.value = response.data?.data || []

    const holiday = leaveTypes.value.find((type) => type.is_protected_holiday)
    form.value.leave_type_id = String(holiday?.id || leaveTypes.value[0]?.id || '')

    await updatePreview()
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load leave types.'
  } finally {
    loading.value = false
  }
}

async function updatePreview() {
  preview.value = null
  error.value = ''

  if (
    !props.person?.id
    || !form.value.leave_type_id
    || !form.value.starts_on
    || !form.value.ends_on
  ) {
    return
  }

  previewing.value = true

  try {
    await initialiseCsrf()

    const response = await api.post('/api/v1/leave-requests/manual/preview', {
      user_id: Number(props.person.id),
      leave_type_id: Number(form.value.leave_type_id),
      starts_on: form.value.starts_on,
      start_session: form.value.start_session,
      ends_on: form.value.ends_on,
      end_session: form.value.end_session,
      reason: form.value.reason || null,
    })

    preview.value = response.data?.data || null
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not calculate this leave.'
  } finally {
    previewing.value = false
  }
}

async function save() {
  if (!canSave.value) return

  saving.value = true
  error.value = ''

  try {
    await initialiseCsrf()

    const response = await api.post('/api/v1/leave-requests/manual', {
      user_id: Number(props.person.id),
      leave_type_id: Number(form.value.leave_type_id),
      starts_on: form.value.starts_on,
      start_session: form.value.start_session,
      ends_on: form.value.ends_on,
      end_session: form.value.end_session,
      reason: form.value.reason || null,
    })

    emit('created', response.data?.data)
    emit('close')
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not add this leave.'
  } finally {
    saving.value = false
  }
}

watch(isHoliday, (holiday) => {
  if (holiday) {
    enforceHolidaySessions()
  }
})

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    reset()
    await loadSetup()
  },
)

watch(
  () => [
    form.value.leave_type_id,
    form.value.starts_on,
    form.value.start_session,
    form.value.ends_on,
    form.value.end_session,
  ],
  () => {
    if (props.open && !loading.value) {
      updatePreview()
    }
  },
  { deep: true },
)
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="open"
        class="modal-shell"
        role="dialog"
        aria-modal="true"
        aria-labelledby="manual-leave-title"
      >
        <button
          class="modal-shell__backdrop"
          type="button"
          aria-label="Close"
          @click="$emit('close')"
        />

        <section class="manual-leave-modal">
          <header>
            <div>
              <div class="eyebrow">MANUAL LEAVE</div>
              <h2 id="manual-leave-title">Add leave</h2>
              <p>
                Add approved leave directly for
                <strong>{{ person?.name }}</strong>.
              </p>
            </div>

            <button class="icon-button" type="button" aria-label="Close" @click="$emit('close')">
              <FontAwesomeIcon :icon="faXmark" />
            </button>
          </header>

          <div class="manual-leave-modal__body">
            <div v-if="error" class="manual-leave-error">
              {{ error }}
            </div>

            <div class="manual-leave-notice">
              <FontAwesomeIcon :icon="faCircleInfo" />
              <span>
                Manual leave is approved immediately and bypasses staffing-limit checks.
              </span>
            </div>

            <label class="field">
              <span class="field__label">Leave type</span>
              <CustomSelect
                v-model="form.leave_type_id"
                :options="leaveTypeOptions"
                :disabled="loading"
                placeholder="Choose leave type"
              />
            </label>

            <div v-if="isHoliday" class="holiday-full-day-note">
              Holidays are always added as full days. Half days remain available for other leave types.
            </div>

            <div class="manual-leave-grid">
              <div class="manual-leave-date">
                <span class="field__label">Starting</span>

                <div class="manual-leave-date__controls">
                  <CustomDatePicker
                    v-model="form.starts_on"
                    :allow-clear="false"
                  />

                  <div v-if="isHoliday" class="holiday-full-day-control">
                    Morning
                  </div>

                  <CustomSelect
                    v-else
                    v-model="form.start_session"
                    :options="startSessionOptions"
                  />
                </div>
              </div>

              <div class="manual-leave-date">
                <span class="field__label">Ending</span>

                <div class="manual-leave-date__controls">
                  <CustomDatePicker
                    v-model="form.ends_on"
                    :min="form.starts_on"
                    :allow-clear="false"
                  />

                  <div v-if="isHoliday" class="holiday-full-day-control">
                    End of day
                  </div>

                  <CustomSelect
                    v-else
                    v-model="form.end_session"
                    :options="endSessionOptions"
                  />
                </div>
              </div>
            </div>

            <label class="field">
              <span class="field__label">
                Note / reason
                <small>Optional</small>
              </span>

              <span class="manual-textarea">
                <textarea
                  v-model="form.reason"
                  rows="3"
                  placeholder="e.g. Reported sick by phone, manual correction..."
                />
              </span>
            </label>

            <div
              v-if="selectedLeaveType?.is_protected_holiday"
              class="manual-allowance"
            >
              <div class="eyebrow">ALLOWANCE</div>
              <p>{{ allowanceText }}</p>
            </div>

            <div
              v-if="selectedLeaveType && !selectedLeaveType.is_protected_holiday"
              class="manual-no-deduction"
            >
              <FontAwesomeIcon :icon="faTriangleExclamation" />
              <span>
                {{ selectedLeaveType.label }} will not deduct the employee's holiday allowance.
              </span>
            </div>
          </div>

          <footer>
            <button class="button button--secondary" type="button" @click="$emit('close')">
              Cancel
            </button>

            <button
              class="button button--primary"
              type="button"
              :disabled="!canSave"
              @click="save"
            >
              {{ saving ? 'Adding…' : 'Add approved leave' }}
            </button>
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.manual-leave-modal {
  position: relative;
  z-index: 2;
  width: min(760px, calc(100vw - 32px));
  max-height: calc(100vh - 36px);
  overflow: auto;
  border: 1px solid #303030;
  background: #111;
}

.manual-leave-modal > header,
.manual-leave-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 22px;
}

.manual-leave-modal > header {
  border-bottom: 1px solid #292929;
}

.manual-leave-modal > footer {
  justify-content: flex-end;
  border-top: 1px solid #292929;
}

.manual-leave-modal h2 {
  margin: 4px 0 0;
  color: #eee9e3;
  font-size: 22px;
}

.manual-leave-modal header p {
  margin: 5px 0 0;
  color: #77716b;
  font-size: 10px;
}

.manual-leave-modal header p strong {
  color: #cfc9c3;
}

.manual-leave-modal__body {
  display: grid;
  gap: 18px;
  padding: 22px;
}

.manual-leave-notice,
.manual-no-deduction {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  padding: 11px 12px;
  border: 1px solid #343434;
  background: #151515;
  color: #a39d96;
  font-size: 10px;
  line-height: 1.45;
}

.manual-leave-notice svg {
  color: #ef7b65;
  margin-top: 2px;
}

.manual-no-deduction {
  border-color: #493f2c;
  color: #c4ae7d;
}

.manual-no-deduction svg {
  color: #e6bd67;
  margin-top: 2px;
}

.manual-leave-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.manual-leave-date {
  display: grid;
  gap: 8px;
}

.manual-leave-date__controls {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 145px;
  gap: 8px;
}

.manual-textarea {
  display: block;
  border: 1px solid #373737;
  background: #151515;
}

.manual-textarea textarea {
  width: 100%;
  resize: vertical;
  padding: 11px 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 11px;
  box-sizing: border-box;
}

.manual-textarea textarea::placeholder {
  color: #666;
}

.manual-allowance {
  display: grid;
  gap: 5px;
  padding: 12px;
  border: 1px solid #303030;
  background: #151515;
}

.manual-allowance p {
  margin: 0;
  color: #b8b2ab;
  font-size: 10px;
}

.manual-leave-error {
  padding: 11px 12px;
  border: 1px solid rgba(239, 91, 63, 0.45);
  background: rgba(239, 91, 63, 0.08);
  color: #f3a393;
  font-size: 10px;
}

@media (max-width: 760px) {
  .manual-leave-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 560px) {
  .manual-leave-date__controls {
    grid-template-columns: 1fr;
  }
}

/* Patch 17A — manual full-day holidays */
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
