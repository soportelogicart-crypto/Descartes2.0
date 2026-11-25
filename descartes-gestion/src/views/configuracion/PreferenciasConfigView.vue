<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { TEMAS } from '@/config/temas'
import { IDIOMAS, type IdiomaUi } from '@/i18n'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { t, locale } = useI18n()
const guardando = ref(false)
const error = ref('')

const temaActivo = computed(() => auth.usuario?.colorTema || 'azul')
const ayudasActivas = computed(() => auth.usuario?.ayudasMenu !== false)

async function cambiarIdioma(id: IdiomaUi) {
  if (guardando.value || locale.value === id) return
  guardando.value = true
  error.value = ''
  try {
    await auth.guardarPreferencias({ idioma: id })
  } catch {
    error.value = t('preferencias.error')
  } finally {
    guardando.value = false
  }
}

async function cambiarTema(id: string) {
  if (guardando.value || temaActivo.value === id) return
  guardando.value = true
  error.value = ''
  try {
    await auth.guardarPreferencias({ colorTema: id })
  } catch {
    error.value = t('preferencias.error')
  } finally {
    guardando.value = false
  }
}

async function cambiarAyudas(ev: Event) {
  const activo = (ev.target as HTMLInputElement).checked
  if (guardando.value) return
  guardando.value = true
  error.value = ''
  try {
    await auth.guardarPreferencias({ ayudasMenu: activo })
  } catch {
    error.value = t('preferencias.error')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <section class="prefs">
    <RouterLink to="/configuracion" class="volver">← Configuración</RouterLink>
    <h2>{{ t('preferencias.titulo') }}</h2>
    <p class="intro">{{ t('preferencias.intro') }}</p>

    <div class="panel">
      <h3>{{ t('preferencias.idioma') }}</h3>
      <div class="idiomas">
        <button
          v-for="idioma in IDIOMAS"
          :key="idioma.id"
          type="button"
          class="idioma"
          :class="{ activo: locale === idioma.id }"
          :disabled="guardando"
          @click="cambiarIdioma(idioma.id)"
        >
          {{ idioma.etiqueta }}
        </button>
      </div>
    </div>

    <div class="panel">
      <h3>{{ t('preferencias.color') }}</h3>
      <div class="colores">
        <button
          v-for="tema in TEMAS"
          :key="tema.id"
          type="button"
          class="color"
          :class="{ activo: temaActivo === tema.id }"
          :style="{ background: tema.muestra }"
          :title="t(`temas.${tema.id}`)"
          :aria-label="t(`temas.${tema.id}`)"
          :disabled="guardando"
          @click="cambiarTema(tema.id)"
        >
          <span>{{ t(`temas.${tema.id}`) }}</span>
        </button>
      </div>
    </div>

    <div class="panel">
      <label class="ayudas">
        <input type="checkbox" :checked="ayudasActivas" :disabled="guardando" @change="cambiarAyudas" />
        {{ t('preferencias.ayudas') }}
      </label>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
  </section>
</template>

<style scoped>
.prefs { max-width: 40rem; }
.volver { color: #2563eb; text-decoration: none; font-size: .85rem; }
h2 { margin: .6rem 0 .25rem; }
.intro { margin: 0 0 1rem; color: #64748b; }
.panel { margin-bottom: 1rem; padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; }
h3 { margin: 0 0 .7rem; font-size: .95rem; color: #0f172a; }
.idiomas { display: flex; flex-wrap: wrap; gap: .5rem; }
.idioma {
  padding: .4rem .8rem;
  border: 1px solid #94a3b8;
  border-radius: 7px;
  background: #fff;
  cursor: pointer;
}
.idioma.activo { color: #fff; border-color: #1d4ed8; background: #2563eb; }
.colores { display: flex; flex-wrap: wrap; gap: .5rem; }
.color {
  display: flex;
  align-items: center;
  gap: .45rem;
  padding: .35rem .7rem .35rem .35rem;
  border: 2px solid transparent;
  border-radius: 999px;
  color: #fff;
  font-size: .82rem;
  cursor: pointer;
}
.color span {
  padding-right: .15rem;
  text-shadow: 0 1px 1px rgb(0 0 0 / 35%);
}
.color.activo { border-color: #0f172a; }
.ayudas { display: flex; align-items: center; gap: .5rem; cursor: pointer; }
button:disabled { opacity: .5; cursor: not-allowed; }
.error { color: #b91c1c; }
</style>
