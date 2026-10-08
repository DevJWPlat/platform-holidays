<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  faBell,
  faBuilding,
  faCalendarDays,
  faChevronRight,
  faGear,
  faPlus,
  faShieldHalved,
} from '@fortawesome/free-solid-svg-icons'
import api from '@/api/client'
import LeaveTypeModal from '@/components/settings/LeaveTypeModal.vue'
import HolidayPolicyPanel from '@/components/settings/HolidayPolicyPanel.vue'
import NotificationsPanel from '@/components/settings/NotificationsPanel.vue'
import OrganisationPanel from '@/components/settings/OrganisationPanel.vue'

const route = useRoute()
const router = useRouter()

const leaveTypes = ref([])
const loading = ref(true)
const error = ref('')
const modalOpen = ref(false)
const editingLeaveType = ref(null)

const sections = [
  {
    key: 'leave-types',
    label: 'Leave types',
    description: 'Holiday, sickness, training and other absence types.',
    icon: faCalendarDays,
  },
  {
    key: 'holiday-policy',
    label: 'Holiday policy',
    description: 'Allowance, carry-over and service increases.',
    icon: faShieldHalved,
  },
  {
    key: 'notifications',
    label: 'Notifications',
    description: 'Birthday, anniversary and leave reminders.',
    icon: faBell,
  },
  {
    key: 'organisation',
    label: 'Organisation',
    description: 'Leave year, timezone and bank holidays.',
    icon: faBuilding,
  },
]

const activeSection = computed(() => {
  const requested = String(route.params.section || 'leave-types')
  return sections.some((section) => section.key === requested)
    ? requested
    : 'leave-types'
})

const activeSectionMeta = computed(() =>
  sections.find((section) => section.key === activeSection.value),
)

async function loadLeaveTypes() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/api/v1/leave-types')
    leaveTypes.value = response.data?.data || []
  } catch (requestError) {
    console.error(requestError)
    error.value = 'Could not load leave types.'
  } finally {
    loading.value = false
  }
}

function selectSection(key) {
  router.push({
    name: 'settings',
    params: { section: key },
  })
}

function openAddLeaveType() {
  editingLeaveType.value = null
  modalOpen.value = true
}

function openEditLeaveType(type) {
  editingLeaveType.value = type
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
  editingLeaveType.value = null
}

async function onChanged() {
  closeModal()
  await loadLeaveTypes()
}

onMounted(loadLeaveTypes)
</script>

<template>
  <section class="page settings-page">
    <div class="page-heading">
      <div>
        <div class="eyebrow">ADMINISTRATION</div>
        <h1>Settings</h1>
        <p>Configure how Platform Holidays behaves for the team.</p>
      </div>
    </div>

    <div class="settings-layout">
      <aside class="settings-nav">
        <button
          v-for="section in sections"
          :key="section.key"
          class="settings-nav__item"
          :class="{ 'settings-nav__item--active': activeSection === section.key }"
          type="button"
          @click="selectSection(section.key)"
        >
          <FontAwesomeIcon :icon="section.icon" />

          <span>
            <strong>{{ section.label }}</strong>
            <small>{{ section.description }}</small>
          </span>

          <FontAwesomeIcon class="settings-nav__arrow" :icon="faChevronRight" />
        </button>
      </aside>

      <main class="settings-content">
        <template v-if="activeSection === 'leave-types'">
          <header class="settings-content__heading">
            <div>
              <div class="eyebrow">LEAVE TYPES</div>
              <h2>Leave types</h2>
              <p>
                Control approval, staffing rules and whether each type can be booked as a half day.
              </p>
            </div>

            <button class="button button--primary" type="button" @click="openAddLeaveType">
              <FontAwesomeIcon :icon="faPlus" />
              Add leave type
            </button>
          </header>

          <div v-if="error" class="settings-error">{{ error }}</div>

          <div v-if="loading" class="settings-empty">Loading leave types…</div>

          <div v-else class="leave-type-list">
            <button
              v-for="type in leaveTypes"
              :key="type.id"
              class="leave-type-row"
              type="button"
              @click="openEditLeaveType(type)"
            >
              <span
                class="leave-type-row__colour"
                :style="{ backgroundColor: type.colour || '#777' }"
              />

              <div class="leave-type-row__name">
                <strong>{{ type.label }}</strong>
                <span>
                  {{ type.is_protected_holiday ? 'Protected system type' : type.key }}
                </span>
              </div>

              <div class="leave-type-row__rule">
                <small>Approval</small>
                <strong>{{ type.requires_approval ? 'Required' : 'Automatic' }}</strong>
              </div>

              <div class="leave-type-row__rule">
                <small>Half days</small>
                <strong>{{ type.allow_half_days ? 'Allowed' : 'Full days only' }}</strong>
              </div>

              <div class="leave-type-row__rule">
                <small>Staffing</small>
                <strong>{{ type.include_in_staffing_limits ? 'Counts' : 'Ignored' }}</strong>
              </div>

              <FontAwesomeIcon class="leave-type-row__arrow" :icon="faChevronRight" />
            </button>

            <div v-if="!leaveTypes.length" class="settings-empty">
              No leave types configured.
            </div>
          </div>
        </template>

        <template v-else-if="activeSection === 'holiday-policy'">
          <HolidayPolicyPanel />
        </template>

        <template v-else-if="activeSection === 'notifications'">
          <NotificationsPanel />
        </template>

        <template v-else-if="activeSection === 'organisation'">
          <OrganisationPanel />
        </template>

        <template v-else>
          <header class="settings-content__heading">
            <div>
              <div class="eyebrow">{{ activeSectionMeta.label.toUpperCase() }}</div>
              <h2>{{ activeSectionMeta.label }}</h2>
              <p>{{ activeSectionMeta.description }}</p>
            </div>
          </header>

          <div class="settings-coming-soon">
            <FontAwesomeIcon :icon="faGear" />
            <strong>{{ activeSectionMeta.label }} is next</strong>
            <span>
              This section is now part of the Settings structure and will be wired to its backend policy in the next settings patch.
            </span>
          </div>
        </template>
      </main>
    </div>

    <LeaveTypeModal
      :open="modalOpen"
      :leave-type="editingLeaveType"
      @close="closeModal"
      @saved="onChanged"
      @deleted="onChanged"
    />
  </section>
</template>

<style scoped>
.settings-layout {
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  gap: 18px;
  align-items: start;
}

.settings-nav,
.settings-content {
  border: 1px solid #292929;
  background: #101010;
}

.settings-nav {
  display: grid;
}

.settings-nav__item {
  display: grid;
  grid-template-columns: 28px minmax(0, 1fr) 14px;
  min-height: 78px;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border: 0;
  border-bottom: 1px solid #262626;
  background: transparent;
  color: #777;
  text-align: left;
  cursor: pointer;
}

.settings-nav__item:last-child {
  border-bottom: 0;
}

.settings-nav__item:hover {
  background: #151515;
}

.settings-nav__item--active {
  box-shadow: inset 3px 0 0 #ef5b3f;
  background: #161413;
}

.settings-nav__item > svg:first-child {
  font-size: 14px;
}

.settings-nav__item > span {
  display: grid;
  gap: 4px;
}

.settings-nav__item strong {
  color: #ddd7d1;
  font-size: 12px;
}

.settings-nav__item small {
  color: #706b66;
  font-size: 9px;
  line-height: 1.4;
}

.settings-nav__arrow {
  justify-self: end;
  font-size: 9px;
}

.settings-content {
  min-width: 0;
}

.settings-content__heading {
  display: flex;
  min-height: 92px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 16px 18px;
  border-bottom: 1px solid #292929;
}

.settings-content__heading h2 {
  margin: 3px 0 2px;
  color: #eee9e3;
  font-size: 20px;
}

.settings-content__heading p {
  margin: 0;
  color: #77716b;
  font-size: 10px;
}

.leave-type-list {
  display: grid;
}

.leave-type-row {
  display: grid;
  grid-template-columns: 5px minmax(170px, 1fr) 130px 130px 130px 16px;
  min-height: 78px;
  align-items: center;
  gap: 14px;
  padding-right: 16px;
  border: 0;
  border-bottom: 1px solid #252525;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.leave-type-row:last-child {
  border-bottom: 0;
}

.leave-type-row:hover {
  background: #151515;
}

.leave-type-row__colour {
  align-self: stretch;
}

.leave-type-row__name,
.leave-type-row__rule {
  display: grid;
  gap: 4px;
}

.leave-type-row__name strong,
.leave-type-row__rule strong {
  color: #ddd7d1;
  font-size: 12px;
}

.leave-type-row__name span,
.leave-type-row__rule small {
  color: #706b66;
  font-size: 9px;
}

.leave-type-row__arrow {
  color: #666;
  font-size: 9px;
}

.settings-error {
  margin: 16px;
  padding: 11px 12px;
  border: 1px solid rgba(239, 91, 63, 0.45);
  background: rgba(239, 91, 63, 0.08);
  color: #f3a393;
  font-size: 10px;
}

.settings-empty,
.settings-coming-soon {
  display: grid;
  min-height: 260px;
  place-items: center;
  align-content: center;
  gap: 8px;
  padding: 24px;
  color: #706b66;
  text-align: center;
}

.settings-coming-soon svg {
  font-size: 22px;
}

.settings-coming-soon strong {
  color: #d7d1cb;
  font-size: 13px;
}

.settings-coming-soon span,
.settings-empty {
  font-size: 10px;
}

@media (max-width: 980px) {
  .settings-layout {
    grid-template-columns: 1fr;
  }

  .settings-nav {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .settings-nav__item {
    border-right: 1px solid #262626;
  }

  .leave-type-row {
    grid-template-columns: 5px minmax(150px, 1fr) 100px 100px 16px;
  }

  .leave-type-row__rule:nth-of-type(4) {
    display: none;
  }
}

@media (max-width: 640px) {
  .settings-nav {
    grid-template-columns: 1fr;
  }

  .settings-content__heading {
    align-items: flex-start;
    flex-direction: column;
  }

  .leave-type-row {
    grid-template-columns: 5px minmax(0, 1fr) 16px;
    gap: 10px;
    padding: 12px 12px 12px 0;
  }

  .leave-type-row__rule {
    grid-column: 2;
  }

  .leave-type-row__arrow {
    grid-column: 3;
    grid-row: 1 / span 4;
  }
}

/* Patch 19B sidebar readability */
.settings-nav__item small {
  color: #8a847e;
  font-size: 11px;
  line-height: 1.5;
}

</style>
