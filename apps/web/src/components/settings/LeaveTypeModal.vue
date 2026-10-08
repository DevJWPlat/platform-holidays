<script setup>
import { computed, ref, watch } from 'vue'
import { faSearch, faTrash, faXmark, faCircleInfo } from '@fortawesome/free-solid-svg-icons'
import { leaveTypeIconChoices } from '@/utils/leaveTypeIcons'
import api, { initialiseCsrf } from '@/api/client'

const props = defineProps({
  open: { type: Boolean, default: false },
  leaveType: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved', 'deleted'])

const saving = ref(false)
const deleting = ref(false)
const error = ref('')
const iconSearch = ref('')

const form = ref({
  label: '',
  colour: '#777777',
  icon: 'calendar-day',
  icon_colour: 'white',
  requires_approval: true,
  allow_half_days: true,
  include_in_staffing_limits: true,
})

const iconChoices = leaveTypeIconChoices

const iconColourOptions = [{ value: 'white', label: 'White' }, { value: 'black', label: 'Black' }]

const editing = computed(() => Boolean(props.leaveType?.id))
const protectedHoliday = computed(() =>
  Boolean(props.leaveType?.is_protected_holiday),
)

const filteredIcons = computed(() => {
  const search = iconSearch.value.trim().toLowerCase()
  if (!search) return iconChoices

  return iconChoices.filter((item) =>
    item.label.toLowerCase().includes(search)
    || item.key.includes(search),
  )
})

function reset() {
  error.value = ''
  iconSearch.value = ''

  if (!props.leaveType) {
    form.value = {
      label: '',
      colour: '#777777',
      icon: 'calendar-day',
      icon_colour: 'white',
      requires_approval: true,
      allow_half_days: true,
      include_in_staffing_limits: true,
    }
    return
  }

  form.value = {
    label: props.leaveType.label || '',
    colour: props.leaveType.colour || '#777777',
    icon: props.leaveType.icon || 'calendar-day',
    icon_colour: props.leaveType.icon_colour || 'white',
    requires_approval: Boolean(props.leaveType.requires_approval),
    allow_half_days: protectedHoliday.value
      ? false
      : props.leaveType.allow_half_days !== false,
    include_in_staffing_limits:
      props.leaveType.include_in_staffing_limits !== false,
  }
}

async function save() {
  if (!form.value.label.trim()) {
    error.value = 'Enter a leave type name.'
    return
  }

  saving.value = true
  error.value = ''

  try {
    await initialiseCsrf()

    const payload = {
      ...form.value,
      label: form.value.label.trim(),
      allow_half_days: protectedHoliday.value
        ? false
        : Boolean(form.value.allow_half_days),
    }

    const response = editing.value
      ? await api.put(`/api/v1/leave-types/${props.leaveType.id}`, payload)
      : await api.post('/api/v1/leave-types', payload)

    emit('saved', response.data?.data)
    emit('close')
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not save leave type.'
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!editing.value || protectedHoliday.value) return
  if (!window.confirm(`Remove ${props.leaveType.label}?`)) return

  deleting.value = true
  error.value = ''

  try {
    await initialiseCsrf()
    await api.delete(`/api/v1/leave-types/${props.leaveType.id}`)
    emit('deleted', props.leaveType.id)
    emit('close')
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not remove leave type.'
  } finally {
    deleting.value = false
  }
}

watch(() => props.open, (open) => {
  if (open) reset()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="open" class="modal-shell" role="dialog" aria-modal="true">
        <button class="modal-shell__backdrop" type="button" aria-label="Close" @click="$emit('close')" />

        <section class="leave-type-modal">
          <header>
            <div>
              <div class="eyebrow">LEAVE TYPES</div>
              <h2>{{ editing ? 'Edit leave type' : 'Add leave type' }}</h2>
            </div>

            <button class="icon-button" type="button" aria-label="Close" @click="$emit('close')">
              <FontAwesomeIcon :icon="faXmark" />
            </button>
          </header>

          <div class="leave-type-modal__body">
            <div v-if="error" class="leave-type-error">{{ error }}</div>

            <div v-if="protectedHoliday" class="leave-type-protected">
              <FontAwesomeIcon :icon="faCircleInfo" />
              <span>
                Holiday is a protected system leave type. It cannot be removed and half days stay disabled.
              </span>
            </div>

            <label class="field">
              <span class="field__label">Name</span>
              <span class="settings-input">
                <input v-model="form.label" type="text" placeholder="e.g. Bereavement" />
              </span>
            </label>

            <label class="field">
              <span class="field__label">Colour</span>
              <span class="settings-colour">
                <input v-model="form.colour" type="color" />
                <span>{{ form.colour }}</span>
              </span>
            </label>

            <section class="leave-icon-picker">
              <div>
                <span class="field__label">Icon</span>
                <label class="leave-icon-search">
                  <FontAwesomeIcon :icon="faSearch" />
                  <input v-model="iconSearch" type="search" placeholder="Search icons..." />
                </label>
              </div>

              <div class="leave-icon-grid">
                <button
                  v-for="item in filteredIcons"
                  :key="item.key"
                  class="leave-icon-option"
                  :class="{ 'leave-icon-option--selected': form.icon === item.key }"
                  type="button"
                  :title="item.label"
                  @click="form.icon = item.key"
                >
                  <FontAwesomeIcon :icon="item.icon" />
                  <span>{{ item.label }}</span>
                </button>
              </div>
            </section>
            <section class="leave-icon-colour-setting"><span class="field__label">Icon colour</span><div class="leave-icon-colour-options">
              <button v-for="o in iconColourOptions" :key="o.value" type="button" class="leave-icon-colour-option" :class="{ 'leave-icon-colour-option--selected': form.icon_colour === o.value }" @click="form.icon_colour=o.value"><span class="leave-icon-colour-preview" :class="`leave-icon-colour-preview--${o.value}`"/>{{o.label}}</button>
            </div></section>
            <section class="leave-type-rules">
              <label class="setting-toggle">
                <input v-model="form.requires_approval" type="checkbox" />
                <span>
                  <strong>Requires approval</strong>
                  <small>Requests stay pending until an approver accepts them.</small>
                </span>
              </label>

              <label class="setting-toggle" :class="{ 'setting-toggle--disabled': protectedHoliday }">
                <input
                  v-model="form.allow_half_days"
                  type="checkbox"
                  :disabled="protectedHoliday"
                />
                <span>
                  <strong>Allow half days</strong>
                  <small>
                    Shows Morning / Afternoon session controls when booking this leave type.
                  </small>
                </span>
              </label>

              <label class="setting-toggle">
                <input v-model="form.include_in_staffing_limits" type="checkbox" />
                <span>
                  <strong>Counts towards staffing limits</strong>
                  <small>
                    Include this leave type when checking department and cross-team availability.
                  </small>
                </span>
              </label>
            </section>
          </div>

          <footer>
            <button
              v-if="editing && !protectedHoliday"
              class="button button--danger leave-type-delete"
              type="button"
              :disabled="deleting || saving"
              @click="remove"
            >
              <FontAwesomeIcon :icon="faTrash" />
              {{ deleting ? 'Removing…' : 'Remove' }}
            </button>

            <div class="leave-type-actions">
              <button class="button button--secondary" type="button" @click="$emit('close')">
                Cancel
              </button>

              <button
                class="button button--primary"
                type="button"
                :disabled="saving || deleting"
                @click="save"
              >
                {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Add leave type' }}
              </button>
            </div>
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.leave-type-modal {
  position: relative;
  z-index: 2;
  width: min(720px, calc(100vw - 32px));
  max-height: calc(100vh - 36px);
  overflow: auto;
  border: 1px solid #303030;
  background: #111;
}

.leave-type-modal > header,
.leave-type-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 22px;
}

.leave-type-modal > header { border-bottom: 1px solid #292929; }
.leave-type-modal > footer { border-top: 1px solid #292929; }

.leave-type-modal h2 {
  margin: 4px 0 0;
  color: #eee9e3;
  font-size: 22px;
}

.leave-type-modal__body {
  display: grid;
  gap: 18px;
  padding: 22px;
}

.settings-input,
.settings-colour {
  display: flex;
  min-height: 44px;
  align-items: center;
  border: 1px solid #373737;
  background: #151515;
}

.settings-input input {
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

.settings-colour {
  gap: 12px;
  padding: 6px 10px;
  color: #99928c;
  font-size: 10px;
}

.settings-colour input {
  width: 40px;
  height: 30px;
  padding: 0;
  border: 1px solid #4a4a4a;
  background: transparent;
  cursor: pointer;
}

.leave-type-protected,
.leave-type-error {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  padding: 11px 12px;
  font-size: 10px;
  line-height: 1.45;
}

.leave-type-protected {
  border: 1px solid #3d372c;
  background: #1b1812;
  color: #b9a780;
}

.leave-type-error {
  border: 1px solid rgba(239, 91, 63, 0.45);
  background: rgba(239, 91, 63, 0.08);
  color: #f3a393;
}

.leave-icon-picker {
  display: grid;
  gap: 10px;
}

.leave-icon-picker > div:first-child {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 12px;
}

.leave-icon-search {
  display: flex;
  width: 220px;
  min-height: 38px;
  align-items: center;
  gap: 8px;
  padding: 0 10px;
  border: 1px solid #343434;
  background: #151515;
  color: #6e6e6e;
}

.leave-icon-search input {
  width: 100%;
  border: 0;
  outline: 0;
  background: transparent;
  color: #ddd7d1;
  font: inherit;
  font-size: 10px;
}

.leave-icon-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 6px;
}

.leave-icon-option {
  display: grid;
  min-height: 62px;
  place-items: center;
  align-content: center;
  gap: 6px;
  border: 1px solid #303030;
  background: #151515;
  color: #88827d;
  cursor: pointer;
}

.leave-icon-option:hover {
  border-color: #4a4a4a;
  color: #d5cfc9;
}

.leave-icon-option--selected {
  border-color: #ef5b3f;
  background: #1d1614;
  color: #ef7259;
}

.leave-icon-option span {
  font-size: 8px;
}

.leave-type-rules {
  display: grid;
  gap: 7px;
}

.setting-toggle {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px;
  border: 1px solid #303030;
  background: #151515;
  cursor: pointer;
}

.setting-toggle input {
  margin-top: 2px;
  accent-color: #ef5b3f;
}

.setting-toggle > span {
  display: grid;
  gap: 3px;
}

.setting-toggle strong {
  color: #ddd7d1;
  font-size: 11px;
}

.setting-toggle small {
  color: #77716b;
  font-size: 9px;
  line-height: 1.45;
}

.setting-toggle--disabled {
  opacity: 0.55;
  cursor: default;
}

.leave-type-delete { margin-right: auto; }

.leave-type-actions {
  display: flex;
  gap: 10px;
  margin-left: auto;
}

@media (max-width: 640px) {
  .leave-icon-picker > div:first-child {
    align-items: stretch;
    flex-direction: column;
  }

  .leave-icon-search {
    width: 100%;
  }

  .leave-icon-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .leave-type-modal > footer {
    align-items: stretch;
    flex-direction: column;
  }

  .leave-type-delete {
    width: 100%;
    margin-right: 0;
  }

  .leave-type-actions {
    width: 100%;
    margin-left: 0;
  }

  .leave-type-actions .button {
    flex: 1;
  }
}

/* Patch 20 icon rail */
.leave-icon-grid{display:grid;grid-auto-flow:column;grid-auto-columns:120px;grid-template-rows:repeat(2,78px);gap:7px;overflow-x:auto;overflow-y:hidden;padding-bottom:8px}.leave-icon-option{min-height:78px}.leave-icon-colour-setting{display:grid;gap:9px}.leave-icon-colour-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.leave-icon-colour-option{display:flex;min-height:44px;align-items:center;gap:9px;padding:0 12px;border:1px solid #333;background:#151515;color:#aaa49e;cursor:pointer}.leave-icon-colour-option--selected{border-color:#ef5b3f;color:#eee9e3}.leave-icon-colour-preview{width:20px;height:20px;border:1px solid #555}.leave-icon-colour-preview--white{background:#fff}.leave-icon-colour-preview--black{background:#090909}

/* Patch 20E icon grid fix */
.leave-icon-grid {
  display: grid;
  grid-template-columns: none;
  grid-template-rows: repeat(3, 82px);
  grid-auto-flow: column;
  grid-auto-columns: 145px;
  gap: 8px;
  width: 100%;
  max-width: 100%;
  overflow-x: auto;
  overflow-y: hidden;
  padding: 0 0 10px;
  align-content: start;
  justify-content: start;
  scrollbar-width: thin;
  scrollbar-color: #3a3a3a #151515;
}

.leave-icon-grid::-webkit-scrollbar {
  height: 8px;
}

.leave-icon-grid::-webkit-scrollbar-track {
  background: #151515;
}

.leave-icon-grid::-webkit-scrollbar-thumb {
  background: #3a3a3a;
}

.leave-icon-option {
  width: 145px;
  min-width: 145px;
  height: 82px;
  min-height: 82px;
  margin: 0;
  padding: 10px 8px;
  box-sizing: border-box;
  overflow: hidden;
}

.leave-icon-option span {
  width: 100%;
  overflow: hidden;
  font-size: 10px;
  line-height: 1.2;
  text-align: center;
  text-overflow: ellipsis;
  white-space: nowrap;
}


/* Patch 20F sticky modal */
.leave-type-modal {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  width: min(720px, calc(100vw - 32px));
  height: min(760px, calc(100vh - 72px));
  max-height: calc(100vh - 72px);
  overflow: hidden;
}

.leave-type-modal > header {
  position: sticky;
  z-index: 5;
  top: 0;
  background: #111;
}

.leave-type-modal__body {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color: #3a3a3a #111;
}

.leave-type-modal__body::-webkit-scrollbar {
  width: 8px;
}

.leave-type-modal__body::-webkit-scrollbar-track {
  background: #111;
}

.leave-type-modal__body::-webkit-scrollbar-thumb {
  background: #3a3a3a;
}

.leave-type-modal > footer {
  position: sticky;
  z-index: 5;
  bottom: 0;
  background: #111;
}

@media (max-height: 760px) {
  .leave-type-modal {
    height: calc(100vh - 40px);
    max-height: calc(100vh - 40px);
  }
}

</style>
