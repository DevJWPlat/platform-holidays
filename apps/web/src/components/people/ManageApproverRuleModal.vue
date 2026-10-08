<script setup>
import { computed, ref, watch } from 'vue'
import { faTrash, faXmark } from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import CustomSelect from '@/components/ui/CustomSelect.vue'
import CustomDatePicker from '@/components/ui/CustomDatePicker.vue'

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  assignment: {
    type: Object,
    default: null,
  },
  people: {
    type: Array,
    default: () => [],
  },
  departments: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['close', 'saved', 'deleted'])

const saving = ref(false)
const deleting = ref(false)
const error = ref('')

const form = ref({
  scope_type: 'department',
  employee_id: '',
  department_id: '',
  approver_id: '',
  priority: 1,
  effective_from: '',
  effective_until: '',
})

const editing = computed(() => Boolean(props.assignment?.id))

const scopeOptions = [
  { value: 'department', label: 'Department' },
  { value: 'person', label: 'Specific person' },
]

const departmentOptions = computed(() =>
  props.departments.map((department) => ({
    value: String(department.id),
    label: department.name,
  })),
)

const peopleOptions = computed(() =>
  props.people.map((person) => ({
    value: String(person.id),
    label: person.job_title
      ? `${person.name} — ${person.job_title}`
      : person.name,
  })),
)

const approverOptions = computed(() =>
  props.people.map((person) => ({
    value: String(person.id),
    label: person.job_title
      ? `${person.name} — ${person.job_title}`
      : person.name,
  })),
)

function reset() {
  error.value = ''

  if (!props.assignment) {
    form.value = {
      scope_type: 'department',
      employee_id: '',
      department_id: props.departments[0]?.id
        ? String(props.departments[0].id)
        : '',
      approver_id: '',
      priority: 1,
      effective_from: '',
      effective_until: '',
    }
    return
  }

  form.value = {
    scope_type: props.assignment.scope_type || 'department',
    employee_id: props.assignment.employee?.id
      ? String(props.assignment.employee.id)
      : '',
    department_id: props.assignment.department?.id
      ? String(props.assignment.department.id)
      : '',
    approver_id: props.assignment.approver?.id
      ? String(props.assignment.approver.id)
      : '',
    priority: props.assignment.priority || 1,
    effective_from: props.assignment.effective_from || '',
    effective_until: props.assignment.effective_until || '',
  }
}

async function save() {
  error.value = ''

  if (!form.value.approver_id) {
    error.value = 'Choose an approver.'
    return
  }

  if (form.value.scope_type === 'department' && !form.value.department_id) {
    error.value = 'Choose a department.'
    return
  }

  if (form.value.scope_type === 'person' && !form.value.employee_id) {
    error.value = 'Choose a person.'
    return
  }

  saving.value = true

  try {
    await initialiseCsrf()

    const payload = {
      scope_type: form.value.scope_type,
      employee_id:
        form.value.scope_type === 'person'
          ? Number(form.value.employee_id)
          : null,
      department_id:
        form.value.scope_type === 'department'
          ? Number(form.value.department_id)
          : null,
      approver_id: Number(form.value.approver_id),
      priority: Number(form.value.priority || 1),
      effective_from: form.value.effective_from || null,
      effective_until: form.value.effective_until || null,
    }

    const response = editing.value
      ? await api.put(
          `/api/v1/approver-assignments/${props.assignment.id}`,
          payload,
        )
      : await api.post('/api/v1/approver-assignments', payload)

    emit('saved', response.data?.data)
    emit('close')
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not save this approval rule.'
  } finally {
    saving.value = false
  }
}

async function removeRule() {
  if (!editing.value) return
  if (!window.confirm('Remove this approval rule?')) return

  deleting.value = true
  error.value = ''

  try {
    await initialiseCsrf()
    await api.delete(`/api/v1/approver-assignments/${props.assignment.id}`)
    emit('deleted', props.assignment.id)
    emit('close')
  } catch (requestError) {
    error.value =
      requestError.response?.data?.message || 'Could not remove this approval rule.'
  } finally {
    deleting.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) reset()
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
        aria-labelledby="approver-rule-title"
      >
        <button
          class="modal-shell__backdrop"
          type="button"
          aria-label="Close"
          @click="$emit('close')"
        />

        <section class="approver-modal">
          <header>
            <div>
              <div class="eyebrow">APPROVALS</div>
              <h2 id="approver-rule-title">
                {{ editing ? 'Edit approval rule' : 'Add approval rule' }}
              </h2>
            </div>

            <button class="icon-button" type="button" aria-label="Close" @click="$emit('close')">
              <FontAwesomeIcon :icon="faXmark" />
            </button>
          </header>

          <div class="approver-modal__body">
            <div v-if="error" class="approver-modal__error">
              {{ error }}
            </div>

            <label class="field">
              <span class="field__label">Rule applies to</span>
              <CustomSelect
                v-model="form.scope_type"
                :options="scopeOptions"
              />
            </label>

            <label v-if="form.scope_type === 'department'" class="field">
              <span class="field__label">Department</span>
              <CustomSelect
                v-model="form.department_id"
                :options="departmentOptions"
                placeholder="Choose department"
              />
            </label>

            <label v-else class="field">
              <span class="field__label">Person</span>
              <CustomSelect
                v-model="form.employee_id"
                :options="peopleOptions"
                placeholder="Choose person"
              />
            </label>

            <label class="field">
              <span class="field__label">Approver</span>
              <CustomSelect
                v-model="form.approver_id"
                :options="approverOptions"
                placeholder="Choose approver"
              />
            </label>

            <div class="approver-modal__grid">
              <label class="field">
                <span class="field__label">Priority</span>
                <span class="approver-input">
                  <input v-model="form.priority" type="number" min="1" max="999" />
                </span>
              </label>

              <div class="approver-priority-note">
                <strong>Lower number = higher priority</strong>
                <span>
                  Priority is stored now so we can support ordered/multi-step approvals later.
                </span>
              </div>
            </div>

            <div class="approver-modal__grid">
              <label class="field">
                <span class="field__label">Starts</span>
                <CustomDatePicker
                  v-model="form.effective_from"
                  placeholder="Immediately"
                />
              </label>

              <label class="field">
                <span class="field__label">Ends</span>
                <CustomDatePicker
                  v-model="form.effective_until"
                  :min="form.effective_from"
                  placeholder="No end date"
                />
              </label>
            </div>

            <div class="approver-rule-help">
              <strong>Specific person rules override department rules.</strong>
              <span>
                If someone has their own approval rule, department approvers will not be used for that person.
              </span>
            </div>
          </div>

          <footer>
            <button
              v-if="editing"
              class="button button--danger approver-delete"
              type="button"
              :disabled="deleting || saving"
              @click="removeRule"
            >
              <FontAwesomeIcon :icon="faTrash" />
              {{ deleting ? 'Removing…' : 'Remove rule' }}
            </button>

            <div class="approver-modal__actions">
              <button class="button button--secondary" type="button" @click="$emit('close')">
                Cancel
              </button>

              <button
                class="button button--primary"
                type="button"
                :disabled="saving || deleting"
                @click="save"
              >
                {{ saving ? 'Saving…' : 'Save rule' }}
              </button>
            </div>
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.approver-modal {
  position: relative;
  z-index: 2;
  width: min(660px, calc(100vw - 32px));
  max-height: calc(100vh - 36px);
  overflow: auto;
  border: 1px solid #303030;
  background: #111;
}

.approver-modal > header,
.approver-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 22px;
}

.approver-modal > header {
  border-bottom: 1px solid #292929;
}

.approver-modal > footer {
  border-top: 1px solid #292929;
}

.approver-modal h2 {
  margin: 4px 0 0;
  color: #eee9e3;
  font-size: 21px;
}

.approver-modal__body {
  display: grid;
  gap: 17px;
  padding: 22px;
}

.approver-modal__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  align-items: end;
}

.approver-input {
  display: flex;
  min-height: 44px;
  align-items: center;
  border: 1px solid #373737;
  background: #151515;
}

.approver-input input {
  width: 100%;
  height: 42px;
  padding: 0 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 11px;
}

.approver-priority-note,
.approver-rule-help {
  display: grid;
  gap: 4px;
  padding: 11px 12px;
  border: 1px solid #303030;
  background: #151515;
}

.approver-priority-note strong,
.approver-rule-help strong {
  color: #d7d1cb;
  font-size: 10px;
}

.approver-priority-note span,
.approver-rule-help span {
  color: #77716b;
  font-size: 9px;
  line-height: 1.45;
}

.approver-modal__error {
  padding: 11px 12px;
  border: 1px solid rgba(239, 91, 63, 0.45);
  background: rgba(239, 91, 63, 0.08);
  color: #f3a393;
  font-size: 10px;
}

.approver-modal__actions {
  display: flex;
  gap: 10px;
  margin-left: auto;
}

.approver-delete {
  margin-right: auto;
}

@media (max-width: 680px) {
  .approver-modal__grid {
    grid-template-columns: 1fr;
  }

  .approver-modal > footer {
    align-items: stretch;
    flex-direction: column;
  }

  .approver-delete {
    width: 100%;
    margin-right: 0;
  }

  .approver-modal__actions {
    width: 100%;
    margin-left: 0;
  }

  .approver-modal__actions .button {
    flex: 1;
  }
}
</style>
