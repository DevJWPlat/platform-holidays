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
  display: flex;
  width: min(680px, 100%);
  flex-direction: column;
  border: 1px solid #2b2b2b;
  background: #121212;
}

.not-found-card__code {
  padding: 28px 44px 18px;
  color: #ef5b3f;
  font-size: 72px;
  font-weight: 800;
  line-height: 1;
}

.not-found-card__content {
  display: flex;
  flex-direction: column;
  padding: 0px 44px 32px;
}

.not-found-card__content .eyebrow {
  margin: 0 0 8px;
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
  margin: 10px 0 0;
  color: #8b8782;
  font-size: 14px;
  line-height: 1.65;
}

.not-found-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 20px;
}

.not-found-card__home {
  text-decoration: none;
}

@media (max-width: 700px) {
  .not-found-page {
    min-height: calc(100vh - 110px);
    padding: 28px 0;
  }

  .not-found-card__code {
    padding: 22px 22px 14px;
    font-size: 54px;
  }

  .not-found-card__content {
    padding: 20px 22px 24px;
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
