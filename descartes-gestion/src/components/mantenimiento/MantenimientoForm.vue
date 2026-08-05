<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import type { CampoEntidad } from '@/config/entidades'
import { api } from '@/api/client'

const props = defineProps<{
  campos: CampoEntidad[]
  modelValue: Record<string, unknown>
  esNuevo?: boolean
  entidad?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
  guardar: []
  cancelar: []
}>()

const local = reactive<Record<string, unknown>>({ ...props.modelValue })
const rolesOptions = ref<{ value: string; label: string }[]>([])
const almacenesOptions = ref<{ value: string; label: string }[]>([])
const impuestosOptions = ref<{ value: string; label: string }[]>([])
const proveedoresOptions = ref<{ value: string; label: string }[]>([])
const tiendasOptions = ref<{ value: string; label: string }[]>([])
const trabajadoresOptions = ref<{ value: string; label: string }[]>([])
const usuariosOptions = ref<{ value: string; label: string }[]>([])

const camposVisibles = computed(() =>
  props.campos.filter((c) => !c.onlyCreate || props.esNuevo)
)

const esTiendaCentral = computed(
  () => props.entidad === 'tiendas' && local.esCentral === true
)

watch(
  () => props.modelValue,
  (value) => {
    Object.assign(local, value)
  },
  { deep: true }
)

onMounted(async () => {
  if (props.campos.some((c) => c.optionsSource === 'roles')) {
    const { data } = await api.get('/api/mantenimiento/roles', { params: { activo: true, pageSize: 100 } })
    rolesOptions.value = (data.items ?? []).map((r: { codigo: string; nombre: string }) => ({
      value: r.codigo,
      label: `${r.codigo} - ${r.nombre}`,
    }))
  }
  if (props.campos.some((c) => c.optionsSource === 'almacenes')) {
    const { data } = await api.get('/api/mantenimiento/almacenes', { params: { activo: true, pageSize: 100 } })
    almacenesOptions.value = (data.items ?? []).map((a: { codigo: number; descripcion: string }) => ({
      value: String(a.codigo),
      label: `${a.codigo} - ${a.descripcion}`,
    }))
  }
  if (props.campos.some((c) => c.optionsSource === 'impuestos')) {
    const { data } = await api.get('/api/mantenimiento/impuestos', { params: { activo: true, pageSize: 100 } })
    impuestosOptions.value = (data.items ?? []).map((i: { codigo: string; descripcion: string; porcentajeIVA?: number }) => ({
      value: i.codigo,
      label: `${i.codigo} - ${i.descripcion}${i.porcentajeIVA != null ? ` (${i.porcentajeIVA}%)` : ''}`,
    }))
  }
  if (props.campos.some((c) => c.optionsSource === 'proveedores')) {
    const { data } = await api.get('/api/mantenimiento/proveedores', { params: { activo: true, pageSize: 100 } })
    proveedoresOptions.value = (data.items ?? []).map((p: { codigo: string; nombre: string }) => ({
      value: p.codigo,
      label: `${p.codigo} - ${p.nombre}`,
    }))
  }
  if (props.campos.some((c) => c.optionsSource === 'tiendas')) {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { activo: true, pageSize: 100 } })
    tiendasOptions.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: t.codigo,
      label: `${t.codigo} - ${t.nombre}`,
    }))
  }
  if (props.campos.some((c) => c.optionsSource === 'trabajadores')) {
    const { data } = await api.get('/api/mantenimiento/trabajadores', { params: { activo: true, pageSize: 100 } })
    trabajadoresOptions.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: t.codigo,
      label: `${t.codigo} - ${t.nombre}`,
    }))
  }
  if (props.campos.some((c) => c.optionsSource === 'usuarios')) {
    const { data } = await api.get('/api/mantenimiento/usuarios', { params: { activo: true, pageSize: 100 } })
    usuariosOptions.value = (data.items ?? []).map((u: { codigo: string; nombre: string }) => ({
      value: u.codigo,
      label: `${u.codigo} - ${u.nombre}`,
    }))
  }
})

function opciones(campo: CampoEntidad) {
  if (campo.optionsSource === 'roles') return rolesOptions.value
  if (campo.optionsSource === 'almacenes') return almacenesOptions.value
  if (campo.optionsSource === 'impuestos') return impuestosOptions.value
  if (campo.optionsSource === 'proveedores') return proveedoresOptions.value
  if (campo.optionsSource === 'tiendas') return tiendasOptions.value
  if (campo.optionsSource === 'trabajadores') return trabajadoresOptions.value
  if (campo.optionsSource === 'usuarios') return usuariosOptions.value
  return campo.options ?? []
}

const optionalSelectKeys = new Set([
  'almacenCodigo',
  'impuestoCodigo',
  'proveedorHabitual',
  'usuarioCodigo',
  'trabajadorCodigo',
])

function onGuardar() {
  const payload = { ...local }
  for (const key of optionalSelectKeys) {
    if (key in payload && payload[key] === '') {
      payload[key] = null
    }
  }
  emit('update:modelValue', payload)
  emit('guardar')
}
</script>

<template>
  <form class="form" @submit.prevent="onGuardar">
    <p v-if="esTiendaCentral" class="aviso-central">
      Esta es la <strong>tienda central</strong> (sede principal). Los datos fiscales del cliente se
      gestionan en Empresa. No se puede dar de baja desde aqui.
    </p>

    <div v-for="campo in camposVisibles" :key="campo.key" class="field">
      <label :for="campo.key">{{ campo.label }}</label>
      <select
        v-if="campo.type === 'select'"
        :id="campo.key"
        v-model="local[campo.key]"
        :required="campo.required"
        :disabled="campo.readOnly"
      >
        <option value="">-- Seleccionar --</option>
        <option v-for="opt in opciones(campo)" :key="opt.value" :value="opt.value">
          {{ opt.label }}
        </option>
      </select>
      <input
        v-else-if="campo.type === 'checkbox'"
        :id="campo.key"
        v-model="local[campo.key]"
        type="checkbox"
        :disabled="campo.readOnly || (campo.key === 'activo' && esTiendaCentral)"
      />
      <input
        v-else
        :id="campo.key"
        v-model="local[campo.key]"
        :type="campo.type ?? 'text'"
        :required="campo.required"
        :readonly="campo.readOnly || (!esNuevo && campo.key === 'codigo')"
        :placeholder="campo.type === 'password' && !esNuevo ? 'Dejar vacio para no cambiar' : undefined"
      />
    </div>

    <p
      v-if="entidad === 'almacenes' && Array.isArray(modelValue.tiendasVinculadas) && modelValue.tiendasVinculadas.length"
      class="vinculo-info"
    >
      Tiendas vinculadas (almacen principal):
      {{ (modelValue.tiendasVinculadas as string[]).join(', ') }}
    </p>

    <div class="actions">
      <button type="submit">Guardar</button>
      <button type="button" @click="$emit('cancelar')">Cancelar</button>
    </div>
  </form>
</template>

<style scoped>
.form {
  background: #fff;
  border-radius: 8px;
  padding: 1rem;
  display: grid;
  gap: 0.75rem;
}

.aviso-central {
  background: #fef3c7;
  border: 1px solid #f59e0b;
  border-radius: 8px;
  padding: 0.75rem;
  color: #92400e;
  margin: 0;
}

.vinculo-info {
  background: #eff6ff;
  border-radius: 8px;
  padding: 0.5rem 0.75rem;
  color: #1e40af;
  margin: 0;
}

.field {
  display: grid;
  gap: 0.25rem;
}

input[type='text'],
input[type='number'],
input[type='email'],
input[type='password'],
select {
  padding: 0.5rem 0.65rem;
  border: 1px solid #d1d5db;
  border-radius: 8px;
}

.actions {
  display: flex;
  gap: 0.5rem;
}

button[type='submit'] {
  background: #2563eb;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 0.5rem 0.9rem;
}
</style>
