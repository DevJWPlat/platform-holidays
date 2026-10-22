<script setup>
import { computed, ref, watch } from 'vue'
import { faCalendarDays, faTrash, faUsers, faXmark } from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import CustomDatePicker from '@/components/ui/CustomDatePicker.vue'
import CustomSelect from '@/components/ui/CustomSelect.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'changed'])

const people = ref([])
const departments = ref([])
const leaveTypes = ref([])
const bookings = ref([])
const loading = ref(false)
const saving = ref(false)
const removingId = ref(null)
const confirmRemoveId = ref(null)
const error = ref('')
const form = ref(emptyForm())

const scopeOptions = [
  { value: 'all', label: 'Whole company' },
  { value: 'department', label: 'Department' },
  { value: 'people', label: 'Selected people' },
]

const departmentOptions = computed(() => [
  { value: '', label: 'Choose department' },
  ...departments.value.map((department) => ({ value: String(department.id), label: department.name })),
])

const leaveTypeOptions = computed(() => [
  { value: '', label: 'Choose booking type' },
  ...leaveTypes.value.map((type) => ({ value: String(type.id), label: type.label })),
])

const selectedPeople = computed(() =>
  people.value.filter((person) => form.value.user_ids.map(Number).includes(Number(person.id))),
)

function emptyForm() {
  return {
    leave_type_id: '', scope_type: 'all', department_id: '', user_ids: [],
    starts_on: '', ends_on: '', reason: '',
  }
}

function formatDate(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
    .format(new Date(String(value) + 'T12:00:00'))
}

function togglePerson(personId) {
  const id = Number(personId)
  const ids = form.value.user_ids.map(Number)
  form.value.user_ids = ids.includes(id) ? ids.filter((item) => item !== id) : [...ids, id]
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [peopleResponse, departmentsResponse, leaveTypesResponse, bookingsResponse] = await Promise.all([
      api.get('/api/v1/people'), api.get('/api/v1/departments'), api.get('/api/v1/leave-types'), api.get('/api/v1/group-bookings'),
    ])
    people.value = peopleResponse.data?.data || []
    departments.value = departmentsResponse.data?.data || []
    leaveTypes.value = leaveTypesResponse.data?.data || []
    bookings.value = bookingsResponse.data?.data || []
  } catch (requestError) {
    console.error(requestError)
    error.value = requestError.response?.data?.message || 'Could not load group booking options.'
  } finally {
    loading.value = false
  }
}

async function createBooking() {
  if (!form.value.leave_type_id || !form.value.starts_on || !form.value.ends_on) {
    error.value = 'Choose a booking type, start date and end date.'
    return
  }
  if (form.value.scope_type === 'department' && !form.value.department_id) {
    error.value = 'Choose a department.'
    return
  }
  if (form.value.scope_type === 'people' && !form.value.user_ids.length) {
    error.value = 'Choose at least one person.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    await initialiseCsrf()
    await api.post('/api/v1/group-bookings', {
      leave_type_id: Number(form.value.leave_type_id),
      scope_type: form.value.scope_type,
      department_id: form.value.scope_type === 'department' ? Number(form.value.department_id) : null,
      user_ids: form.value.scope_type === 'people' ? form.value.user_ids.map(Number) : [],
      starts_on: form.value.starts_on,
      ends_on: form.value.ends_on,
      reason: form.value.reason.trim() || null,
    })
    form.value = emptyForm()
    await load()
    emit('changed')
    emit('close')
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat().join(' ')
      : requestError.response?.data?.message || 'Could not create group booking.'
  } finally {
    saving.value = false
  }
}

async function removeBooking(booking) {
  if (confirmRemoveId.value !== booking.id) {
    confirmRemoveId.value = booking.id
    return
  }

  removingId.value = booking.id
  error.value = ''
  try {
    await initialiseCsrf()
    await api.delete(`/api/v1/group-bookings/${booking.id}`)
    confirmRemoveId.value = null
    await load()
    emit('changed')
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Could not remove group booking.'
  } finally {
    removingId.value = null
  }
}

watch(() => props.open, async (open) => {
  if (!open) return
  form.value = emptyForm()
  confirmRemoveId.value = null
  await load()
})
// PATCH_43A_SYNC_GROUP_END_DATE
watch(
  () => form.value.starts_on,
  (value, previousValue) => {
    if (!value || value === previousValue) return

    if (
      !form.value.ends_on
      || form.value.ends_on < value
      || form.value.ends_on === previousValue
    ) {
      form.value.ends_on = value
    }
  },
)

</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="open" class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="group-booking-title">
        <button class="modal-shell__backdrop" type="button" aria-label="Close" @click="$emit('close')" />

        <section class="group-booking-modal">
          <header>
            <div>
              <div class="eyebrow">ADMIN BOOKING</div>
              <h2 id="group-booking-title">Group booking</h2>
              <p>Book the same event for the company, a department, or selected people. Everyone selected must be free.</p>
            </div>
            <button class="icon-button" type="button" aria-label="Close" @click="$emit('close')">
              <FontAwesomeIcon :icon="faXmark" />
            </button>
          </header>

          <div class="group-booking-modal__body">
            <div v-if="error" class="group-booking-error">{{ error }}</div>
            <div v-if="loading" class="group-booking-loading">Loading group bookings…</div>

            <template v-else>
              <section class="group-booking-create">
                <div class="group-booking-section-heading">
                  <FontAwesomeIcon :icon="faUsers" />
                  <div>
                    <strong>Create group booking</strong>
                    <span>Admin group bookings are approved immediately.</span>
                  </div>
                </div>

                <div class="group-booking-grid">
                  <label class="field">
                    <span class="field__label">Booking type</span>
                    <CustomSelect v-model="form.leave_type_id" :options="leaveTypeOptions" />
                  </label>
                  <label class="field">
                    <span class="field__label">Who is this for?</span>
                    <CustomSelect v-model="form.scope_type" :options="scopeOptions" />
                  </label>
                </div>

                <label v-if="form.scope_type === 'department'" class="field">
                  <span class="field__label">Department</span>
                  <CustomSelect v-model="form.department_id" :options="departmentOptions" />
                </label>

                <div v-if="form.scope_type === 'people'" class="group-booking-people">
                  <span class="field__label">People <small>{{ selectedPeople.length }} selected</small></span>
                  <div class="group-booking-people__grid">
                    <button
                      v-for="person in people"
                      :key="person.id"
                      class="group-booking-person"
                      :class="{ 'group-booking-person--selected': form.user_ids.map(Number).includes(Number(person.id)) }"
                      type="button"
                      @click="togglePerson(person.id)"
                    >
                      <span>{{ person.name }}</span>
                      <small>{{ form.user_ids.map(Number).includes(Number(person.id)) ? 'Selected' : 'Add' }}</small>
                    </button>
                  </div>
                </div>

                <div class="group-booking-grid">
                  <label class="field">
                    <span class="field__label">Start date</span>
                    <CustomDatePicker v-model="form.starts_on" placeholder="Choose start date" />
                  </label>
                  <label class="field">
                    <span class="field__label">End date</span>
                    <CustomDatePicker v-model="form.ends_on" :min="form.starts_on" placeholder="Choose end date" />
                  </label>
                </div>

                <label class="field">
                  <span class="field__label">Reason / event name</span>
                  <span class="group-booking-input">
                    <input v-model="form.reason" type="text" placeholder="e.g. Manchester office day" />
                  </span>
                </label>

                <button class="button button--primary group-booking-create__button" type="button" :disabled="saving" @click="createBooking">
                  <FontAwesomeIcon :icon="faCalendarDays" />
                  {{ saving ? 'Checking everyone…' : 'Check availability & book' }}
                </button>
              </section>

              <section class="group-booking-existing">
                <div class="group-booking-section-heading">
                  <FontAwesomeIcon :icon="faCalendarDays" />
                  <div>
                    <strong>Existing group bookings</strong>
                    <span>Removing one cancels it for everyone in the group.</span>
                  </div>
                </div>

                <div v-if="!bookings.length" class="group-booking-empty">No group bookings yet.</div>

                <article v-for="booking in bookings" v-else :key="booking.id" class="group-booking-row">
                  <span class="group-booking-row__colour" :style="{ backgroundColor: booking.leave_type?.colour || '#777' }" />
                  <div class="group-booking-row__copy">
                    <strong>{{ booking.leave_type?.label || 'Booking' }}</strong>
                    <span>
                      {{ formatDate(booking.starts_on) }}
                      <template v-if="booking.ends_on !== booking.starts_on"> — {{ formatDate(booking.ends_on) }}</template>
                    </span>
                    <small>
                      {{ booking.department?.name || (booking.scope_type === 'all' ? 'Whole company' : booking.people_count + ' people') }}
                      <template v-if="booking.reason"> · {{ booking.reason }}</template>
                    </small>
                  </div>
                  <span class="group-booking-row__count">{{ booking.people_count }} {{ booking.people_count === 1 ? 'person' : 'people' }}</span>
                  <button class="button button--danger group-booking-row__remove" type="button" :disabled="removingId === booking.id" @click="removeBooking(booking)">
                    <FontAwesomeIcon :icon="faTrash" />
                    {{ removingId === booking.id ? 'Removing…' : confirmRemoveId === booking.id ? 'Confirm remove' : 'Remove' }}
                  </button>
                </article>
              </section>
            </template>
          </div>

          <footer>
            <button class="button button--secondary" type="button" @click="$emit('close')">Close</button>
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.group-booking-modal {
  position: relative;
  z-index: 2;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  width: min(820px, calc(100vw - 32px));
  height: min(820px, calc(100vh - 48px));
  max-height: calc(100vh - 48px);
  overflow: hidden;
  border: 1px solid #303030;
  background: #111;
}
.group-booking-modal > header,
.group-booking-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 22px;
}
.group-booking-modal > header { border-bottom: 1px solid #292929; }
.group-booking-modal > footer { justify-content: flex-end; border-top: 1px solid #292929; }
.group-booking-modal h2 { margin: 4px 0 0; color: #f0ebe5; font-size: 22px; font-weight: 600; }
.group-booking-modal header p { max-width: 620px; margin: 5px 0 0; color: #77716b; font-size: 10px; line-height: 1.5; }
.group-booking-modal__body { display: grid; min-height: 0; gap: 22px; overflow-y: auto; padding: 22px; }
.group-booking-create,
.group-booking-existing { display: grid; gap: 14px; }
.group-booking-existing { padding-top: 20px; border-top: 1px solid #292929; }
.group-booking-section-heading { display: flex; align-items: flex-start; gap: 10px; }
.group-booking-section-heading > svg { margin-top: 2px; color: #ef5b3f; }
.group-booking-section-heading > div { display: grid; gap: 3px; }
.group-booking-section-heading strong { color: #e8e2dc; font-size: 12px; }
.group-booking-section-heading span { color: #77716b; font-size: 9px; }
.group-booking-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.group-booking-input { display: flex; min-height: 44px; align-items: center; border: 1px solid #373737; background: #151515; }
.group-booking-input input { width: 100%; min-width: 0; height: 42px; padding: 0 12px; border: 0; outline: 0; background: transparent; color: #eee9e3; font: inherit; font-size: 11px; }
.group-booking-people { display: grid; gap: 8px; }
.group-booking-people .field__label { display: flex; justify-content: space-between; }
.group-booking-people .field__label small { color: #77716b; font-weight: 400; }
.group-booking-people__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; }
.group-booking-person { display: flex; min-height: 42px; align-items: center; justify-content: space-between; gap: 10px; padding: 0 11px; border: 1px solid #303030; background: #151515; color: #dcd6d0; text-align: left; cursor: pointer; }
.group-booking-person small { color: #77716b; font-size: 9px; }
.group-booking-person--selected { border-color: #ef5b3f; background: #1a1615; }
.group-booking-create__button { justify-self: end; }
.group-booking-error { padding: 11px 12px; border: 1px solid rgba(239, 91, 63, 0.45); background: rgba(239, 91, 63, 0.08); color: #f3a393; font-size: 10px; line-height: 1.5; }
.group-booking-loading,
.group-booking-empty { padding: 18px; border: 1px solid #292929; color: #77716b; font-size: 10px; }
.group-booking-row { display: grid; grid-template-columns: 4px minmax(0, 1fr) auto auto; min-height: 74px; align-items: center; gap: 14px; overflow: hidden; border: 1px solid #292929; background: #151515; }
.group-booking-row__colour { align-self: stretch; }
.group-booking-row__copy { display: grid; gap: 3px; }
.group-booking-row__copy strong { color: #ddd7d1; font-size: 11px; }
.group-booking-row__copy span,
.group-booking-row__copy small,
.group-booking-row__count { color: #77716b; font-size: 9px; }
.group-booking-row__remove { margin-right: 12px; }
.button--danger { border: 1px solid #8f3f32; background: transparent; color: #ef765f; }
@media (max-width: 680px) {
  .group-booking-grid,
  .group-booking-people__grid { grid-template-columns: 1fr; }
  .group-booking-row { grid-template-columns: 4px minmax(0, 1fr); padding: 10px 10px 10px 0; }
  .group-booking-row__count,
  .group-booking-row__remove { grid-column: 2; }
  .group-booking-row__remove { width: fit-content; margin-right: 0; }
}
.group-booking-modal {
  height: min(700px, calc(100vh - 40px));
  max-height: calc(100vh - 40px);
}

.group-booking-modal__body {
  gap: 16px;
  padding: 18px 22px;
}

.group-booking-modal > header,
.group-booking-modal > footer {
  padding-top: 16px;
  padding-bottom: 16px;
}

.group-booking-modal :deep(.custom-date-picker__panel) {
  width: 320px !important;
  max-width: calc(100vw - 48px);
  padding: 10px !important;
}

.group-booking-modal :deep(.custom-date-picker__panel button) {
  min-height: 28px !important;
}

.group-booking-modal :deep(.custom-date-picker__panel header),
.group-booking-modal :deep(.custom-date-picker__panel [class*="header"]) {
  min-height: 34px;
}

.group-booking-modal :deep(.custom-date-picker__panel [class*="day"]) {
  min-height: 28px !important;
  font-size: 10px !important;
}

.group-booking-modal :deep(.custom-date-picker__panel [class*="weekday"]) {
  font-size: 9px !important;
}

@media (max-width: 680px) {
  .group-booking-modal {
    height: calc(100vh - 20px);
    max-height: calc(100vh - 20px);
  }

  .group-booking-modal :deep(.custom-date-picker__panel) {
    width: min(300px, calc(100vw - 40px)) !important;
  }
}

/* PATCH_42F_COMPACT_DATE_PICKER */
.group-booking-modal :deep(.custom-date-picker__panel) {
  z-index: 80 !important;
  width: 270px !important;
  max-width: calc(100vw - 40px);
  padding: 8px !important;
}

.group-booking-modal :deep(.custom-date-picker__panel button) {
  min-height: 24px !important;
  height: 24px !important;
  padding: 0 !important;
  font-size: 9px !important;
}

.group-booking-modal :deep(.custom-date-picker__panel [class*="header"]) {
  min-height: 28px !important;
  margin-bottom: 4px !important;
}

.group-booking-modal :deep(.custom-date-picker__panel [class*="day"]) {
  min-height: 24px !important;
  height: 24px !important;
  font-size: 9px !important;
}

.group-booking-modal :deep(.custom-date-picker__panel [class*="weekday"]) {
  min-height: 18px !important;
  font-size: 8px !important;
}

.group-booking-modal :deep(.custom-date-picker__panel [class*="grid"]) {
  gap: 1px !important;
}

@media (max-width: 680px) {
  .group-booking-modal :deep(.custom-date-picker__panel) {
    width: 250px !important;
  }
}

/* PATCH_43B_MATCH_SHARED_CALENDAR */
.group-booking-modal :deep(.custom-date-picker__panel) {
  z-index: 80 !important;
  width: 300px !important;
  max-width: calc(100vw - 40px) !important;
  padding: 12px !important;
}

.group-booking-modal :deep(.custom-date-picker__panel button) {
  min-height: 30px !important;
  height: auto !important;
  font-size: inherit !important;
}

.group-booking-modal :deep(.custom-date-picker__panel button:disabled),
.group-booking-modal :deep(.custom-date-picker__panel [aria-disabled="true"]) {
  color: #4f4b48 !important;
  opacity: 0.55 !important;
  cursor: not-allowed !important;
}

.group-booking-modal :deep(.custom-date-picker__panel [class*="day"]) {
  min-height: 30px !important;
  height: auto !important;
  font-size: inherit !important;
}

.group-booking-modal :deep(.custom-date-picker__panel [class*="weekday"]) {
  font-size: inherit !important;
}

@media (max-width: 680px) {
  .group-booking-modal :deep(.custom-date-picker__panel) {
    width: min(300px, calc(100vw - 32px)) !important;
  }
}


/* PATCH_51_SQUARE_GROUP_BOOKING_MODAL */
.group-booking-modal,
.group-booking-modal > header,
.group-booking-modal > footer,
.group-booking-modal__body,
.group-booking-row,
.group-booking-input,
.group-booking-person,
.group-booking-error,
.group-booking-loading,
.group-booking-empty {
  border-radius: 0 !important;
}

.group-booking-modal :deep(.custom-select),
.group-booking-modal :deep(.custom-select__trigger),
.group-booking-modal :deep(.custom-date-picker),
.group-booking-modal :deep(.custom-date-picker__trigger),
.group-booking-modal :deep(.custom-date-picker__panel) {
  border-radius: 0 !important;
}



/* PATCH_51A_FORCE_SQUARE_MODAL */
.group-booking-modal,
.group-booking-modal *,
.group-booking-modal::before,
.group-booking-modal::after {
  border-radius: 0 !important;
}

.group-booking-modal :deep(*) {
  border-radius: 0 !important;
}



/* PATCH_51B_TRUE_SQUARE_CLOSE_BUTTON */
.group-booking-modal > header .icon-button {
  width: 40px !important;
  min-width: 40px !important;
  max-width: 40px !important;
  height: 40px !important;
  min-height: 40px !important;
  max-height: 40px !important;
  flex: 0 0 40px !important;
  padding: 0 !important;
  aspect-ratio: 1 / 1 !important;
  border-radius: 0 !important;
}

.group-booking-modal > header .icon-button svg {
  width: 14px !important;
  height: 14px !important;
}

</style>
