<script setup>
import { onMounted, ref } from 'vue'
import {
  faCalendarDays,
  faSnowflake,
  faTrash,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import CustomDatePicker from '@/components/ui/CustomDatePicker.vue'

const loading = ref(true)
const saving = ref(false)
const deleting = ref(false)
const closureToDelete = ref(null)
const error = ref('')
const closures = ref([])
const startsOn = ref('')
const endsOn = ref('')

function formatDate(value) {
  if (!value) return '—'

  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date(String(value) + 'T12:00:00'))
}

async function load() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/api/v1/company-closures')
    closures.value = response.data?.data || []
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load company closures.'
  } finally {
    loading.value = false
  }
}

async function savePendingClosure() {
  if (!startsOn.value && !endsOn.value) {
    return true
  }

  if (!startsOn.value || !endsOn.value) {
    error.value = 'Choose both a start and end date for Festive Break.'
    return false
  }

  saving.value = true
  error.value = ''

  try {
    await initialiseCsrf()

    await api.post('/api/v1/company-closures', {
      starts_on: startsOn.value,
      ends_on: endsOn.value,
    })

    startsOn.value = ''
    endsOn.value = ''
    await load()

    return true
  } catch (requestError) {
    const errors = requestError.response?.data?.errors

    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message
        || 'Could not add Festive Break.'
    return false
  } finally {
    saving.value = false
  }
}

defineExpose({
  savePendingClosure,
  reload: load,
})

function requestRemove(item) {
  closureToDelete.value = item
}

function cancelRemove() {
  if (deleting.value) return
  closureToDelete.value = null
}

async function confirmRemove() {
  if (!closureToDelete.value) return

  deleting.value = true
  error.value = ''

  try {
    await initialiseCsrf()
    await api.delete(
      '/api/v1/company-closures/' + closureToDelete.value.id,
    )

    closureToDelete.value = null
    await load()
  } catch (requestError) {
    error.value =
      requestError.response?.data?.message
      || 'Could not remove Festive Break.'
  } finally {
    deleting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="company-closures">
    <div class="company-closures__intro">
      <div class="company-closures__icon">
        <FontAwesomeIcon :icon="faSnowflake" />
      </div>

      <div>
        <div class="eyebrow">COMPANY CLOSURES</div>
        <h3>Festive Break</h3>
        <p>
          Add organisation-wide closure dates. These appear automatically
          for everyone and do not deduct holiday allowance.
        </p>
      </div>
    </div>

    <div v-if="error" class="company-closures__error">
      {{ error }}
    </div>

    <div class="company-closures__form">
      <label>
        <span>Starting</span>
        <CustomDatePicker
          v-model="startsOn"
          placeholder="Choose start date"
          placement="above"
        />
      </label>

      <label>
        <span>Ending</span>
        <CustomDatePicker
          v-model="endsOn"
          placeholder="Choose end date"
          placement="above"
          :min-date="startsOn || undefined"
        />
      </label>
    </div>

    <div class="company-closures__save-hint">
      Festive Break dates are saved with the main
      <strong>Save settings</strong> button above.
    </div>

    <div v-if="loading" class="company-closures__empty">
      Loading closures…
    </div>

    <div v-else-if="!closures.length" class="company-closures__empty">
      No company closures have been added yet.
    </div>

    <div v-else class="company-closures__list">
      <article
        v-for="item in closures"
        :key="item.id"
        class="company-closure-row"
      >
        <span class="company-closure-row__icon">
          <FontAwesomeIcon :icon="faCalendarDays" />
        </span>

        <div>
          <strong>Festive Break</strong>
          <span>
            {{ formatDate(item.starts_on) }}
            —
            {{ formatDate(item.ends_on) }}
          </span>
        </div>

        <button
          class="icon-button"
          type="button"
          aria-label="Remove Festive Break"
          @click="requestRemove(item)"
        >
          <FontAwesomeIcon :icon="faTrash" />
        </button>
      </article>
    </div>


    <Teleport to="body">
      <div
        v-if="closureToDelete"
        class="closure-confirm"
        @click.self="cancelRemove"
      >
        <section
          class="closure-confirm__dialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby="closure-confirm-title"
        >
          <div class="closure-confirm__icon">
            <FontAwesomeIcon :icon="faSnowflake" />
          </div>

          <div class="closure-confirm__copy">
            <div class="eyebrow">REMOVE CLOSURE</div>
            <h3 id="closure-confirm-title">
              Remove Festive Break?
            </h3>
            <p>
              This will remove the Festive Break from everyone's
              calendar and wallchart.
            </p>

            <div class="closure-confirm__dates">
              {{ formatDate(closureToDelete.starts_on) }}
              —
              {{ formatDate(closureToDelete.ends_on) }}
            </div>
          </div>

          <div class="closure-confirm__actions">
            <button
              class="button"
              type="button"
              :disabled="deleting"
              @click="cancelRemove"
            >
              Cancel
            </button>

            <button
              class="button button--danger"
              type="button"
              :disabled="deleting"
              @click="confirmRemove"
            >
              <FontAwesomeIcon :icon="faTrash" />
              {{ deleting ? 'Removing…' : 'Remove Festive Break' }}
            </button>
          </div>
        </section>
      </div>
    </Teleport>

    <div class="company-closures__note">
      New starters are included automatically. Employees cannot book another
      leave type over company closure dates.
    </div>
  </section>
</template>

<style scoped>
.company-closures {
  display: grid;
  gap: 16px;
  padding: 22px 18px;
  border-top: 1px solid #292929;
}

.company-closures__intro {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  gap: 12px;
}

.company-closures__icon {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  background: rgba(239, 91, 63, 0.1);
  color: #ef5b3f;
}

.company-closures__intro h3 {
  margin: 4px 0 5px;
  color: #eee9e3;
  font-size: 18px;
}

.company-closures__intro p {
  margin: 0;
  max-width: 620px;
  color: #8c8680;
  font-size: 12px;
  line-height: 1.5;
}

.company-closures__form {
  display: grid;
  grid-template-columns:
    minmax(180px, 1fr)
    minmax(180px, 1fr);
  gap: 10px;
  align-items: end;
}

.company-closures__form label {
  display: grid;
  gap: 7px;
}

.company-closures__form label > span {
  color: #aaa39d;
  font-size: 12px;
  font-weight: 600;
}



.company-closures__save-hint {
  padding: 10px 12px;
  border: 1px dashed #343434;
  color: #817b75;
  font-size: 10px;
  line-height: 1.45;
}

.company-closures__save-hint strong {
  color: #d8d2cc;
  font-weight: 600;
}

.company-closures__list {
  display: grid;
  border: 1px solid #2d2d2d;
}

.company-closure-row {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr) 38px;
  min-height: 62px;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-bottom: 1px solid #292929;
}

.company-closure-row:last-child {
  border-bottom: 0;
}

.company-closure-row__icon {
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  background: #ef5b3f;
  color: #fff;
}

.company-closure-row > div {
  display: grid;
  gap: 3px;
}

.company-closure-row strong {
  color: #ddd7d1;
  font-size: 12px;
}

.company-closure-row div span {
  color: #817b75;
  font-size: 10px;
}

.company-closures__empty,
.company-closures__note,
.company-closures__error {
  padding: 12px;
  border: 1px solid #303030;
  background: #151515;
  color: #8c8680;
  font-size: 10px;
  line-height: 1.5;
}

.company-closures__error {
  border-color: rgba(239, 91, 63, 0.42);
  color: #f2a494;
}

.company-closures__note {
  background: #191713;
  color: #aaa08a;
}

@media (max-width: 720px) {
  .company-closures__form {
    grid-template-columns: 1fr;
  }

  .company-closures__form .button {
    width: 100%;
  }
}

/* Patch 27H custom confirm */
.closure-confirm {
  position: fixed;
  z-index: 2000;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(0, 0, 0, 0.72);
}

.closure-confirm__dialog {
  width: min(100%, 460px);
  border: 1px solid #343434;
  background: #111;
  box-shadow: 0 26px 80px rgba(0, 0, 0, 0.55);
}

.closure-confirm__icon {
  display: grid;
  width: 46px;
  height: 46px;
  margin: 22px 22px 0;
  place-items: center;
  background: rgba(239, 91, 63, 0.12);
  color: #ef5b3f;
  font-size: 18px;
}

.closure-confirm__copy {
  padding: 18px 22px 22px;
}

.closure-confirm__copy h3 {
  margin: 5px 0 8px;
  color: #f1ece6;
  font-size: 22px;
}

.closure-confirm__copy p {
  margin: 0;
  color: #918a84;
  font-size: 12px;
  line-height: 1.55;
}

.closure-confirm__dates {
  margin-top: 16px;
  padding: 12px 14px;
  border: 1px solid #303030;
  background: #171717;
  color: #d6d0ca;
  font-size: 12px;
  font-weight: 600;
}

.closure-confirm__actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 22px;
  border-top: 1px solid #292929;
}

.closure-confirm__actions .button {
  min-width: 110px;
}

.button--danger {
  border-color: #ef5b3f;
  background: #ef5b3f;
  color: #090909;
}

.button--danger:hover:not(:disabled) {
  filter: brightness(1.06);
}

@media (max-width: 560px) {
  .closure-confirm {
    align-items: end;
    padding: 12px;
  }

  .closure-confirm__dialog {
    width: 100%;
  }

  .closure-confirm__actions {
    flex-direction: column-reverse;
  }

  .closure-confirm__actions .button {
    width: 100%;
  }
}

</style>
