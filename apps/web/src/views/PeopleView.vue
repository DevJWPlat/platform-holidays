<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  faCalendarDays,
  faFilter,
  faMagnifyingGlass,
  faPlus,
  faPen,
  faRotateRight,
  faUser,
  faUsers,
  faXmark,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import ManagePersonModal from '@/components/people/ManagePersonModal.vue'
import ManageApproverRuleModal from '@/components/people/ManageApproverRuleModal.vue'
import ManualLeaveModal from '@/components/leave/ManualLeaveModal.vue'
import AdjustAllowanceModal from '@/components/people/AdjustAllowanceModal.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const people = ref([])
const departments = ref([])
const staffingGroups = ref([])
const approverAssignments = ref([])
const approverRuleOpen = ref(false)
const editingApproverRule = ref(null)
const departmentModalOpen = ref(false)
const editingDepartment = ref(null)
const departmentForm = ref({
  name: '',
  colour: '#777777',
  maximum_absent: '',
  member_ids: [],
})
const savingDepartment = ref(false)
const loading = ref(true)
const error = ref('')
const search = ref('')
const departmentFilter = ref('all')
const selectedPerson = ref(null)
const manualLeaveOpen = ref(false)
const allowanceAdjustOpen = ref(false)
const allowanceAdjustPerson = ref(null)
const manualLeavePerson = ref(null)
const managePersonOpen = ref(false)
const editingPerson = ref(null)

const canManageDepartments = computed(() =>
  Boolean(auth.user?.permissions?.manage_department_people),
)

const canManagePeople = computed(() =>
  Boolean(auth.user?.permissions?.manage_department_people),
)

const filteredPeople = computed(() => {
  const term = search.value.trim().toLowerCase()

  return people.value.filter((person) => {
    const matchesSearch = !term
      || person.name?.toLowerCase().includes(term)
      || person.email?.toLowerCase().includes(term)
      || person.job_title?.toLowerCase().includes(term)

    const matchesDepartment = departmentFilter.value === 'all'
      || (person.departments || []).some(
        (department) => String(department.id) === String(departmentFilter.value),
      )

    return matchesSearch && matchesDepartment
  })
})

function initials(name = '') {
  return String(name)
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || '?'
}

function primaryDepartment(person) {
  return (person.departments || []).find((department) => department?.pivot?.is_primary)
    || person.departments?.[0]
    || null
}

function roleLabel(role) {
  return {
    employee: 'Employee',
    approver: 'Approver',
    department_manager: 'Department manager',
    administrator: 'Administrator',
  }[role] || role || 'Employee'
}

function formatDate(value) {
  if (!value) return '—'

  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(`${value}T12:00:00`))
}

async function loadPeople() {
  loading.value = true
  error.value = ''

  try {
    const [peopleResult, departmentsResult, staffingResult, approversResult] = await Promise.allSettled([
      api.get('/api/v1/people'),
      api.get('/api/v1/departments'),
      api.get('/api/v1/staffing-groups'),
      canManageDepartments.value
        ? api.get('/api/v1/approver-assignments')
        : Promise.resolve({ data: { data: [] } }),
    ])

    if (peopleResult.status !== 'fulfilled') {
      throw peopleResult.reason
    }

    people.value = (peopleResult.value.data?.data || []).map((person) => ({
      ...person,
      avatarFailed: false,
    }))

    departments.value =
      departmentsResult.status === 'fulfilled'
        ? departmentsResult.value.data?.data || []
        : []

    staffingGroups.value =
      staffingResult.status === 'fulfilled'
        ? staffingResult.value.data?.data || []
        : []

    approverAssignments.value =
      approversResult.status === 'fulfilled'
        ? approversResult.value.data?.data || []
        : []
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load people.'
  } finally {
    loading.value = false
  }
}

function openDepartmentModal(department = null) {
  editingDepartment.value = department

  departmentForm.value = {
    name: department?.name || '',
    colour: department?.colour || '#777777',
    maximum_absent: department?.maximum_absent ?? '',
    member_ids: department?.members?.map((member) => member.id) || [],
  }

  departmentModalOpen.value = true
}

function closeDepartmentModal() {
  if (savingDepartment.value) return
  departmentModalOpen.value = false
  editingDepartment.value = null
}

async function saveDepartment() {
  if (!departmentForm.value.name.trim()) return

  savingDepartment.value = true
  error.value = ''

  try {
    await initialiseCsrf()

    const payload = {
      name: departmentForm.value.name.trim(),
      colour: departmentForm.value.colour,
      maximum_absent:
        departmentForm.value.maximum_absent === ''
          ? null
          : Number(departmentForm.value.maximum_absent),
      member_ids: departmentForm.value.member_ids.map(Number),
    }

    if (editingDepartment.value) {
      await api.put(`/api/v1/departments/${editingDepartment.value.id}`, payload)
    } else {
      await api.post('/api/v1/departments', payload)
    }

    closeDepartmentModal()
    await loadPeople()
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not save department.'
  } finally {
    savingDepartment.value = false
  }
}

function toggleDepartmentMember(personId) {
  const id = Number(personId)
  const members = departmentForm.value.member_ids.map(Number)

  departmentForm.value.member_ids = members.includes(id)
    ? members.filter((memberId) => memberId !== id)
    : [...members, id]
}

function openApproverRule(assignment = null) {
  editingApproverRule.value = assignment
  approverRuleOpen.value = true
}

function closeApproverRule() {
  approverRuleOpen.value = false
  editingApproverRule.value = null
}

async function onApproverRuleChanged() {
  closeApproverRule()
  await loadPeople()
}

function approvalScopeLabel(assignment) {
  if (assignment.scope_type === 'person') {
    return assignment.employee?.name || 'Person'
  }

  return assignment.department?.name || 'Department'
}

function openAllowanceAdjustment(person) {
  selectedPerson.value = null
  allowanceAdjustPerson.value = person
  allowanceAdjustOpen.value = true
}

function closeAllowanceAdjustment() {
  allowanceAdjustOpen.value = false
  allowanceAdjustPerson.value = null
}

async function onAllowanceAdjusted() {
  closeAllowanceAdjustment()
  await loadPeople()
}

function openManualLeave(person) {
  selectedPerson.value = null
  manualLeavePerson.value = person
  manualLeaveOpen.value = true
}

function closeManualLeave() {
  manualLeaveOpen.value = false
  manualLeavePerson.value = null
}

async function onManualLeaveCreated() {
  closeManualLeave()
  await loadPeople()
}

function openAddPerson() {
  editingPerson.value = null
  managePersonOpen.value = true
}

function openEditPerson(person) {
  selectedPerson.value = null
  editingPerson.value = person
  managePersonOpen.value = true
}

function closeManagePerson() {
  managePersonOpen.value = false
  editingPerson.value = null
}

async function onPersonSaved() {
  closeManagePerson()
  await loadPeople()
}

async function onPersonArchived() {
  closeManagePerson()
  selectedPerson.value = null
  await loadPeople()
}

function openPerson(person) {
  selectedPerson.value = person
}

function closePerson() {
  selectedPerson.value = null
}

onMounted(loadPeople)
</script>

<template>
  <section class="page">
    <div class="page-heading people-heading">
      <div>
        <div class="eyebrow">TEAM</div>
        <h1>People</h1>
        <p>See everyone at Platform and how their holiday setup is configured.</p>
      </div>

      <div class="people-heading__actions">
        <div class="people-heading__count">
          <FontAwesomeIcon :icon="faUsers" />
          {{ people.length }} {{ people.length === 1 ? 'person' : 'people' }}
        </div>

        <button
          v-if="auth.user?.permissions?.manage_settings"
          class="button button--primary button--compact"
          type="button"
          @click="openAddPerson"
        >
          <span class="people-add-person__plus">+</span>
          Add person
        </button>
      </div>
    </div>

    <section class="people-panel">
      <div class="people-toolbar">
        <label class="people-search">
          <FontAwesomeIcon :icon="faMagnifyingGlass" />
          <input
            v-model="search"
            type="search"
            placeholder="Search people..."
          />
        </label>

        <label class="people-filter">
          <FontAwesomeIcon :icon="faFilter" />
          <select v-model="departmentFilter">
            <option value="all">All departments</option>
            <option
              v-for="department in departments"
              :key="department.id"
              :value="department.id"
            >
              {{ department.name }}
            </option>
          </select>
        </label>

        <button
          class="button button--ghost button--compact"
          type="button"
          :disabled="loading"
          @click="loadPeople"
        >
          <FontAwesomeIcon :icon="faRotateRight" />
          Refresh
        </button>
      </div>

      <div v-if="error" class="people-error">
        {{ error }}
      </div>

      <div v-if="loading" class="people-loading">
        Loading people…
      </div>

      <div v-else-if="!filteredPeople.length" class="people-empty">
        <FontAwesomeIcon :icon="faUser" />
        <strong>No people found</strong>
        <span>Try changing your search or department filter.</span>
      </div>

      <div v-else class="people-table-wrap">
        <table class="people-table">
          <thead>
            <tr>
              <th>Person</th>
              <th>Department</th>
              <th>Role</th>
              <th v-if="canManagePeople">Allowance</th>
              <th>Status</th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="person in filteredPeople"
              :key="person.id"
              @click="openPerson(person)"
            >
              <td>
                <div class="person-cell">
                  <div class="person-avatar">
                    <img
                      v-if="person.avatar_url && !person.avatarFailed"
                      :src="person.avatar_url"
                      :alt="person.name"
                      @error="person.avatarFailed = true"
                    />
                    <span v-else>{{ initials(person.name) }}</span>
                  </div>

                  <div class="person-copy">
                    <strong>{{ person.name }}</strong>
                    <span>{{ person.job_title || person.email }}</span>
                  </div>
                </div>
              </td>

              <td>
                <div class="department-cell">
                  <span
                    class="department-dot"
                    :style="{ backgroundColor: primaryDepartment(person)?.colour || '#555' }"
                  />
                  {{ primaryDepartment(person)?.name || 'No department' }}
                </div>
              </td>

              <td>
                <span class="role-badge">
                  {{ roleLabel(person.role) }}
                </span>
              </td>

              <td v-if="canManagePeople">
                <span v-if="person.allowance">
                  {{ person.allowance.remaining_days }} / {{ person.allowance.granted_days }} days
                </span>
                <span v-else>—</span>
              </td>

              <td>
                <span class="status-badge status-badge--active">
                  Active
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="canManageDepartments" class="department-management">
      <div class="department-management__heading">
        <div>
          <div class="eyebrow">STAFFING</div>
          <h2>Departments & limits</h2>
          <p>Control team membership and how many people can be away at the same time.</p>
        </div>

        <button class="button button--primary button--compact" type="button" @click="openDepartmentModal()">
          <FontAwesomeIcon :icon="faPlus" />
          Add department
        </button>
      </div>

      <div class="department-cards">
        <article v-for="department in departments" :key="department.id" class="department-card">
          <span class="department-card__colour" :style="{ backgroundColor: department.colour || '#777' }" />

          <div class="department-card__copy">
            <strong>{{ department.name }}</strong>
            <span>
              {{ department.member_count ?? department.members?.length ?? 0 }}
              {{ (department.member_count ?? department.members?.length ?? 0) === 1 ? 'member' : 'members' }}
            </span>
          </div>

          <div class="department-card__limit">
            <span>Maximum away</span>
            <strong>{{ department.maximum_absent ?? 'No limit' }}</strong>
          </div>

          <button class="icon-button" type="button" aria-label="Edit department" @click="openDepartmentModal(department)">
            <FontAwesomeIcon :icon="faPen" />
          </button>
        </article>
      </div>

      <div v-if="staffingGroups.length" class="staffing-groups">
        <div class="eyebrow">CROSS-TEAM RULES</div>

        <article v-for="group in staffingGroups" :key="group.id" class="staffing-group-card">
          <div>
            <strong>{{ group.name }}</strong>
            <span>{{ group.members?.map((member) => member.name).join(' + ') }}</span>
          </div>

          <span>
            Max {{ group.maximum_absent ?? '—' }} away
          </span>
        </article>
      </div>
    </section>

    <section v-if="canManageDepartments" class="approval-management">
      <div class="approval-management__heading">
        <div>
          <div class="eyebrow">APPROVALS</div>
          <h2>Approvers</h2>
          <p>Choose who approves leave for each department or individual person.</p>
        </div>

        <button
          v-if="auth.user?.permissions?.manage_settings"
          class="button button--primary button--compact"
          type="button"
          @click="openApproverRule()"
        >
          + Add approval rule
        </button>
      </div>

      <div v-if="!approverAssignments.length" class="approval-empty">
        <strong>No approval rules yet</strong>
        <span>
          Add a department rule first. Specific-person rules can then override it when needed.
        </span>
      </div>

      <div v-else class="approval-rules">
        <button
          v-for="assignment in approverAssignments"
          :key="assignment.id"
          class="approval-rule"
          type="button"
          @click="openApproverRule(assignment)"
        >
          <span class="approval-rule__scope">
            <small>{{ assignment.scope_type === 'person' ? 'PERSON' : 'DEPARTMENT' }}</small>
            <strong>{{ approvalScopeLabel(assignment) }}</strong>
          </span>

          <span class="approval-rule__arrow">→</span>

          <span class="approval-rule__approver">
            <small>APPROVER</small>
            <strong>{{ assignment.approver?.name }}</strong>
          </span>

          <span class="approval-rule__priority">
            Priority {{ assignment.priority }}
          </span>
        </button>
      </div>
    </section>

    <Teleport to="body">
      <Transition name="modal">
        <div
          v-if="departmentModalOpen"
          class="modal-shell"
          role="dialog"
          aria-modal="true"
          aria-labelledby="department-modal-title"
        >
          <button class="modal-shell__backdrop" type="button" aria-label="Close" @click="closeDepartmentModal" />

          <section class="department-modal">
            <header>
              <div>
                <div class="eyebrow">DEPARTMENT</div>
                <h2 id="department-modal-title">
                  {{ editingDepartment ? 'Edit department' : 'Add department' }}
                </h2>
              </div>

              <button class="icon-button" type="button" aria-label="Close" @click="closeDepartmentModal">
                <FontAwesomeIcon :icon="faXmark" />
              </button>
            </header>

            <div class="department-modal__body">
              <label class="field">
                <span class="field__label">Name</span>
                <span class="input-control">
                  <input v-model="departmentForm.name" type="text" placeholder="e.g. Front End" />
                </span>
              </label>

              <div class="department-form-row">
                <label class="field">
                  <span class="field__label">Colour</span>
                  <span class="input-control department-colour-input">
                    <input v-model="departmentForm.colour" type="color" />
                    <span>{{ departmentForm.colour }}</span>
                  </span>
                </label>

                <label class="field">
                  <span class="field__label">Maximum people away</span>
                  <span class="input-control">
                    <input
                      v-model="departmentForm.maximum_absent"
                      type="number"
                      min="1"
                      placeholder="No limit"
                    />
                  </span>
                </label>
              </div>

              <div class="department-members">
                <span class="field__label">Members</span>

                <button
                  v-for="person in people"
                  :key="person.id"
                  class="department-member"
                  :class="{ 'department-member--selected': departmentForm.member_ids.map(Number).includes(Number(person.id)) }"
                  type="button"
                  @click="toggleDepartmentMember(person.id)"
                >
                  <span class="department-member__avatar">{{ initials(person.name) }}</span>
                  <span>
                    <strong>{{ person.name }}</strong>
                    <small>{{ person.job_title || person.email }}</small>
                  </span>
                </button>
              </div>
            </div>

            <footer>
              <button class="button button--secondary" type="button" @click="closeDepartmentModal">
                Cancel
              </button>

              <button
                class="button button--primary"
                type="button"
                :disabled="savingDepartment || !departmentForm.name.trim()"
                @click="saveDepartment"
              >
                {{ savingDepartment ? 'Saving…' : 'Save department' }}
              </button>
            </footer>
          </section>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="modal">
        <div
          v-if="selectedPerson"
          class="modal-shell"
          role="dialog"
          aria-modal="true"
          aria-labelledby="person-detail-title"
        >
          <button
            class="modal-shell__backdrop"
            type="button"
            aria-label="Close person details"
            @click="closePerson"
          />

          <section class="person-modal">
            <header>
              <div class="person-modal__identity">
                <div class="person-modal__avatar">
                  <img
                    v-if="selectedPerson.avatar_url && !selectedPerson.avatarFailed"
                    :src="selectedPerson.avatar_url"
                    :alt="selectedPerson.name"
                    @error="selectedPerson.avatarFailed = true"
                  />
                  <span v-else>{{ initials(selectedPerson.name) }}</span>
                </div>

                <div>
                  <div class="eyebrow">PERSON</div>
                  <h2 id="person-detail-title">{{ selectedPerson.name }}</h2>
                  <p>{{ selectedPerson.job_title || selectedPerson.email }}</p>
                </div>
              </div>

              <button class="icon-button" type="button" aria-label="Close" @click="closePerson">
                <FontAwesomeIcon :icon="faXmark" />
              </button>
            </header>

            <div class="person-modal__body">
              <section class="person-detail-section">
                <div class="eyebrow">PROFILE</div>

                <dl>
                  <div>
                    <dt>Email</dt>
                    <dd>{{ selectedPerson.email }}</dd>
                  </div>

                  <div v-if="auth.user?.permissions?.manage_settings">
                    <dt>Invite link</dt>
                    <dd class="person-invite-link-row">
                      <code>
                        {{
                          selectedPerson.invite_url
                            || window.location.origin + '/login?invite=' + encodeURIComponent(selectedPerson.email)
                        }}
                      </code>
                      <button
                        class="person-invite-copy"
                        type="button"
                        @click="copyPersonInvite(selectedPerson)"
                      >
                        {{ inviteCopiedPersonId === selectedPerson.id ? 'Copied!' : 'Copy' }}
                      </button>
                    </dd>
                  </div>
                  <div>
                    <dt>Role</dt>
                    <dd>{{ roleLabel(selectedPerson.role) }}</dd>
                  </div>
                  <div>
                    <dt>Department</dt>
                    <dd>{{ primaryDepartment(selectedPerson)?.name || 'No department' }}</dd>
                  </div>
                  <div>
                    <dt>Start date</dt>
                    <dd>{{ formatDate(selectedPerson.employment_start_date) }}</dd>
                  </div>
                </dl>
              </section>

              <section v-if="canManagePeople" class="person-detail-section">
                <div class="eyebrow">HOLIDAY SETUP</div>

                <div class="person-stats">
                  <article>
                    <FontAwesomeIcon :icon="faCalendarDays" />
                    <span>Remaining</span>
                    <strong>
                      {{ selectedPerson.allowance?.remaining_days ?? '—' }}
                      <small>days</small>
                    </strong>
                  </article>

                  <article>
                    <FontAwesomeIcon :icon="faCalendarDays" />
                    <span>Total</span>
                    <strong>
                      {{ selectedPerson.allowance?.granted_days ?? '—' }}
                      <small>days</small>
                    </strong>
                  </article>
                </div>

                <dl>
                  <div>
                    <dt>Allowance unit</dt>
                    <dd>{{ selectedPerson.allowance_unit || 'days' }}</dd>
                  </div>
                  <div>
                    <dt>Bank holidays</dt>
                    <dd>{{ selectedPerson.bank_holiday_division || 'Organisation default' }}</dd>
                  </div>
                </dl>
              </section>
            </div>

            <footer>
              <button class="button button--secondary" type="button" @click="closePerson">
                Close
              </button>

              <button
                v-if="auth.user?.permissions?.manage_settings"
                class="button button--secondary"
                type="button"
                @click="openAllowanceAdjustment(selectedPerson)"
              >
                Adjust allowance
              </button>

              <button
                v-if="auth.user?.permissions?.manage_settings"
                class="button button--secondary"
                type="button"
                @click="openManualLeave(selectedPerson)"
              >
                Add leave
              </button>

              <button
                v-if="auth.user?.permissions?.manage_settings"
                class="button button--primary"
                type="button"
                @click="openEditPerson(selectedPerson)"
              >
                Edit person
              </button>
            </footer>
          </section>
        </div>
      </Transition>
    </Teleport>
  </section>

    <ManagePersonModal
      :open="managePersonOpen"
      :person="editingPerson"
      @close="closeManagePerson"
      @saved="onPersonSaved"
      @archived="onPersonArchived"
    />

    <ManageApproverRuleModal
      :open="approverRuleOpen"
      :assignment="editingApproverRule"
      :people="people"
      :departments="departments"
      @close="closeApproverRule"
      @saved="onApproverRuleChanged"
      @deleted="onApproverRuleChanged"
    />

    <ManualLeaveModal
      :open="manualLeaveOpen"
      :person="manualLeavePerson"
      @close="closeManualLeave"
      @created="onManualLeaveCreated"
    />

    <AdjustAllowanceModal
      :open="allowanceAdjustOpen"
      :person="allowanceAdjustPerson"
      @close="closeAllowanceAdjustment"
      @adjusted="onAllowanceAdjusted"
    />
</template>

<style scoped>
.people-heading {
  align-items: flex-end;
}

.people-heading__count {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  color: #777;
  font-size: 10px;
}

.people-panel {
  border: 1px solid #262626;
  background: #101010;
}

.people-toolbar {
  display: flex;
  min-height: 62px;
  align-items: center;
  gap: 10px;
  padding: 11px 14px;
  border-bottom: 1px solid #262626;
}

.people-search,
.people-filter {
  display: flex;
  min-height: 38px;
  align-items: center;
  gap: 8px;
  border: 1px solid #303030;
  background: #141414;
  color: #666;
}

.people-search {
  width: min(360px, 100%);
  padding: 0 11px;
}

.people-search input,
.people-filter select {
  border: 0;
  outline: 0;
  background: transparent;
  color: #d8d3cd;
  font: inherit;
  font-size: 10px;
}

.people-search input {
  width: 100%;
}

.people-search input::placeholder {
  color: #666;
}

.people-filter {
  margin-left: auto;
  padding: 0 9px;
}

.people-filter select {
  cursor: pointer;
}

.people-error {
  margin: 14px;
  padding: 11px 12px;
  border: 1px solid rgba(239, 91, 63, 0.4);
  background: rgba(239, 91, 63, 0.08);
  color: #f4a898;
  font-size: 10px;
}

.people-loading,
.people-empty {
  display: grid;
  min-height: 280px;
  place-items: center;
  align-content: center;
  gap: 8px;
  padding: 30px;
  color: #686868;
  text-align: center;
  font-size: 9px;
}

.people-empty svg {
  font-size: 20px;
}

.people-empty strong {
  color: #cec8c1;
  font-size: 12px;
}

.people-table-wrap {
  overflow-x: auto;
}

.people-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 820px;
}

.people-table th,
.people-table td {
  padding: 13px 15px;
  border-bottom: 1px solid #242424;
  text-align: left;
}

.people-table th {
  background: #0e0e0e;
  color: #666;
  font-size: 8px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.people-table td {
  color: #96908a;
  font-size: 9px;
}

.people-table tbody tr {
  cursor: pointer;
}

.people-table tbody tr:hover {
  background: #151515;
}

.people-table tbody tr:last-child td {
  border-bottom: 0;
}

.person-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.person-avatar,
.person-modal__avatar {
  display: grid;
  overflow: hidden;
  place-items: center;
  border: 1px solid #343434;
  background: #1a1a1a;
  color: #d8d2cc;
  font-weight: 700;
}

.person-avatar {
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  font-size: 9px;
}

.person-avatar img,
.person-modal__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.person-copy {
  display: grid;
  gap: 3px;
}

.person-copy strong {
  color: #e3ddd6;
  font-size: 10px;
  font-weight: 600;
}

.person-copy span {
  color: #686868;
  font-size: 8px;
}

.department-cell {
  display: flex;
  align-items: center;
  gap: 7px;
}

.department-dot {
  width: 7px;
  height: 7px;
  flex: 0 0 7px;
  border-radius: 50%;
}

.role-badge,
.status-badge {
  display: inline-flex;
  min-height: 22px;
  align-items: center;
  padding: 0 6px;
  border: 1px solid #333;
  background: #171717;
  font-size: 8px;
  font-weight: 600;
}

.role-badge {
  color: #aaa49d;
}

.status-badge--active {
  color: #9fd356;
}

.person-modal {
  position: relative;
  z-index: 2;
  width: min(680px, calc(100vw - 32px));
  max-height: calc(100vh - 40px);
  overflow: auto;
  border: 1px solid #2e2e2e;
  background: #111;
  box-shadow: 0 28px 80px rgba(0, 0, 0, 0.55);
}

.person-modal > header,
.person-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 20px;
}

.person-modal > header {
  border-bottom: 1px solid #262626;
}

.person-modal > footer {
  justify-content: flex-end;
  border-top: 1px solid #262626;
}

.person-modal__identity {
  display: flex;
  align-items: center;
  gap: 13px;
}

.person-modal__avatar {
  width: 46px;
  height: 46px;
  flex: 0 0 46px;
  font-size: 11px;
}

.person-modal h2 {
  margin: 3px 0 2px;
  color: #eee9e3;
  font-size: 18px;
}

.person-modal header p {
  margin: 0;
  color: #77716c;
  font-size: 9px;
}

.person-modal__body {
  display: grid;
  gap: 24px;
  padding: 20px;
}

.person-detail-section {
  display: grid;
  gap: 12px;
}

.person-detail-section dl {
  display: grid;
  gap: 1px;
  margin: 0;
  background: #292929;
}

.person-detail-section dl > div {
  display: grid;
  grid-template-columns: 140px 1fr;
  gap: 18px;
  padding: 12px;
  background: #151515;
}

.person-detail-section dt {
  color: #6d6964;
  font-size: 9px;
}

.person-detail-section dd {
  margin: 0;
  color: #c8c1ba;
  font-size: 10px;
}

.person-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  border: 1px solid #292929;
  background: #292929;
}

.person-stats article {
  display: grid;
  grid-template-columns: 18px 1fr auto;
  align-items: center;
  gap: 8px;
  padding: 14px;
  background: #151515;
}

.person-stats article > svg {
  color: #777;
}

.person-stats article > span {
  color: #77716b;
  font-size: 9px;
}

.person-stats strong {
  color: #eee8e1;
  font-size: 16px;
  font-weight: 600;
}

.person-stats strong small {
  color: #777;
  font-size: 8px;
  font-weight: 500;
}

@media (max-width: 720px) {
  .people-toolbar {
    align-items: stretch;
    flex-direction: column;
  }

  .people-search,
  .people-filter {
    width: 100%;
    margin-left: 0;
  }

  .people-filter select {
    width: 100%;
  }

  .person-detail-section dl > div {
    grid-template-columns: 1fr;
    gap: 5px;
  }

  .person-stats {
    grid-template-columns: 1fr;
  }
}
/* Patch 13 — department management */
.department-management {
  margin-top: 18px;
  border: 1px solid #262626;
  background: #101010;
}

.department-management__heading {
  display: flex;
  min-height: 78px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 15px 16px;
  border-bottom: 1px solid #262626;
}

.department-management__heading h2 {
  margin: 3px 0 2px;
  color: #eee9e3;
  font-size: 18px;
}

.department-management__heading p {
  margin: 0;
  color: #74706b;
  font-size: 9px;
}

.department-cards {
  display: grid;
}

.department-card {
  display: grid;
  grid-template-columns: 4px minmax(0, 1fr) 140px 38px;
  min-height: 68px;
  align-items: center;
  gap: 14px;
  border-bottom: 1px solid #242424;
}

.department-card:last-child {
  border-bottom: 0;
}

.department-card__colour {
  align-self: stretch;
}

.department-card__copy {
  display: grid;
  gap: 3px;
}

.department-card__copy strong {
  color: #ddd7d1;
  font-size: 11px;
}

.department-card__copy span,
.department-card__limit span {
  color: #6e6a66;
  font-size: 8px;
}

.department-card__limit {
  display: grid;
  gap: 3px;
}

.department-card__limit strong {
  color: #c9c3bd;
  font-size: 10px;
}

.staffing-groups {
  display: grid;
  gap: 10px;
  padding: 16px;
  border-top: 1px solid #262626;
}

.staffing-group-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 12px;
  border: 1px solid #2b2b2b;
  background: #151515;
}

.staffing-group-card > div {
  display: grid;
  gap: 3px;
}

.staffing-group-card strong {
  color: #ddd7d1;
  font-size: 10px;
}

.staffing-group-card span {
  color: #77716b;
  font-size: 8px;
}

.department-modal {
  position: relative;
  z-index: 2;
  width: min(680px, calc(100vw - 32px));
  max-height: calc(100vh - 40px);
  overflow: auto;
  border: 1px solid #2e2e2e;
  background: #111;
}

.department-modal > header,
.department-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 20px;
}

.department-modal > header {
  border-bottom: 1px solid #262626;
}

.department-modal > footer {
  justify-content: flex-end;
  border-top: 1px solid #262626;
}

.department-modal h2 {
  margin: 3px 0 0;
  color: #eee9e3;
  font-size: 18px;
}

.department-modal__body {
  display: grid;
  gap: 18px;
  padding: 20px;
}

.department-form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.department-colour-input {
  display: flex;
  align-items: center;
  gap: 10px;
}

.department-colour-input input[type='color'] {
  width: 34px;
  height: 28px;
  padding: 0;
  border: 0;
  background: transparent;
}

.department-colour-input span {
  color: #aaa49e;
  font-size: 9px;
}

.department-members {
  display: grid;
  gap: 6px;
}

.department-member {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border: 1px solid #292929;
  background: #141414;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.department-member:hover,
.department-member--selected {
  border-color: #4a3a36;
  background: #191615;
}

.department-member--selected {
  box-shadow: inset 2px 0 0 #ef5b3f;
}

.department-member__avatar {
  display: grid;
  width: 30px;
  height: 30px;
  flex: 0 0 30px;
  place-items: center;
  border: 1px solid #343434;
  background: #1d1d1d;
  color: #ddd7d1;
  font-size: 8px;
  font-weight: 700;
}

.department-member > span:last-child {
  display: grid;
  gap: 2px;
}

.department-member strong {
  color: #d9d3cd;
  font-size: 10px;
}

.department-member small {
  color: #6f6a65;
  font-size: 8px;
}

@media (max-width: 720px) {
  .department-management__heading {
    align-items: flex-start;
    flex-direction: column;
  }

  .department-card {
    grid-template-columns: 4px minmax(0, 1fr) 38px;
  }

  .department-card__limit {
    grid-column: 2;
  }

  .department-form-row {
    grid-template-columns: 1fr;
  }
}


/* Patch 13B — visible department form controls */
.department-modal .input-control,
.department-modal .textarea-control,
.department-modal .select-control {
  display: flex;
  min-height: 42px;
  align-items: center;
  width: 100%;
  padding: 0 12px;
  border: 1px solid #343434;
  background: #151515;
  box-sizing: border-box;
}

.department-modal .input-control:focus-within,
.department-modal .textarea-control:focus-within,
.department-modal .select-control:focus-within {
  border-color: #5a5a5a;
}

.department-modal .input-control input,
.department-modal .textarea-control textarea,
.department-modal .select-control select {
  width: 100%;
  min-width: 0;
  border: 0;
  outline: 0;
  background: transparent;
  color: #ece7e1;
  font: inherit;
  font-size: 11px;
  box-sizing: border-box;
}

.department-modal .input-control input::placeholder,
.department-modal .textarea-control textarea::placeholder {
  color: #666;
}

.department-modal .department-colour-input {
  gap: 10px;
  padding: 6px 10px;
}

.department-modal .department-colour-input input[type='color'] {
  width: 38px;
  height: 28px;
  flex: 0 0 38px;
  padding: 0;
  border: 1px solid #454545;
  background: transparent;
  cursor: pointer;
}

.department-members {
  padding-top: 2px;
}

.department-member {
  border: 1px solid #343434;
}

.department-member:hover {
  border-color: #4a4a4a;
}

.department-member--selected {
  border-color: #ef5b3f;
}


/* Patch 13C — department readability + spacing */
.department-card {
  padding-right: 16px;
}

.department-card__copy strong {
  font-size: 13px;
}

.department-card__copy span,
.department-card__limit span {
  font-size: 10px;
}

.department-card__limit strong {
  font-size: 12px;
}

.department-card > .icon-button {
  margin-right: 2px;
}

.staffing-group-card {
  padding-right: 16px;
}

.staffing-group-card strong {
  font-size: 12px;
}

.staffing-group-card span {
  font-size: 10px;
}

@media (max-width: 720px) {
  .department-card {
    padding-right: 12px;
  }

  .department-card__copy strong {
    font-size: 12px;
  }

  .department-card__copy span,
  .department-card__limit span {
    font-size: 9px;
  }

  .department-card__limit strong {
    font-size: 11px;
  }

  .staffing-group-card {
    padding-right: 12px;
  }
}



/* Patch 13D — people directory typography */
.people-toolbar {
  gap: 12px;
  padding: 14px 18px;
}

.people-search,
.people-filter {
  min-height: 44px;
}

.people-search input,
.people-filter select {
  font-size: 12px;
}

.people-error {
  font-size: 12px;
}

.people-empty,
.people-loading {
  font-size: 11px;
}

.people-empty strong {
  font-size: 14px;
}

.people-table th,
.people-table td {
  padding: 16px 18px;
}

.people-table th {
  font-size: 10px;
  letter-spacing: 0.8px;
}

.people-table td {
  font-size: 11px;
}

.person-cell {
  gap: 14px;
}

.person-avatar {
  width: 46px;
  height: 46px;
  flex: 0 0 46px;
  font-size: 13px;
}

.person-copy {
  gap: 4px;
}

.person-copy strong {
  font-size: 15px;
}

.person-copy span {
  font-size: 11px;
}

.department-cell {
  gap: 9px;
  font-size: 12px;
}

.department-dot {
  width: 8px;
  height: 8px;
  flex: 0 0 8px;
}

.role-badge,
.status-badge {
  min-height: 26px;
  padding: 0 10px;
  font-size: 10px;
}

@media (max-width: 900px) {
  .people-toolbar {
    padding: 12px 14px;
  }

  .people-search input,
  .people-filter select,
  .people-error {
    font-size: 11px;
  }

  .people-table th,
  .people-table td {
    padding: 14px 14px;
  }

  .people-table th {
    font-size: 9px;
  }

  .people-table td {
    font-size: 10px;
  }

  .person-avatar {
    width: 40px;
    height: 40px;
    flex-basis: 40px;
    font-size: 11px;
  }

  .person-copy strong {
    font-size: 13px;
  }

  .person-copy span,
  .department-cell {
    font-size: 10px;
  }

  .role-badge,
  .status-badge {
    font-size: 9px;
  }
}


/* Patch 14 — people management */
.people-heading__actions {
  display: flex;
  align-items: center;
  gap: 14px;
}

.people-add-person__plus {
  font-size: 16px;
  font-weight: 500;
  line-height: 1;
}

@media (max-width: 680px) {
  .people-heading__actions {
    width: 100%;
    align-items: flex-start;
    flex-direction: column;
  }
}


/* Patch 15 — approval management */
.approval-management {
  margin-top: 18px;
  border: 1px solid #262626;
  background: #101010;
}

.approval-management__heading {
  display: flex;
  min-height: 78px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 15px 16px;
  border-bottom: 1px solid #262626;
}

.approval-management__heading h2 {
  margin: 3px 0 2px;
  color: #eee9e3;
  font-size: 18px;
}

.approval-management__heading p {
  margin: 0;
  color: #74706b;
  font-size: 10px;
}

.approval-empty {
  display: grid;
  gap: 5px;
  padding: 22px 16px;
}

.approval-empty strong {
  color: #d8d2cc;
  font-size: 12px;
}

.approval-empty span {
  color: #74706b;
  font-size: 10px;
}

.approval-rules {
  display: grid;
}

.approval-rule {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 36px minmax(0, 1fr) 100px;
  min-height: 70px;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
  border: 0;
  border-bottom: 1px solid #252525;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.approval-rule:last-child {
  border-bottom: 0;
}

.approval-rule:hover {
  background: #151515;
}

.approval-rule__scope,
.approval-rule__approver {
  display: grid;
  gap: 4px;
}

.approval-rule small {
  color: #666;
  font-size: 8px;
  font-weight: 700;
}

.approval-rule strong {
  color: #ddd7d1;
  font-size: 12px;
}

.approval-rule__arrow {
  color: #ef5b3f;
  font-size: 16px;
}

.approval-rule__priority {
  justify-self: end;
  color: #77716b;
  font-size: 9px;
}

@media (max-width: 720px) {
  .approval-management__heading {
    align-items: flex-start;
    flex-direction: column;
  }

  .approval-rule {
    grid-template-columns: 1fr auto;
    padding: 12px;
  }

  .approval-rule__arrow {
    display: none;
  }

  .approval-rule__priority {
    grid-column: 2;
    grid-row: 1 / span 2;
  }
}


/* Patch 28A visible invite link */
.person-invite-link-row {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 10px;
}

.person-invite-link-row code {
  min-width: 0;
  overflow: hidden;
  color: #aaa49e;
  font-family: inherit;
  font-size: 9px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.person-invite-copy {
  flex: 0 0 auto;
  padding: 5px 8px;
  border: 1px solid #353535;
  background: #181818;
  color: #d9d3cd;
  font: inherit;
  font-size: 9px;
  cursor: pointer;
}

.person-invite-copy:hover {
  border-color: #555;
}

</style>
