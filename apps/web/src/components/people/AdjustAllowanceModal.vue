<script setup>
import { computed, ref, watch } from 'vue'
import { faCircleInfo, faXmark } from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'

const props = defineProps({
  open: { type: Boolean, default: false },
  person: { type: Object, default: null },
})

const emit = defineEmits(['close', 'adjusted'])

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const summary = ref(null)
const days = ref('')
const reason = ref('')

const numericDays = computed(() => Number(days.value || 0))
const predictedRemaining = computed(() =>
  summary.value
    ? Number(summary.value.remaining_days) + numericDays.value
    : null,
)

async function load() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get(
      `/api/v1/people/${props.person.id}/allowance`,
    )

    summary.value = response.data?.data || null
  } catch (requestError) {
    error.value =
      requestError.response?.data?.message || 'Could not load allowance.'
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!numericDays.value || !reason.value.trim()) return

  saving.value = true
  error.value = ''

  try {
    await initialiseCsrf()

    const response = await api.post(
      `/api/v1/people/${props.person.id}/allowance/adjustments`,
      {
        days: numericDays.value,
        reason: reason.value.trim(),
      },
    )

    emit('adjusted', response.data?.data)
    emit('close')
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not adjust allowance.'
  } finally {
    saving.value = false
  }
}

watch(() => props.open, async (open) => {
  if (!open || !props.person?.id) return

  days.value = ''
  reason.value = ''
  summary.value = null
  error.value = ''
  await load()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="open" class="modal-shell" role="dialog" aria-modal="true">
        <button class="modal-shell__backdrop" type="button" aria-label="Close" @click="$emit('close')" />

        <section class="adjust-modal">
          <header>
            <div>
              <div class="eyebrow">ALLOWANCE</div>
              <h2>Adjust allowance</h2>
              <p>{{ person?.name }}</p>
            </div>

            <button class="icon-button" type="button" aria-label="Close" @click="$emit('close')">
              <FontAwesomeIcon :icon="faXmark" />
            </button>
          </header>

          <div class="adjust-modal__body">
            <div v-if="error" class="adjust-error">{{ error }}</div>

            <div v-if="loading" class="adjust-loading">Loading allowance…</div>

            <template v-else-if="summary">
              <div class="adjust-summary">
                <article>
                  <span>Granted</span>
                  <strong>{{ summary.granted_days }} days</strong>
                </article>
                <article>
                  <span>Used</span>
                  <strong>{{ summary.used_days }} days</strong>
                </article>
                <article>
                  <span>Remaining</span>
                  <strong>{{ summary.remaining_days }} days</strong>
                </article>
              </div>

              <div class="adjust-note">
                <FontAwesomeIcon :icon="faCircleInfo" />
                <span>
                  Use a positive or negative whole number of days. This creates a ledger entry rather than rewriting history.
                </span>
              </div>

              <label class="field">
                <span class="field__label">Adjustment</span>
                <span class="adjust-number">
                  <input v-model="days" type="number" step="1" placeholder="e.g. +2 or -1" />
                  <span>days</span>
                </span>
              </label>

              <div v-if="numericDays" class="adjust-preview">
                {{ summary.remaining_days }} → {{ predictedRemaining }} days remaining
              </div>

              <label class="field">
                <span class="field__label">Reason</span>
                <span class="adjust-textarea">
                  <textarea v-model="reason" rows="3" placeholder="Why is this adjustment being made?" />
                </span>
              </label>
            </template>
          </div>

          <footer>
            <button class="button button--secondary" type="button" @click="$emit('close')">
              Cancel
            </button>
            <button
              class="button button--primary"
              type="button"
              :disabled="saving || loading || !numericDays || !reason.trim()"
              @click="save"
            >
              {{ saving ? 'Saving…' : 'Save adjustment' }}
            </button>
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.adjust-modal {
  position: relative;
  z-index: 2;
  width: min(600px, calc(100vw - 32px));
  max-height: calc(100vh - 36px);
  overflow: auto;
  border: 1px solid #303030;
  background: #111;
}

.adjust-modal > header,
.adjust-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 22px;
}

.adjust-modal > header { border-bottom: 1px solid #292929; }
.adjust-modal > footer { justify-content: flex-end; border-top: 1px solid #292929; }

.adjust-modal h2 {
  margin: 4px 0 2px;
  color: #eee9e3;
  font-size: 21px;
}

.adjust-modal header p {
  margin: 0;
  color: #77716b;
  font-size: 10px;
}

.adjust-modal__body {
  display: grid;
  gap: 17px;
  padding: 22px;
}

.adjust-summary {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1px;
  border: 1px solid #292929;
  background: #292929;
}

.adjust-summary article {
  display: grid;
  gap: 5px;
  padding: 13px;
  background: #151515;
}

.adjust-summary span { color: #77716b; font-size: 9px; }
.adjust-summary strong { color: #ddd7d1; font-size: 13px; }

.adjust-note {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 11px;
  border: 1px solid #3a372f;
  background: #191713;
  color: #a99d85;
  font-size: 9px;
  line-height: 1.45;
}

.adjust-number,
.adjust-textarea {
  display: flex;
  border: 1px solid #373737;
  background: #151515;
}

.adjust-number {
  min-height: 44px;
  align-items: center;
}

.adjust-number input {
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

.adjust-number > span {
  padding: 0 12px;
  color: #77716b;
  font-size: 10px;
}

.adjust-textarea textarea {
  width: 100%;
  resize: vertical;
  padding: 11px 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 11px;
}

.adjust-preview {
  padding: 10px 12px;
  border: 1px solid #303030;
  background: #151515;
  color: #b7b0aa;
  font-size: 10px;
}

.adjust-error {
  padding: 11px;
  border: 1px solid rgba(239, 91, 63, 0.45);
  background: rgba(239, 91, 63, 0.08);
  color: #f3a393;
  font-size: 10px;
}

.adjust-loading {
  color: #77716b;
  font-size: 10px;
}

@media (max-width: 520px) {
  .adjust-summary { grid-template-columns: 1fr; }
}
</style>
