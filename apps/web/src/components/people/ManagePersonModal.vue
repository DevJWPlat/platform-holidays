<script setup>
import { computed, ref, watch } from 'vue'
import {
  faArchive,
  faChevronDown,
  faPlus,
  faXmark,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import CustomSelect from '@/components/ui/CustomSelect.vue'
import CustomDatePicker from '@/components/ui/CustomDatePicker.vue'
import BirthDateInput from '@/components/people/BirthDateInput.vue'

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

const emit = defineEmits(['close', 'saved', 'archived'])

const departments = ref([])
const loading = ref(false)
const saving = ref(false)
const archiving = ref(false)
const error = ref('')
const createdInviteUrl = ref('')
const createdPersonName = ref('')
const inviteCopied = ref(false)
const emailLocal = ref('')

const form = ref(emptyForm())

const editing = computed(() => Boolean(props.person?.id))

const todayKey = new Date().toISOString().slice(0, 10)

const futureStartDate = computed(() =>
  Boolean(
    form.value.employment_start_date
    && form.value.employment_start_date > todayKey,
  ),
)

const validationError = computed(() => {
  if (!form.value.name.trim()) return 'Enter a name.'
  if (!emailLocal.value.trim()) return 'Enter the Platform email name.'

  if (!/^[a-z0-9._%+-]+$/i.test(emailLocal.value.trim())) {
    return 'The email name contains invalid characters.'
  }

  return ''
})

const carryOverOptions = [
  { value: '', label: 'Use organisation policy' },
  { value: 'allow', label: 'Allow carry-over' },
  { value: 'block', label: 'Do not allow carry-over' },
]

const roleOptions = computed(() => [
  { value: 'employee', label: 'Employee' },
  { value: 'approver', label: 'Approver' },
  { value: 'department_manager', label: 'Department manager' },
  { value: 'administrator', label: 'Administrator' },
])

const primaryDepartmentOptions = computed(() =>
  departments.value
    .filter((department) =>
      form.value.department_ids.map(Number).includes(Number(department.id)),
    )
    .map((department) => ({
      value: String(department.id),
      label: department.name,
    })),
)

function emptyForm() {
  return {
    name: '',
    email: '',
    job_title: '',
    role: 'employee',
    employment_start_date: '',
    date_of_birth: '',
    department_ids: [],
    primary_department_id: '',
    can_override_staffing_limits: false,
    holiday_allowance_override_days: '',
    carry_over_override: '',
  }
}

function resetForm() {
  if (!props.person) {
    form.value = emptyForm()
    emailLocal.value = ''
    return
  }

  emailLocal.value = String(props.person.email || '').replace(/@platform\.team$/i, '')

  const departmentIds = (props.person.departments || []).map((department) => Number(department.id))
  const primary =
    (props.person.departments || []).find((department) => department?.pivot?.is_primary)
    || props.person.departments?.[0]
    || null

  form.value = {
    name: props.person.name || '',
    email: props.person.email || '',
    job_title: props.person.job_title || '',
    role: props.person.role || 'employee',
    employment_start_date: props.person.employment_start_date || '',
    date_of_birth: props.person.date_of_birth || '',
    department_ids: departmentIds,
    primary_department_id: primary?.id ? String(primary.id) : '',
    can_override_staffing_limits: Boolean(props.person.can_override_staffing_limits),
    holiday_allowance_override_days: props.person.holiday_allowance_override_days ?? '',
    carry_over_override:
      props.person.carry_over_override === null || props.person.carry_over_override === undefined
        ? ''
        : props.person.carry_over_override
          ? 'allow'
          : 'block',
  }
}

async function loadOptions() {
  loading.value = true
  error.value = ''

  try {
    const departmentsResponse = await api.get('/api/v1/departments')
    departments.value = departmentsResponse.data?.data || []
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load person setup options.'
  } finally {
    loading.value = false
  }
}

function toggleDepartment(departmentId) {
  const id = Number(departmentId)
  const selected = form.value.department_ids.map(Number)

  if (selected.includes(id)) {
    form.value.department_ids = selected.filter((item) => item !== id)

    if (Number(form.value.primary_department_id) === id) {
      form.value.primary_department_id = form.value.department_ids[0]
        ? String(form.value.department_ids[0])
        : ''
    }

    return
  }

  form.value.department_ids = [...selected, id]

  if (!form.value.primary_department_id) {
    form.value.primary_department_id = String(id)
  }
}

async function save() {
  if (validationError.value) {
    error.value = validationError.value
    return
  }

  saving.value = true
  error.value = ''

  try {
    await initialiseCsrf()

    const payload = {
      ...form.value,
      name: form.value.name.trim(),
      email: `${emailLocal.value.trim().toLowerCase()}@platform.team`,
      job_title: form.value.job_title.trim() || null,
      employment_start_date: form.value.employment_start_date || null,
      date_of_birth: form.value.date_of_birth || null,
      department_ids: form.value.department_ids.map(Number),
      primary_department_id: form.value.primary_department_id
        ? Number(form.value.primary_department_id)
        : null,
      can_override_staffing_limits: Boolean(form.value.can_override_staffing_limits),
      holiday_allowance_override_days:
        form.value.holiday_allowance_override_days === ''
          ? null
          : Number(form.value.holiday_allowance_override_days),
      carry_over_override:
        form.value.carry_over_override === ''
          ? null
          : form.value.carry_over_override === 'allow',
    }

    const response = editing.value
      ? await api.put(`/api/v1/people/${props.person.id}`, payload)
      : await api.post('/api/v1/people', payload)

    const savedPerson = response.data?.data
    emit('saved', savedPerson)

    if (editing.value) {
      emit('close')
      return
    }

    createdPersonName.value = savedPerson?.name || form.value.name.trim()
    createdInviteUrl.value = savedPerson?.invite_url
      || window.location.origin + '/login?invite=' + encodeURIComponent(payload.email)
    inviteCopied.value = false
  } catch (requestError) {
    console.error('Add/edit person failed:', requestError)

    const status = requestError.response?.status
    const errors = requestError.response?.data?.errors

    const message = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message
        || requestError.message
        || 'Could not save this person.'

    error.value = status
      ? `Could not save this person (HTTP ${status}): ${message}`
      : `Could not save this person: ${message}`

    window.requestAnimationFrame(() => {
      document.querySelector('.manage-person-error')?.scrollIntoView({
        block: 'nearest',
        behavior: 'smooth',
      })
    })
  } finally {
    saving.value = false
  }
}

async function copyInviteLink() {
  if (!createdInviteUrl.value) return

  try {
    await navigator.clipboard.writeText(createdInviteUrl.value)
    inviteCopied.value = true
  } catch {
    error.value = 'Could not copy the invite link. Select the link and copy it manually.'
  }
}

function finishInvite() {
  emit('close')
}

async function archivePerson() {
  if (!editing.value || !window.confirm(`Archive ${props.person.name}?`)) return

  archiving.value = true
  error.value = ''

  try {
    await initialiseCsrf()
    await api.post(`/api/v1/people/${props.person.id}/archive`)
    emit('archived', props.person.id)
    emit('close')
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not archive this person.'
  } finally {
    archiving.value = false
  }
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    createdInviteUrl.value = ''
    createdPersonName.value = ''
    inviteCopied.value = false
    resetForm()
    await loadOptions()
  },
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
        aria-labelledby="manage-person-title"
      >
        <button
          class="modal-shell__backdrop"
          type="button"
          aria-label="Close"
          @click="$emit('close')"
        />

        <section class="manage-person-modal">
          <header>
            <div>
              <div class="eyebrow">PEOPLE</div>
              <h2 id="manage-person-title">
                {{ editing ? 'Edit person' : 'Add person' }}
              </h2>
              <p v-if="!editing">
                They can sign in later with the same Platform Google account.
              </p>
            </div>

            <button class="icon-button" type="button" aria-label="Close" @click="$emit('close')">
              <FontAwesomeIcon :icon="faXmark" />
            </button>
          </header>

          <div class="manage-person-modal__body">
            <section v-if="createdInviteUrl" class="manage-person-invite-success">
              <div class="eyebrow">INVITE READY</div>
              <h3>{{ createdPersonName }} has been added</h3>
              <p>
                Send this link to them. They must sign in with the exact Platform
                Google email address that was added.
              </p>

              <label class="field">
                <span class="field__label">Invite link</span>
                <div class="manage-person-invite-link">
                  <input
                    :value="createdInviteUrl"
                    readonly
                    @focus="$event.target.select()"
                  />
                  <button
                    class="button button--secondary"
                    type="button"
                    @click="copyInviteLink"
                  >
                    {{ inviteCopied ? 'Copied!' : 'Copy link' }}
                  </button>
                </div>
              </label>
            </section>

            <template v-else>
            <div v-if="error" class="manage-person-error">
              {{ error }}
            </div>

            <div class="manage-person-grid">
              <label class="field">
                <span class="field__label">Name</span>
                <span class="person-input">
                  <input v-model="form.name" type="text" placeholder="Full name" />
                </span>
              </label>

              <label class="field">
                <span class="field__label">Platform email</span>
                <span class="person-email-input">
                  <input
                    v-model="emailLocal"
                    type="text"
                    autocomplete="off"
                    placeholder="name"
                  />
                  <span>@platform.team</span>
                </span>
              </label>

              <label class="field">
                <span class="field__label">Job title</span>
                <span class="person-input">
                  <input v-model="form.job_title" type="text" placeholder="e.g. Front-end developer" />
                </span>
              </label>

              <label class="field">
                <span class="field__label">Start date</span>
                <CustomDatePicker
                  v-model="form.employment_start_date"
                  placeholder="Select start date"
                />

                <small
                  v-if="futureStartDate"
                  class="person-field-note"
                >
                  Future start date — this person can still be added now and will appear in the system before they start.
                </small>
              </label>

            <label class="field">
              <span class="field__label">Date of birth <small>For birthday reminders</small></span>
              <BirthDateInput v-model="form.date_of_birth" />
            </label>

              <label class="field">
                <span class="field__label">Role</span>
                <CustomSelect
                  v-model="form.role"
                  :options="roleOptions"
                />
              </label>
            </div>

            <section class="manage-person-section">
              <div>
                <div class="eyebrow">DEPARTMENTS</div>
                <p>Select every team they belong to, then choose their primary department.</p>
              </div>

              <div v-if="loading" class="manage-person-loading">
                Loading departments…
              </div>

              <div v-else class="person-department-grid">
                <button
                  v-for="department in departments"
                  :key="department.id"
                  class="person-department-option"
                  :class="{
                    'person-department-option--selected':
                      form.department_ids.map(Number).includes(Number(department.id)),
                  }"
                  type="button"
                  @click="toggleDepartment(department.id)"
                >
                  <span
                    class="person-department-option__colour"
                    :style="{ backgroundColor: department.colour || '#777' }"
                  />
                  <strong>{{ department.name }}</strong>
                  <small>
                    {{
                      form.department_ids.map(Number).includes(Number(department.id))
                        ? 'Selected'
                        : 'Add'
                    }}
                  </small>
                </button>
              </div>

              <label v-if="form.department_ids.length > 1" class="field">
                <span class="field__label">Primary department</span>
                <CustomSelect
                  v-model="form.primary_department_id"
                  :options="primaryDepartmentOptions"
                />
              </label>
            </section>

            <section class="manage-person-section person-allowance-setting">
              <div>
                <div class="eyebrow">HOLIDAY ALLOWANCE</div>
                <p>
                  Leave blank to use the organisation default, or enter this person's contracted annual allowance.
                </p>
              </div>

              <label class="field">
                <span class="field__label">Custom annual holiday allowance</span>
                <span class="person-allowance-input">
                  <input
                    v-model="form.holiday_allowance_override_days"
                    type="number"
                    min="0"
                    max="365"
                    step="1"
                    placeholder="Use organisation default"
                  />
                  <span>days</span>
                </span>
              </label>

              <label class="field">
                <span class="field__label">Carry-over permission</span>
                <CustomSelect
                  v-model="form.carry_over_override"
                  :options="carryOverOptions"
                />
                <small class="person-allowance-help">
                  Use the organisation rule, always allow carry-over, or block it for this person.
                </small>
              </label>
            </section>

            <label class="person-toggle">
              <input v-model="form.can_override_staffing_limits" type="checkbox" />
              <span>
                <strong>Can override staffing limits</strong>
                <small>
                  Allows this person to continue through a staffing conflict when they provide a reason.
                </small>
              </span>
            </label>
            </template>
          </div>

          <footer v-if="!createdInviteUrl">
            <button
              v-if="editing"
              class="button button--danger manage-person-archive"
              type="button"
              :disabled="archiving || saving"
              @click="archivePerson"
            >
              <FontAwesomeIcon :icon="faArchive" />
              {{ archiving ? 'Archiving…' : 'Archive person' }}
            </button>

            <div class="manage-person-modal__footer-actions">
              <button class="button button--secondary" type="button" @click="$emit('close')">
                Cancel
              </button>

              <button
                class="button button--primary"
                type="button"
                :disabled="saving || loading || Boolean(validationError)"
                @click="save"
              >
                <FontAwesomeIcon v-if="!editing" :icon="faPlus" />
                {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Add person' }}
              </button>
            </div>
          </footer>

          <footer v-else class="manage-person-invite-footer">
            <button
              class="button button--primary"
              type="button"
              @click="finishInvite"
            >
              Done
            </button>
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.manage-person-modal {
  position: relative;
  z-index: 2;
  width: min(760px, calc(100vw - 32px));
  max-height: calc(100vh - 36px);
  overflow: auto;
  border: 1px solid #303030;
  background: #111;
  box-shadow: 0 28px 80px rgba(0, 0, 0, 0.6);
}

.manage-person-modal > header,
.manage-person-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 22px;
}

.manage-person-modal > header {
  border-bottom: 1px solid #292929;
}

.manage-person-modal > footer {
  border-top: 1px solid #292929;
}

.manage-person-modal h2 {
  margin: 4px 0 0;
  color: #f0ebe5;
  font-size: 22px;
  font-weight: 600;
}

.manage-person-modal header p {
  margin: 5px 0 0;
  color: #77716b;
  font-size: 10px;
}

.manage-person-modal__body {
  display: grid;
  gap: 22px;
  padding: 22px;
}

.manage-person-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.person-input,
.person-select,
.person-email-input {
  position: relative;
  display: flex;
  min-height: 44px;
  align-items: center;
  width: 100%;
  border: 1px solid #373737;
  background: #151515;
}

.person-input input,
.person-select select,
.person-email-input input {
  width: 100%;
  min-width: 0;
  height: 42px;
  padding: 0 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 11px;
}

.person-input:focus-within,
.person-select:focus-within {
  border-color: #5d5d5d;
}

.person-input input::placeholder {
  color: #666;
}

.person-select select {
  appearance: none;
  padding-right: 34px;
  cursor: pointer;
}

.person-select svg {
  position: absolute;
  right: 11px;
  color: #6d6d6d;
  pointer-events: none;
}

.manage-person-section {
  display: grid;
  gap: 12px;
  padding-top: 4px;
}

.manage-person-section > div:first-child p {
  margin: 4px 0 0;
  color: #77716b;
  font-size: 9px;
}

.person-department-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.person-department-option {
  display: grid;
  grid-template-columns: 5px 1fr auto;
  min-height: 48px;
  align-items: center;
  gap: 10px;
  overflow: hidden;
  padding: 0 11px 0 0;
  border: 1px solid #303030;
  background: #151515;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.person-department-option:hover {
  border-color: #454545;
}

.person-department-option--selected {
  border-color: #ef5b3f;
  background: #1a1615;
}

.person-department-option__colour {
  align-self: stretch;
}

.person-department-option strong {
  color: #ddd7d1;
  font-size: 11px;
}

.person-department-option small {
  color: #777;
  font-size: 9px;
}

.person-toggle {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 13px;
  border: 1px solid #303030;
  background: #151515;
  cursor: pointer;
}

.person-toggle input {
  margin-top: 2px;
  accent-color: #ef5b3f;
}

.person-toggle > span {
  display: grid;
  gap: 4px;
}

.person-toggle strong {
  color: #ddd7d1;
  font-size: 11px;
}

.person-toggle small {
  color: #77716b;
  font-size: 9px;
  line-height: 1.5;
}

.manage-person-error {
  padding: 11px 12px;
  border: 1px solid rgba(239, 91, 63, 0.45);
  background: rgba(239, 91, 63, 0.08);
  color: #f3a393;
  font-size: 10px;
}

.manage-person-loading {
  padding: 16px;
  border: 1px solid #2d2d2d;
  color: #777;
  font-size: 10px;
}

.manage-person-modal__footer-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-left: auto;
}

.manage-person-archive {
  margin-right: auto;
}

.button--danger {
  border: 1px solid #51302a;
  background: #211513;
  color: #e49786;
}

.button--danger:hover {
  background: #2a1916;
}

@media (max-width: 680px) {
  .manage-person-grid,
  .person-department-grid {
    grid-template-columns: 1fr;
  }

  .manage-person-modal > footer {
    align-items: stretch;
    flex-direction: column;
  }

  .manage-person-archive {
    width: 100%;
    margin-right: 0;
  }

  .manage-person-modal__footer-actions {
    width: 100%;
    margin-left: 0;
  }

  .manage-person-modal__footer-actions .button {
    flex: 1;
  }
}

.person-email-input {
  display: flex;
  min-height: 44px;
  align-items: center;
  width: 100%;
  border: 1px solid #373737;
  background: #151515;
  box-sizing: border-box;
}

.person-email-input:focus-within {
  border-color: #5d5d5d;
}

.person-email-input input {
  min-width: 0;
  flex: 1;
  height: 42px;
  padding: 0 0 0 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 11px;
}

.person-email-input input::placeholder {
  color: #666;
}

.person-email-input > span {
  flex: 0 0 auto;
  padding: 0 12px 0 3px;
  color: #85817c;
  font-size: 11px;
  pointer-events: none;
}


/* Patch 14B — validation feedback */
.manage-person-error {
  position: sticky;
  top: 0;
  z-index: 4;
  border-color: #7a3428;
  background: #2a1714;
  color: #ffb2a2;
  font-size: 11px;
  line-height: 1.5;
}

.person-field-note {
  display: block;
  margin-top: 7px;
  color: #c6a56b;
  font-size: 9px;
  line-height: 1.45;
}


.person-allowance-setting {
  padding-top: 4px;
}

.person-allowance-input {
  display: flex;
  min-height: 44px;
  align-items: center;
  border: 1px solid #373737;
  background: #151515;
}

.person-allowance-input input {
  min-width: 0;
  flex: 1;
  height: 42px;
  padding: 0 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 11px;
}

.person-allowance-input > span {
  padding: 0 12px;
  color: #77716b;
  font-size: 10px;
}


.person-allowance-help {
  display: block;
  margin-top: 5px;
  color: #77716b;
  font-size: 10px;
  line-height: 1.45;
}

/* Patch 21A compact person modal */
.manage-person-modal {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  height: min(760px, calc(100vh - 72px));
  max-height: calc(100vh - 72px);
  overflow: hidden !important;
}

.manage-person-modal > header {
  position: sticky;
  z-index: 10;
  top: 0;
  background: #111;
}

.manage-person-modal__body {
  min-height: 0;
  overflow-y: auto !important;
  overflow-x: hidden;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color: #3a3a3a #111;
}

.manage-person-modal__body::-webkit-scrollbar {
  width: 8px;
}

.manage-person-modal__body::-webkit-scrollbar-track {
  background: #111;
}

.manage-person-modal__body::-webkit-scrollbar-thumb {
  background: #3a3a3a;
}

.manage-person-modal > footer {
  position: sticky;
  z-index: 10;
  bottom: 0;
  background: #111;
}

@media (max-height: 760px) {
  .manage-person-modal {
    height: calc(100vh - 40px);
    max-height: calc(100vh - 40px);
  }
}


/* Patch 28A invite */
.manage-person-invite-success {
  display: grid;
  gap: 14px;
}

.manage-person-invite-success h3 {
  margin: 0;
  color: #eee9e3;
  font-size: 20px;
}

.manage-person-invite-success p {
  margin: 0;
  color: #8d8781;
  font-size: 11px;
  line-height: 1.55;
}

.manage-person-invite-link {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
}

.manage-person-invite-link input {
  width: 100%;
  min-width: 0;
  height: 44px;
  padding: 0 12px;
  border: 1px solid #373737;
  outline: 0;
  background: #151515;
  color: #ddd7d1;
  font: inherit;
  font-size: 10px;
  box-sizing: border-box;
}

.manage-person-invite-footer {
  justify-content: flex-end !important;
}

@media (max-width: 680px) {
  .manage-person-invite-link {
    grid-template-columns: 1fr;
  }

  .manage-person-invite-link .button {
    width: 100%;
    justify-content: center;
  }
}

</style>
