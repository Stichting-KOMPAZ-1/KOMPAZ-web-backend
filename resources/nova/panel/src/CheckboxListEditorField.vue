<template>
  <DefaultField
    :field="currentField"
    :errors="errors"
    :show-help-text="showHelpText"
    :full-width-content="true"
  >
    <template #field>
      <div class="space-y-2" :dusk="fieldAttribute">
        <div v-for="(option, index) in options" :key="option.key" class="flex items-center gap-2">
          <!-- Drawn, not interactive: the box is what the reader ticks, not the operator. -->
          <span
            class="shrink-0 h-4 w-4 rounded-sm border-2 border-gray-300 dark:border-gray-600"
            aria-hidden="true"
          />

          <input
            v-model="option.label"
            type="text"
            class="form-control form-input form-control-bordered w-full"
            :maxlength="currentField.maximumLength"
            :placeholder="currentField.optionPlaceholder"
            :aria-label="`${currentField.optionPlaceholder} ${index + 1}`"
            :disabled="isReadonly"
          />

          <button
            type="button"
            class="text-gray-400 hover:text-red-500"
            :title="currentField.removeOptionLabel"
            :aria-label="currentField.removeOptionLabel"
            :disabled="isReadonly"
            @click="removeOption(index)"
          >
            ×
          </button>
        </div>

        <p v-if="options.length === 0" class="text-gray-500">{{ currentField.emptyLabel }}</p>

        <Button
          variant="ghost"
          size="small"
          :disabled="isReadonly || options.length >= currentField.maximumOptions"
          @click="addOption"
        >
          {{ currentField.addOptionLabel }}
        </Button>
      </div>
    </template>
  </DefaultField>
</template>

<script>
import { Button } from 'laravel-nova-ui'
import { FormField, HandlesValidationErrors } from 'laravel-nova'

/**
 * A checkbox block's options (KOM-107): one text field per option, "+ optie" up to the limit, and a
 * cross to take one away.
 *
 * Sent as a JSON list of `{id, label}`. An option read from the block keeps its `id`, which is
 * what lets a reader's ticks survive the option being reworded or moved; a new one has none, and
 * is given one when it is saved. Untouched — every option empty — it sends nothing at all, so a
 * "Checkboxes" added with "+ blok" and then left alone is dropped like any empty block rather than
 * refused for having no options. Blank options among filled ones are dropped on the server, which
 * is why none is refused here either.
 */
export default {
  components: { Button },

  mixins: [HandlesValidationErrors, FormField],

  data: () => ({
    options: [],
    // Only for Vue to tell rows apart: a new option has no `id` yet, and an index would hand one
    // row's input to the next when an option above it is removed.
    nextKey: 0,
  }),

  methods: {
    setInitialValue() {
      const value = this.currentField.value

      this.options =
        Array.isArray(value) && value.length > 0
          ? value.map(option => this.row(option?.id ?? null, option?.label ?? ''))
          : [this.row(null, '')]
    },

    fill(formData) {
      if (this.isBlank()) {
        return
      }

      formData.append(
        this.fieldAttribute,
        JSON.stringify(this.options.map(({ id, label }) => ({ id, label }))),
      )
    },

    isBlank() {
      return this.options.every(option => String(option.label ?? '').trim() === '')
    },

    row(id, label) {
      return { key: this.nextKey++, id, label }
    },

    addOption() {
      if (this.options.length >= this.currentField.maximumOptions) {
        return
      }

      this.options.push(this.row(null, ''))
    },

    removeOption(index) {
      this.options.splice(index, 1)
    },
  },
}
</script>
