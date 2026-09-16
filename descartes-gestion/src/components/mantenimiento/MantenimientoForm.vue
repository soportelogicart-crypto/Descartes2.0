<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { CampoEntidad } from '@/config/entidades'
import { entidadDesdeOptionsSource } from '@/config/entidad-lookup'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'

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

const optionalSelectKeys = new Set([
  'almacenCodigo',
  'impuestoCodigo',
  'proveedorHabitual',
  'usuarioCodigo',
  'trabajadorCodigo',
  'rolCodigo',
  'tiendaCodigo',
])

function lookupEntidad(campo: CampoEntidad) {
  return entidadDesdeOptionsSource(campo.optionsSource)
}

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
      <EntidadLookupField
        v-if="lookupEntidad(campo)"
        :model-value="(local[campo.key] as string | number | null) ?? null"
        :entidad="lookupEntidad(campo)!"
        :readonly="campo.readOnly"
        :max-length="campo.maxLength"
        :field-key="campo.key"
        empty-as-null
        @update:model-value="local[campo.key] = $event"
      />
      <select
        v-else-if="campo.type === 'select'"
        :id="campo.key"
        v-model="local[campo.key]"
        :required="campo.required"
        :disabled="campo.readOnly"
      >
        <option value="">-- Seleccionar --</option>
        <option v-for="opt in campo.options ?? []" :key="opt.value" :value="opt.value">
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
      <DecimalInput
        v-else-if="campo.type === 'number'"
        :id="campo.key"
        :model-value="(local[campo.key] as number | null) ?? null"
        :required="campo.required"
        :readonly="campo.readOnly || (!esNuevo && campo.key === 'codigo')"
        @update:model-value="local[campo.key] = $event"
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
      <button type="button" @click="emit('cancelar')">Cancelar</button>
    </div>
  </form>
</template>

<style scoped>
.form {
  display: grid;
  gap: 0.65rem;
  max-width: 420px;
}

.field {
  display: grid;
  gap: 0.2rem;
}

.field label {
  font-size: 0.85rem;
  color: #475569;
}

.aviso-central {
  margin: 0;
  padding: 0.5rem 0.65rem;
  background: #eff6ff;
  border: 1px solid #93c5fd;
  border-radius: 6px;
  font-size: 0.85rem;
}

.vinculo-info {
  margin: 0;
  font-size: 0.8rem;
  color: #64748b;
}

.actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.25rem;
}

.actions button {
  padding: 0.35rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #94a3b8;
  background: #fff;
  cursor: pointer;
}

.actions button[type='submit'] {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}
</style>
