<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import { RouterView, useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'

const router = useRouter()
const auth = useAuth()

let offMenu: (() => void) | undefined

onMounted(async () => {
  await auth.refresh()
  offMenu = window.abcVentas?.onMenuConfiguracion(() => {
    router.push({ name: 'configuracion' })
  })
})

onUnmounted(() => {
  offMenu?.()
})
</script>

<template>
  <div class="app-shell">
    <RouterView />
  </div>
</template>
