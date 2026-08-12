<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { formatDecimalDisplay, parseDecimalInput } from '@/composables/useDecimalInput'

const model = defineModel<number | null>({ default: null })

const props = withDefaults(
  defineProps<{
    /** Si true, vacío → null; si false, vacío → 0. */
    emptyAsNull?: boolean
    integer?: boolean
    readonly?: boolean
    disabled?: boolean
    required?: boolean
    id?: string
    name?: string
    placeholder?: string
    fieldKey?: string
    maxlength?: number | string
    class?: string
  }>(),
  {
    emptyAsNull: true,
    integer: false,
    readonly: false,
    disabled: false,
    required: false,
  }
)

const draft = ref<string | null>(null)
const focused = ref(false)

const display = computed(() => {
  if (draft.value !== null) return draft.value
  return formatDecimalDisplay(model.value)
})

function emptyValue(): number | null {
  return props.emptyAsNull ? null : 0
}

function onFocus() {
  focused.value = true
  if (draft.value === null) {
    draft.value = formatDecimalDisplay(model.value)
  }
}

function onInput(e: Event) {
  const raw = (e.target as HTMLInputElement).value
  draft.value = raw
  const parsed = parseDecimalInput(raw, { integer: props.integer })
  if (parsed.complete) {
    model.value = parsed.value
  }
}

function onBlur() {
  focused.value = false
  const parsed = parseDecimalInput(draft.value ?? '', { integer: props.integer })
  model.value = parsed.value === null ? emptyValue() : parsed.value
  draft.value = null
}

watch(model, () => {
  if (!focused.value) draft.value = null
})
</script>

<template>
  <input
    type="text"
    :inputmode="integer ? 'numeric' : 'decimal'"
    :id="id"
    :name="name"
    :class="props.class"
    :value="display"
    :readonly="readonly"
    :disabled="disabled"
    :required="required"
    :placeholder="placeholder"
    :maxlength="maxlength != null ? Number(maxlength) : undefined"
    :data-field-key="fieldKey"
    @focus="onFocus"
    @input="onInput"
    @blur="onBlur"
  />
</template>
