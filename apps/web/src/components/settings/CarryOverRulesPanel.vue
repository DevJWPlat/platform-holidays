<script setup>
import { onMounted, ref } from 'vue'
import {
  faArrowRightArrowLeft,
  faFloppyDisk,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import CustomSelect from '@/components/ui/CustomSelect.vue'

const loading = ref(true)
const savingId = ref(null)
const error = ref('')
const successId = ref(null)
const people = ref([])

const modeOptions = [
  {
    value: 'organisation',
    label: 'Use organisation rule',
  },
  {
    value: 'allow',
    label: 'Allow carry-over',
  },
  {
    value: 'block',
    label: 'Block carry-over',
  },
]

function initials(name = '') {
  return String(name)
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || '?'
}

async function load() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/api/v1/carry-over-rules')

    people.value = (response.data?.data || []).map((person) => ({
      ...person,
      maximum_days:
        person.maximum_days === null
        || person.maximum_days === undefined
          ? ''
          : String(person.maximum_days),
    }))
  } catch (requestError) {
    console.error(requestError)
    error.value =
      requestError.response?.data?.message
      || 'Could not load carry-over rules.'
  } finally {
    loading.value = false
  }
}

async function save(person) {
  savingId.value = person.id
  successId.value = null
  error.value = ''

  try {
    await initialiseCsrf()

    await api.put('/api/v1/carry-over-rules/' + person.id, {
      mode: person.mode,
      maximum_days:
        person.mode === 'allow'
          ? Number(person.maximum_days)
          : null,
    })

    successId.value = person.id

    window.setTimeout(() => {
      if (successId.value === person.id) {
        successId.value = null
      }
    }, 1800)
  } catch (requestError) {
    const errors = requestError.response?.data?.errors

    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message
        || 'Could not save carry-over rule.'
  } finally {
    savingId.value = null
  }
}

onMounted(load)
</script>

<template>
  <section class="carry-over-rules">
    <div class="carry-over-rules__heading">
      <div class="carry-over-rules__icon">
        <FontAwesomeIcon :icon="faArrowRightArrowLeft" />
      </div>

      <div>
        <div class="eyebrow">INDIVIDUAL RULES</div>
        <h3>Team carry-over</h3>
        <p>
          Everyone uses the organisation rule by default. Add an exception
          only when somebody needs a different carry-over rule.
        </p>
      </div>
    </div>

    <div v-if="error" class="carry-over-rules__error">
      {{ error }}
    </div>

    <div v-if="loading" class="carry-over-rules__empty">
      Loading team…
    </div>

    <div v-else class="carry-over-rules__list">
      <article
        v-for="person in people"
        :key="person.id"
        class="carry-over-person"
      >
        <div class="carry-over-person__identity">
          <div class="carry-over-person__avatar">
            <img
              v-if="person.avatar_url"
              :src="person.avatar_url"
              :alt="person.name"
            />
            <span v-else>{{ initials(person.name) }}</span>
          </div>

          <div>
            <strong>{{ person.name }}</strong>
            <span>{{ person.job_title || person.email }}</span>
          </div>
        </div>

        <div class="carry-over-person__controls">
          <CustomSelect
            v-model="person.mode"
            :options="modeOptions"
          />

          <label
            v-if="person.mode === 'allow'"
            class="carry-over-person__max"
          >
            <input
              v-model="person.maximum_days"
              type="number"
              min="0"
              max="365"
              step="1"
            />
            <span>days max</span>
          </label>

          <div v-else class="carry-over-person__max-placeholder" />

          <button
            class="button button--secondary button--compact"
            type="button"
            :disabled="savingId === person.id"
            @click="save(person)"
          >
            <FontAwesomeIcon :icon="faFloppyDisk" />
            {{
              savingId === person.id
                ? 'Saving…'
                : successId === person.id
                  ? 'Saved'
                  : 'Save'
            }}
          </button>
        </div>
      </article>

      <div v-if="!people.length" class="carry-over-rules__empty">
        No active team members found.
      </div>
    </div>
  </section>
</template>

<style scoped>
.carry-over-rules {
  display: grid;
  gap: 16px;
  padding: 22px 18px;
  border-top: 1px solid #292929;
}

.carry-over-rules__heading {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  gap: 12px;
  align-items: start;
}

.carry-over-rules__icon {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  background: rgba(239, 91, 63, 0.1);
  color: #ef5b3f;
}

.carry-over-rules__heading h3 {
  margin: 4px 0 5px;
  color: #eee9e3;
  font-size: 18px;
}

.carry-over-rules__heading p {
  max-width: 680px;
  margin: 0;
  color: #8c8680;
  font-size: 11px;
  line-height: 1.55;
}

.carry-over-rules__list {
  display: grid;
  border: 1px solid #2d2d2d;
}

.carry-over-person {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) minmax(420px, 1.4fr);
  min-height: 72px;
  align-items: center;
  gap: 14px;
  padding: 12px;
  border-bottom: 1px solid #292929;
}

.carry-over-person:last-child {
  border-bottom: 0;
}

.carry-over-person__identity {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 10px;
}

.carry-over-person__avatar {
  display: grid;
  width: 36px;
  height: 36px;
  flex: 0 0 36px;
  overflow: hidden;
  place-items: center;
  border: 1px solid #343434;
  background: #1a1a1a;
  color: #ddd7d1;
  font-size: 9px;
  font-weight: 700;
}

.carry-over-person__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.carry-over-person__identity > div:last-child {
  display: grid;
  min-width: 0;
  gap: 3px;
}

.carry-over-person__identity strong {
  overflow: hidden;
  color: #ddd7d1;
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.carry-over-person__identity span {
  overflow: hidden;
  color: #736e68;
  font-size: 9px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.carry-over-person__controls {
  display: grid;
  grid-template-columns: minmax(180px, 1fr) 120px auto;
  gap: 8px;
  align-items: center;
}

.carry-over-person__max {
  display: flex;
  height: 42px;
  align-items: center;
  border: 1px solid #373737;
  background: #151515;
}

.carry-over-person__max input {
  min-width: 0;
  width: 58px;
  height: 40px;
  padding: 0 8px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 11px;
}

.carry-over-person__max span {
  color: #77716b;
  font-size: 9px;
  white-space: nowrap;
}

.carry-over-rules__empty,
.carry-over-rules__error {
  padding: 13px;
  border: 1px solid #303030;
  background: #151515;
  color: #8c8680;
  font-size: 10px;
}

.carry-over-rules__error {
  border-color: rgba(239, 91, 63, 0.42);
  color: #f2a494;
}

@media (max-width: 760px) {
  .carry-over-person {
    grid-template-columns: 1fr;
  }

  .carry-over-person__controls {
    grid-template-columns: 1fr;
  }

  .carry-over-person__max {
    width: 100%;
  }

  .carry-over-person__max-placeholder {
    display: none;
  }

  .carry-over-person__controls .button {
    width: 100%;
    justify-content: center;
  }
}
</style>
