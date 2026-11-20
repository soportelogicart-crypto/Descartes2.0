<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { novedades, type IdiomaNovedad } from '@/novedades/novedades'

const { t, locale } = useI18n()

const idioma = computed<IdiomaNovedad>(() =>
  locale.value === 'ca' || locale.value === 'en' ? locale.value : 'es'
)

const lista = computed(() =>
  [...novedades].sort((a, b) => (a.fecha < b.fecha ? 1 : a.fecha > b.fecha ? -1 : 0))
)

function fechaLegible(iso: string): string {
  const [y, m, d] = iso.split('-').map((n) => Number(n))
  const fecha = new Date(y, (m || 1) - 1, d || 1)
  const loc = idioma.value === 'en' ? 'en-GB' : idioma.value === 'ca' ? 'ca-ES' : 'es-ES'
  return fecha.toLocaleDateString(loc, { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <section class="novedades">
    <h2>{{ t('novedades.titulo') }}</h2>
    <p class="intro">{{ t('novedades.intro') }}</p>
    <article v-for="(item, i) in lista" :key="`${item.fecha}-${i}`" class="entrada">
      <time :datetime="item.fecha">{{ fechaLegible(item.fecha) }}</time>
      <h3>{{ item.titulo[idioma] }}</h3>
      <p>{{ item.texto[idioma] }}</p>
    </article>
  </section>
</template>

<style scoped>
.novedades {
  max-width: 42rem;
}

h2 {
  margin: 0 0 0.35rem;
}

.intro {
  margin: 0 0 1rem;
  color: #475569;
  font-size: 0.9rem;
}

.entrada {
  margin: 0 0 0.75rem;
  padding: 0.75rem 0.9rem;
  background: #fff;
  border: 1px solid #d5dde6;
  border-left: 4px solid var(--tema-acento, #2563eb);
  border-radius: 8px;
}

time {
  display: block;
  margin-bottom: 0.2rem;
  color: #64748b;
  font-size: 0.78rem;
  font-weight: 600;
}

h3 {
  margin: 0 0 0.35rem;
  font-size: 1rem;
}

p {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.45;
}
</style>
