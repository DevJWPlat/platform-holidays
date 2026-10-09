<script setup>
import { faArrowLeft, faHouse } from '@fortawesome/free-solid-svg-icons'
import { useRouter } from 'vue-router'

const router = useRouter()

function goBack() {
  if (window.history.length > 1) {
    router.back()
    return
  }

  router.push('/')
}
</script>

<template>
  <section class="not-found-page">
    <div class="not-found-card">
      <div class="not-found-card__code">404</div>

      <div class="not-found-card__content">
        <p class="eyebrow">PLATFORM HOLIDAYS</p>
        <h1>Page not found</h1>
        <p>
          The page you’re looking for doesn’t exist or may have moved.
        </p>

        <div class="not-found-card__actions">
          <button
            type="button"
            class="button button--ghost"
            @click="goBack"
          >
            <FontAwesomeIcon :icon="faArrowLeft" />
            Go back
          </button>

          <RouterLink
            to="/"
            class="button button--primary not-found-card__home"
          >
            <FontAwesomeIcon :icon="faHouse" />
            Back to wallchart
          </RouterLink>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.not-found-page {
  display: grid;
  min-height: calc(100vh - 150px);
  place-items: center;
  padding: 48px 0;
}

.not-found-card {
  display: grid;
  width: min(720px, 100%);
  grid-template-columns: 180px minmax(0, 1fr);
  border: 1px solid #2b2b2b;
  background: #121212;
}

.not-found-card__code {
  display: grid;
  min-height: 300px;
  place-items: center;
  border-right: 1px solid #2b2b2b;
  color: #ef5b3f;
  font-size: 72px;
  font-weight: 800;
  line-height: 1;
}

.not-found-card__content {
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 44px;
}

.not-found-card__content .eyebrow {
  margin: 0 0 12px;
}

.not-found-card__content h1 {
  margin: 0;
  color: #f5f3ef;
  font-size: clamp(34px, 5vw, 52px);
  font-weight: 500;
  line-height: 1;
}

.not-found-card__content > p:not(.eyebrow) {
  max-width: 430px;
  margin: 18px 0 0;
  color: #8b8782;
  font-size: 14px;
  line-height: 1.65;
}

.not-found-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 30px;
}

.not-found-card__home {
  text-decoration: none;
}

@media (max-width: 700px) {
  .not-found-page {
    min-height: calc(100vh - 110px);
    padding: 28px 0;
  }

  .not-found-card {
    grid-template-columns: 1fr;
  }

  .not-found-card__code {
    min-height: 130px;
    border-right: 0;
    border-bottom: 1px solid #2b2b2b;
    font-size: 54px;
  }

  .not-found-card__content {
    padding: 28px 22px;
  }

  .not-found-card__actions {
    align-items: stretch;
    flex-direction: column;
  }

  .not-found-card__actions .button {
    justify-content: center;
    width: 100%;
  }
}
</style>
