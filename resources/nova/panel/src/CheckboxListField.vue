<template>
  <DefaultField
    :field="currentField"
    :errors="errors"
    :show-help-text="showHelpText"
    :full-width-content="fullWidthContent"
  >
    <template #field>
      <div class="space-y-3" :dusk="fieldAttribute">
        <input
          v-model="search"
          type="search"
          class="w-full form-control form-input form-control-bordered"
          :placeholder="currentField.searchPlaceholder"
          :disabled="currentlyIsReadonly"
        />

        <div class="flex items-center gap-4 text-sm">
          <button
            type="button"
            class="link-default font-bold"
            :disabled="currentlyIsReadonly || visible.length === 0"
            @click="setVisible(true)"
          >
            {{ currentField.selectAllLabel }}
          </button>
          <button
            type="button"
            class="link-default font-bold"
            :disabled="currentlyIsReadonly || visible.length === 0"
            @click="setVisible(false)"
          >
            {{ currentField.deselectAllLabel }}
          </button>
          <span class="ml-auto text-gray-500">
            {{ checkedCount }} / {{ options.length }}
          </span>
        </div>

        <div
          class="max-h-72 overflow-y-auto space-y-2 rounded border border-gray-200 dark:border-gray-700 p-3"
        >
          <CheckboxWithLabel
            v-for="option in visible"
            :key="option.name"
            :name="option.name"
            :checked="option.checked"
            :disabled="currentlyIsReadonly"
            @input="toggle($event, option)"
          >
            <span>{{ option.label }}</span>
          </CheckboxWithLabel>

          <p v-if="visible.length === 0" class="text-sm text-gray-500">
            {{ currentField.emptyLabel }}
          </p>
        </div>
      </div>
    </template>
  </DefaultField>
</template>

<script>
import { DependentFormField, HandlesValidationErrors } from 'laravel-nova'

/**
 * Nova's boolean group with a search box and "select all / none" above it.
 *
 * It sends exactly what Nova's own sends — a JSON object of every option's key against whether it
 * is ticked — so whatever reads a BooleanGroup on the server reads this unchanged. "Select all"
 * acts on the options the search is showing, which is what makes it useful on a long list: search
 * for "UMC", select all, done.
 */
export default {
  mixins: [HandlesValidationErrors, DependentFormField],

  data: () => ({
    options: [],
    search: '',
  }),

  methods: {
    setInitialValue() {
      const values = this.currentField.value || {}

      this.options = this.currentField.options.map(option => ({
        name: option.name,
        label: option.label,
        checked: values[option.name] || false,
      }))
    },

    fill(formData) {
      this.fillIfVisible(formData, this.fieldAttribute, JSON.stringify(this.payload))
    },

    toggle(event, option) {
      option.checked = event.target.checked
      this.changed()
    },

    setVisible(checked) {
      this.visible.forEach(option => {
        option.checked = checked
      })
      this.changed()
    },

    changed() {
      if (this.field) {
        this.emitFieldValueChange(this.fieldAttribute, JSON.stringify(this.payload))
      }
    },

    onSyncedField() {
      this.setInitialValue()
    },
  },

  computed: {
    visible() {
      const needle = this.search.trim().toLocaleLowerCase('nl')

      if (needle === '') {
        return this.options
      }

      return this.options.filter(option =>
        String(option.label).toLocaleLowerCase('nl').includes(needle)
      )
    },

    checkedCount() {
      return this.options.filter(option => option.checked).length
    },

    payload() {
      return Object.fromEntries(this.options.map(option => [option.name, option.checked]))
    },
  },
}
</script>
