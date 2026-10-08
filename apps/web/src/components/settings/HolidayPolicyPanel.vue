<script setup>
import CarryOverRulesPanel from '@/components/settings/CarryOverRulesPanel.vue'
import { onMounted, ref } from 'vue'
import {
  faCircleInfo,
  faFloppyDisk,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const success = ref('')

const form = ref({
  base_allowance_days: 25,
  service_increment_enabled: true,
  service_increment_days: 1,
  service_increment_after_years: 1,
  maximum_allowance_days: '',
  carry_over_enabled: true,
  maximum_carry_over_days: '',
})

async function load() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/api/v1/holiday-policy')
    const data = response.data?.data || {}

    form.value = {
      base_allowance_days: data.base_allowance_days ?? 25,
      service_increment_enabled: data.service_increment_enabled !== false,
      service_increment_days: data.service_increment_days ?? 1,
      service_increment_after_years: data.service_increment_after_years ?? 1,
      maximum_allowance_days: data.maximum_allowance_days ?? '',
      carry_over_enabled: data.carry_over_enabled !== false,
      maximum_carry_over_days: data.maximum_carry_over_days ?? '',
    }
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load the holiday policy.'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  success.value = ''

  try {
    await initialiseCsrf()

    await api.put('/api/v1/holiday-policy', {
      base_allowance_days: Number(form.value.base_allowance_days),
      service_increment_enabled: Boolean(form.value.service_increment_enabled),
      service_increment_days: Number(form.value.service_increment_days || 0),
      service_increment_after_years: Number(form.value.service_increment_after_years || 0),
      maximum_allowance_days:
        form.value.maximum_allowance_days === ''
          ? null
          : Number(form.value.maximum_allowance_days),
      carry_over_enabled: Boolean(form.value.carry_over_enabled),
      maximum_carry_over_days:
        form.value.maximum_carry_over_days === ''
          ? null
          : Number(form.value.maximum_carry_over_days),
    })

    success.value = 'Holiday policy saved.'
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not save holiday policy.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="policy-panel">
    <header class="policy-panel__heading">
      <div>
        <div class="eyebrow">HOLIDAY POLICY</div>
        <h2>Holiday allowance</h2>
        <p>
          Set the organisation default. Individual people can override this from their profile.
        </p>
      </div>

      <button class="button button--primary" type="button" :disabled="loading || saving" @click="save">
        <FontAwesomeIcon :icon="faFloppyDisk" />
        {{ saving ? 'Saving…' : 'Save policy' }}
      </button>
    </header>

    <div v-if="error" class="policy-alert policy-alert--error">{{ error }}</div>
    <div v-if="success" class="policy-alert policy-alert--success">{{ success }}</div>

    <div v-if="loading" class="policy-loading">Loading policy…</div>

    <div v-else class="policy-body">
      <section class="policy-section">
        <div class="policy-section__intro">
          <h3>Default allowance</h3>
          <p>
            This is used for everyone who does not have a custom allowance set on their person record. Whole days only.
          </p>
        </div>

        <label class="policy-field">
          <span>Default annual allowance</span>
          <div class="policy-number">
            <input v-model="form.base_allowance_days" type="number" min="0" max="365" step="1" />
            <span>days</span>
          </div>
        </label>
      </section>

      <section class="policy-section">
        <div class="policy-section__intro">
          <h3>Service increase</h3>
          <p>Add extra holiday once someone reaches a service threshold.</p>
        </div>

        <label class="policy-toggle">
          <input v-model="form.service_increment_enabled" type="checkbox" />
          <span>
            <strong>Enable service increase</strong>
            <small>Automatically adds an extra allowance grant when eligible.</small>
          </span>
        </label>

        <div v-if="form.service_increment_enabled" class="policy-grid">
          <label class="policy-field">
            <span>Extra allowance</span>
            <div class="policy-number">
              <input v-model="form.service_increment_days" type="number" min="0" max="365" step="1" />
              <span>days</span>
            </div>
          </label>

          <label class="policy-field">
            <span>After</span>
            <div class="policy-number">
              <input v-model="form.service_increment_after_years" type="number" min="0" max="100" step="1" />
              <span>years</span>
            </div>
          </label>
        </div>

        <label class="policy-field">
          <span>Maximum annual entitlement <small>Optional</small></span>
          <div class="policy-number">
            <input
              v-model="form.maximum_allowance_days"
              type="number"
              min="0"
              max="365"
              step="1"
              placeholder="No maximum"
            />
            <span>days</span>
          </div>
        </label>
      </section>

      <section class="policy-section">
        <div class="policy-section__intro">
          <h3>Carry-over</h3>
          <p>Choose whether unused holiday can move into the next leave year.</p>
        </div>

        <label class="policy-toggle">
          <input v-model="form.carry_over_enabled" type="checkbox" />
          <span>
            <strong>Allow carry-over</strong>
            <small>Unused remaining allowance can be brought into the following leave year.</small>
          </span>
        </label>

        <label v-if="form.carry_over_enabled" class="policy-field">
          <span>Maximum carry-over <small>Optional</small></span>
          <div class="policy-number">
            <input
              v-model="form.maximum_carry_over_days"
              type="number"
              min="0"
              max="365"
              step="1"
              placeholder="No maximum"
            />
            <span>days</span>
          </div>
        </label>
      </section>

      <div class="policy-note">
        <FontAwesomeIcon :icon="faCircleInfo" />
        <span>
          Changing the default recalculates system base-grant entries. Existing bookings and manual adjustments remain in the ledger.
        </span>
      </div>
    </div>
  </div>

  <CarryOverRulesPanel />
</template>

<style scoped>
.policy-panel { min-width: 0; }

.policy-panel__heading {
  display: flex;
  min-height: 92px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 16px 18px;
  border-bottom: 1px solid #292929;
}

.policy-panel__heading h2 {
  margin: 3px 0 2px;
  color: #eee9e3;
  font-size: 20px;
}

.policy-panel__heading p {
  margin: 0;
  color: #77716b;
  font-size: 10px;
}

.policy-body {
  display: grid;
}

.policy-section {
  display: grid;
  gap: 15px;
  padding: 20px 18px;
  border-bottom: 1px solid #292929;
}

.policy-section__intro h3 {
  margin: 0 0 4px;
  color: #ddd7d1;
  font-size: 14px;
}

.policy-section__intro p {
  margin: 0;
  color: #77716b;
  font-size: 10px;
}

.policy-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.policy-field {
  display: grid;
  gap: 7px;
}

.policy-field > span {
  color: #89837e;
  font-size: 10px;
  font-weight: 600;
}

.policy-field > span small {
  color: #666;
  font-size: 8px;
  font-weight: 400;
}

.policy-number {
  display: flex;
  min-height: 44px;
  align-items: center;
  border: 1px solid #373737;
  background: #151515;
}

.policy-number input {
  min-width: 0;
  flex: 1;
  height: 42px;
  padding: 0 12px;
  border: 0;
  outline: 0;
  background: transparent;
  color: #eee9e3;
  font: inherit;
  font-size: 12px;
}

.policy-number > span {
  padding: 0 12px;
  color: #77716b;
  font-size: 10px;
}

.policy-toggle {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px;
  border: 1px solid #303030;
  background: #151515;
  cursor: pointer;
}

.policy-toggle input {
  margin-top: 2px;
  accent-color: #ef5b3f;
}

.policy-toggle > span {
  display: grid;
  gap: 3px;
}

.policy-toggle strong {
  color: #ddd7d1;
  font-size: 11px;
}

.policy-toggle small {
  color: #77716b;
  font-size: 9px;
}

.policy-note {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  margin: 18px;
  padding: 11px 12px;
  border: 1px solid #3c372e;
  background: #191713;
  color: #a99d85;
  font-size: 9px;
  line-height: 1.45;
}

.policy-alert {
  margin: 16px 18px 0;
  padding: 11px 12px;
  font-size: 10px;
}

.policy-alert--error {
  border: 1px solid rgba(239, 91, 63, 0.45);
  background: rgba(239, 91, 63, 0.08);
  color: #f3a393;
}

.policy-alert--success {
  border: 1px solid rgba(159, 211, 86, 0.38);
  background: rgba(159, 211, 86, 0.06);
  color: #c9eaa1;
}

.policy-loading {
  padding: 24px 18px;
  color: #77716b;
  font-size: 10px;
}

@media (max-width: 640px) {
  .policy-panel__heading {
    align-items: flex-start;
    flex-direction: column;
  }

  .policy-grid {
    grid-template-columns: 1fr;
  }
}

/* Patch 19A typography */
.policy-panel__heading h2 { font-size: 24px; }
.policy-panel__heading p { font-size: 12px; line-height: 1.5; }
.policy-section__intro h3 { font-size: 18px; }
.policy-section__intro p { font-size: 12px; line-height: 1.5; }
.policy-field > span { font-size: 12px; }
.policy-field > span small { font-size: 10px; }
.policy-number input { font-size: 14px; }
.policy-number > span { font-size: 12px; }
.policy-toggle strong { font-size: 13px; }
.policy-toggle small { font-size: 11px; line-height: 1.45; }
.policy-note { font-size: 11px; line-height: 1.5; }

/* Patch 30 info banner alignment */
.policy-note,
.organisation-note,
.notifications-note,
.notification-note,
.settings-note,
.info-banner {
  align-items: center !important;
}

.policy-note > svg,
.organisation-note > svg,
.notifications-note > svg,
.notification-note > svg,
.settings-note > svg,
.info-banner > svg {
  align-self: center !important;
  margin-top: 0 !important;
  flex: 0 0 auto;
}

</style>
