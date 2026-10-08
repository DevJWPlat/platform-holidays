<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import {
  faCalendarDay,
  faCheck,
  faClock,
  faPlaneDeparture,
  faRotateRight,
  faXmark,
} from '@fortawesome/free-solid-svg-icons'
import api, { initialiseCsrf } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  requestRefreshKey: {
    type: Number,
    default: 0,
  },
})

const emit = defineEmits(['book-time-off'])

const auth = useAuthStore()
const activeTab = ref('mine')
const myRequests = ref([])
const pendingRequests = ref([])
const loading = ref(true)
const actionId = ref(null)
const error = ref('')
const rejectTarget = ref(null)
const rejectReason = ref('')

const canReview = computed(() => Boolean(auth.user?.permissions?.review_requests))

const visibleRequests = computed(() =>
  activeTab.value === 'approvals' ? pendingRequests.value : myRequests.value,
)

function prettyStatus(status) {
  return {
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
  }[status] || status
}

function formatDate(date) {
  if (!date) return '—'

  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(`${date}T12:00:00`))
}

function periodText(item) {
  if (item.starts_on === item.ends_on) {
    const suffix =
      item.start_session === 'morning' && item.end_session === 'morning'
        ? ' · Morning'
        : item.start_session === 'afternoon'
          ? ' · Afternoon'
          : ''

    return `${formatDate(item.starts_on)}${suffix}`
  }

  return `${formatDate(item.starts_on)} — ${formatDate(item.ends_on)}`
}

async function loadRequests() {
  loading.value = true
  error.value = ''

  try {
    const calls = [api.get('/api/v1/leave-requests')]

    if (canReview.value) {
      calls.push(api.get('/api/v1/leave-requests/pending'))
    }

    const [mineResponse, pendingResponse] = await Promise.all(calls)

    myRequests.value = (mineResponse.data?.data || [])
      .filter((item) => !item.is_company_closure)
    pendingRequests.value = pendingResponse?.data?.data || []
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load leave requests.'
  } finally {
    loading.value = false
  }
}

async function approve(item) {
  actionId.value = item.id
  error.value = ''

  try {
    await initialiseCsrf()
    await api.post(`/api/v1/leave-requests/${item.id}/approve`)
    await loadRequests()
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Could not approve this request.'
  } finally {
    actionId.value = null
  }
}

function openReject(item) {
  rejectTarget.value = item
  rejectReason.value = ''
}

function closeReject() {
  if (actionId.value) return
  rejectTarget.value = null
  rejectReason.value = ''
}

async function reject() {
  if (!rejectTarget.value || !rejectReason.value.trim()) return

  actionId.value = rejectTarget.value.id
  error.value = ''

  try {
    await initialiseCsrf()
    await api.post(
      `/api/v1/leave-requests/${rejectTarget.value.id}/reject`,
      { reason: rejectReason.value.trim() },
    )
    closeReject()
    await loadRequests()
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not reject this request.'
  } finally {
    actionId.value = null
  }
}

async function cancel(item) {
  if (!window.confirm('Cancel this time-off request?')) return

  actionId.value = item.id
  error.value = ''

  try {
    await initialiseCsrf()
    await api.post(`/api/v1/leave-requests/${item.id}/cancel`)
    await loadRequests()
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat()[0]
      : requestError.response?.data?.message || 'Could not cancel this request.'
  } finally {
    actionId.value = null
  }
}

watch(
  () => props.requestRefreshKey,
  () => loadRequests(),
)

onMounted(loadRequests)
</script>

<template>
  <section class="page">
    <div class="page-heading requests-heading">
      <div>
        <div class="eyebrow">TIME OFF</div>
        <h1>Requests</h1>
        <p>Track your time off and review requests that need your approval.</p>
      </div>

      <button
        class="button button--primary page-heading__action"
        type="button"
        @click="$emit('book-time-off')"
      >
        <FontAwesomeIcon :icon="faPlaneDeparture" />
        Book time off
      </button>
    </div>

    <section class="requests-panel">
      <div class="requests-toolbar">
        <div class="requests-tabs">
          <button
            class="requests-tab"
            :class="{ 'requests-tab--active': activeTab === 'mine' }"
            type="button"
            @click="activeTab = 'mine'"
          >
            My requests
            <span>{{ myRequests.length }}</span>
          </button>

          <button
            v-if="canReview"
            class="requests-tab"
            :class="{ 'requests-tab--active': activeTab === 'approvals' }"
            type="button"
            @click="activeTab = 'approvals'"
          >
            Awaiting approval
            <span>{{ pendingRequests.length }}</span>
          </button>
        </div>

        <button
          class="button button--ghost button--compact"
          type="button"
          :disabled="loading"
          @click="loadRequests"
        >
          <FontAwesomeIcon :icon="faRotateRight" />
          Refresh
        </button>
      </div>

      <div v-if="error" class="requests-error">
        {{ error }}
      </div>

      <div v-if="loading" class="requests-loading">
        Loading requests…
      </div>

      <div v-else-if="!visibleRequests.length" class="requests-empty">
        <FontAwesomeIcon :icon="faCalendarDay" />
        <strong>
          {{ activeTab === 'approvals' ? 'Nothing waiting for approval' : 'No time off requests yet' }}
        </strong>
        <span>
          {{
            activeTab === 'approvals'
              ? 'New requests that you can review will appear here.'
              : 'Your submitted requests will appear here.'
          }}
        </span>
      </div>

      <div v-else class="requests-list">
        <article
          v-for="item in visibleRequests"
          :key="item.id"
          class="request-card"
        >
          <div
            class="request-card__type"
            :style="{ '--leave-colour': item.leave_type?.colour || '#ef5b3f' }"
          />

          <div class="request-card__main">
            <div class="request-card__top">
              <div class="request-card__identity">
                <div
                  v-if="activeTab === 'approvals'"
                  class="request-card__avatar"
                >
                  <img
                    v-if="item.user?.avatar_url"
                    :src="item.user.avatar_url"
                    :alt="item.user.name"
                  />
                  <span v-else>
                    {{ item.user?.name?.split(' ').map((part) => part[0]).slice(0, 2).join('') }}
                  </span>
                </div>

                <div>
                  <strong>
                    {{
                      activeTab === 'approvals'
                        ? item.user?.name
                        : item.leave_type?.label
                    }}
                  </strong>
                  <span>
                    {{
                      activeTab === 'approvals'
                        ? item.leave_type?.label
                        : periodText(item)
                    }}
                  </span>
                </div>
              </div>

              <span
                class="request-status"
                :class="`request-status--${item.status}`"
              >
                <FontAwesomeIcon
                  :icon="item.status === 'approved' ? faCheck : item.status === 'pending' ? faClock : faXmark"
                />
                {{ prettyStatus(item.status) }}
              </span>
            </div>

            <div v-if="activeTab === 'approvals'" class="request-card__period">
              <FontAwesomeIcon :icon="faCalendarDay" />
              {{ periodText(item) }}
              <span>·</span>
              {{ item.duration_days }} {{ item.duration_days === 1 ? 'day' : 'days' }}
            </div>

            <div v-else class="request-card__duration">
              {{ item.duration_days }} {{ item.duration_days === 1 ? 'day' : 'days' }}
            </div>

            <p v-if="item.reason" class="request-card__reason">
              {{ item.reason }}
            </p>

            <p v-if="item.review_note" class="request-card__review-note">
              <strong>Review note:</strong> {{ item.review_note }}
            </p>

            <div class="request-card__footer">
              <span>
                Submitted {{ formatDate(item.created_at?.slice(0, 10)) }}
              </span>

              <div class="request-card__actions">
                <template v-if="activeTab === 'approvals'">
                  <button
                    class="button button--secondary button--compact"
                    type="button"
                    :disabled="actionId === item.id"
                    @click="openReject(item)"
                  >
                    Reject
                  </button>

                  <button
                    class="button button--primary button--compact"
                    type="button"
                    :disabled="actionId === item.id"
                    @click="approve(item)"
                  >
                    {{ actionId === item.id ? 'Working…' : 'Approve' }}
                  </button>
                </template>

                <button
                  v-else-if="item.can_cancel"
                  class="button button--secondary button--compact"
                  type="button"
                  :disabled="actionId === item.id"
                  @click="cancel(item)"
                >
                  {{ actionId === item.id ? 'Cancelling…' : 'Cancel request' }}
                </button>
              </div>
            </div>
          </div>
        </article>
      </div>
    </section>

    <Teleport to="body">
      <Transition name="modal">
        <div
          v-if="rejectTarget"
          class="modal-shell"
          role="dialog"
          aria-modal="true"
          aria-labelledby="reject-request-title"
        >
          <button
            class="modal-shell__backdrop"
            type="button"
            aria-label="Close rejection dialog"
            @click="closeReject"
          />

          <section class="request-review-modal">
            <header>
              <div>
                <div class="eyebrow">REVIEW REQUEST</div>
                <h2 id="reject-request-title">Reject request</h2>
              </div>

              <button class="icon-button" type="button" @click="closeReject">
                <FontAwesomeIcon :icon="faXmark" />
              </button>
            </header>

            <div class="request-review-modal__body">
              <p>
                Add a reason so {{ rejectTarget.user?.name || 'the employee' }} knows why this request was rejected.
              </p>

              <label class="field">
                <span class="field__label">Reason</span>
                <span class="textarea-control">
                  <textarea
                    v-model="rejectReason"
                    rows="4"
                    placeholder="Add a rejection reason..."
                  />
                </span>
              </label>
            </div>

            <footer>
              <button class="button button--secondary" type="button" @click="closeReject">
                Cancel
              </button>

              <button
                class="button button--primary"
                type="button"
                :disabled="!rejectReason.trim() || actionId === rejectTarget.id"
                @click="reject"
              >
                Reject request
              </button>
            </footer>
          </section>
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<style scoped>
.requests-heading {
  align-items: flex-end;
}

.requests-panel {
  border: 1px solid #252525;
  background: #101010;
}

.requests-toolbar {
  display: flex;
  min-height: 58px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 0 16px;
  border-bottom: 1px solid #252525;
}

.requests-tabs {
  display: flex;
  align-self: stretch;
  gap: 22px;
}

.requests-tab {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  border: 0;
  background: transparent;
  color: #747474;
  font: inherit;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
}

.requests-tab::after {
  position: absolute;
  right: 0;
  bottom: -1px;
  left: 0;
  height: 2px;
  background: transparent;
  content: '';
}

.requests-tab--active {
  color: #ece7e1;
}

.requests-tab--active::after {
  background: #ef5b3f;
}

.requests-tab span {
  display: grid;
  min-width: 19px;
  height: 19px;
  place-items: center;
  padding: 0 5px;
  border: 1px solid #303030;
  background: #181818;
  color: #8a8a8a;
  font-size: 9px;
}

.requests-error {
  margin: 16px;
  padding: 11px 12px;
  border: 1px solid rgba(239, 91, 63, 0.4);
  background: rgba(239, 91, 63, 0.08);
  color: #f4a898;
  font-size: 11px;
}

.requests-loading,
.requests-empty {
  display: grid;
  min-height: 280px;
  place-items: center;
  align-content: center;
  gap: 9px;
  padding: 30px;
  color: #696969;
  text-align: center;
}

.requests-empty > svg {
  margin-bottom: 5px;
  font-size: 22px;
}

.requests-empty strong {
  color: #d5d0ca;
  font-size: 13px;
}

.requests-empty span,
.requests-loading {
  font-size: 10px;
}

.requests-list {
  display: grid;
}

.request-card {
  position: relative;
  display: grid;
  grid-template-columns: 4px 1fr;
  border-bottom: 1px solid #222;
}

.request-card:last-child {
  border-bottom: 0;
}

.request-card__type {
  background: var(--leave-colour);
}

.request-card__main {
  display: grid;
  gap: 13px;
  padding: 18px 20px;
}

.request-card__top,
.request-card__footer,
.request-card__identity,
.request-card__actions,
.request-card__period {
  display: flex;
  align-items: center;
}

.request-card__top,
.request-card__footer {
  justify-content: space-between;
  gap: 18px;
}

.request-card__identity {
  gap: 11px;
  min-width: 0;
}

.request-card__identity > div:last-child {
  display: grid;
  min-width: 0;
  gap: 3px;
}

.request-card__identity strong {
  color: #ece7e1;
  font-size: 12px;
  font-weight: 600;
}

.request-card__identity span,
.request-card__footer > span,
.request-card__duration {
  color: #747474;
  font-size: 9px;
}

.request-card__avatar {
  display: grid;
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  overflow: hidden;
  place-items: center;
  border: 1px solid #343434;
  background: #1b1b1b;
  color: #ddd7d1;
  font-size: 9px;
  font-weight: 700;
}

.request-card__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.request-status {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 7px;
  border: 1px solid #343434;
  background: #171717;
  color: #999;
  font-size: 9px;
  font-weight: 600;
}

.request-status--pending {
  color: #e6bd67;
}

.request-status--approved {
  color: #9fd356;
}

.request-status--rejected,
.request-status--cancelled {
  color: #d08373;
}

.request-card__period {
  gap: 7px;
  color: #96918b;
  font-size: 10px;
}

.request-card__reason,
.request-card__review-note {
  max-width: 760px;
  margin: 0;
  color: #99938d;
  font-size: 10px;
  line-height: 1.55;
}

.request-card__review-note {
  padding: 9px 10px;
  border-left: 2px solid #4a403d;
  background: #151515;
}

.request-card__review-note strong {
  color: #c4beb8;
}

.request-card__actions {
  gap: 8px;
}

.request-review-modal {
  position: relative;
  z-index: 2;
  width: min(520px, calc(100vw - 32px));
  border: 1px solid #2e2e2e;
  background: #111;
  box-shadow: 0 28px 80px rgba(0, 0, 0, 0.55);
}

.request-review-modal > header,
.request-review-modal > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 20px;
}

.request-review-modal > header {
  border-bottom: 1px solid #252525;
}

.request-review-modal > footer {
  justify-content: flex-end;
  border-top: 1px solid #252525;
}

.request-review-modal h2 {
  margin: 3px 0 0;
  color: #eee9e3;
  font-size: 18px;
}

.request-review-modal__body {
  display: grid;
  gap: 18px;
  padding: 20px;
}

.request-review-modal__body > p {
  margin: 0;
  color: #8a8580;
  font-size: 11px;
  line-height: 1.6;
}

@media (max-width: 680px) {
  .requests-toolbar,
  .request-card__top,
  .request-card__footer {
    align-items: flex-start;
  }

  .requests-toolbar,
  .request-card__top,
  .request-card__footer {
    flex-direction: column;
  }

  .requests-toolbar {
    padding: 12px;
  }

  .requests-tabs {
    width: 100%;
    min-height: 40px;
  }

  .request-card__actions {
    width: 100%;
  }
}
</style>
