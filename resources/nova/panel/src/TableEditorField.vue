<template>
  <DefaultField
    :field="currentField"
    :errors="errors"
    :show-help-text="showHelpText"
    :full-width-content="true"
  >
    <template #field>
      <div class="space-y-3" :dusk="fieldAttribute">
        <!-- Wider than the form when it has many columns: it scrolls sideways rather than squeezing. -->
        <div class="overflow-x-auto">
          <table style="border-collapse: separate; border-spacing: 0.5rem">
            <thead>
              <tr>
                <th v-for="(header, column) in table.columns" :key="`header-${column}`" class="align-top">
                  <div class="flex items-center gap-1">
                    <input
                      v-model="table.columns[column]"
                      type="text"
                      class="form-control form-input form-control-bordered font-bold"
                      style="min-width: 10rem"
                      :placeholder="currentField.headerPlaceholder"
                      :aria-label="`${currentField.columnsLabel} ${column + 1}`"
                      :disabled="isReadonly"
                    />
                    <button
                      v-if="table.columns.length > 1"
                      type="button"
                      class="text-gray-400 hover:text-red-500"
                      :title="currentField.removeColumnLabel"
                      :aria-label="currentField.removeColumnLabel"
                      :disabled="isReadonly"
                      @click="removeColumn(column)"
                    >
                      ×
                    </button>
                  </div>
                </th>
                <th class="align-top">
                  <Button
                    variant="ghost"
                    size="small"
                    :disabled="isReadonly || table.columns.length >= currentField.maximumColumns"
                    @click="addColumn"
                  >
                    {{ currentField.addColumnLabel }}
                  </Button>
                </th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="(row, index) in table.rows" :key="`row-${index}`">
                <td v-for="(cell, column) in row" :key="`cell-${index}-${column}`">
                  <input
                    v-model="table.rows[index][column]"
                    type="text"
                    class="form-control form-input form-control-bordered"
                    style="min-width: 10rem"
                    :aria-label="`${currentField.rowsLabel} ${index + 1}, ${table.columns[column] || column + 1}`"
                    :disabled="isReadonly"
                  />
                </td>
                <td>
                  <button
                    type="button"
                    class="text-gray-400 hover:text-red-500"
                    :title="currentField.removeRowLabel"
                    :aria-label="currentField.removeRowLabel"
                    :disabled="isReadonly"
                    @click="removeRow(index)"
                  >
                    ×
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <Button
          variant="ghost"
          size="small"
          :disabled="isReadonly || table.rows.length >= currentField.maximumRows"
          @click="addRow"
        >
          {{ currentField.addRowLabel }}
        </Button>
      </div>
    </template>
  </DefaultField>
</template>

<script>
import { Button } from 'laravel-nova-ui'
import { FormField, HandlesValidationErrors } from 'laravel-nova'

/**
 * A table block's table (KOM-101): column headers the operator adds up to the limit, and rows of
 * one text field per column, "+ rij" to add one and a cross to take it away.
 *
 * Sent as JSON, `{columns, rows}`. Untouched — every header and every cell empty — it sends nothing
 * at all, so a "Tabel" added with "+ blok" and then left alone is dropped like any empty block
 * rather than refused for its empty header.
 */
export default {
  components: { Button },

  mixins: [HandlesValidationErrors, FormField],

  data: () => ({
    table: { columns: [''], rows: [] },
  }),

  methods: {
    setInitialValue() {
      const value = this.currentField.value

      this.table =
        value && Array.isArray(value.columns) && value.columns.length > 0
          ? {
              columns: [...value.columns],
              rows: (value.rows ?? []).map(row => value.columns.map((_, column) => row[column] ?? '')),
            }
          : { columns: [''], rows: [] }
    },

    fill(formData) {
      if (this.isBlank()) {
        return
      }

      formData.append(this.fieldAttribute, JSON.stringify(this.table))
    },

    isBlank() {
      const cells = [...this.table.columns, ...this.table.rows.flat()]

      return cells.every(cell => String(cell ?? '').trim() === '')
    },

    addColumn() {
      if (this.table.columns.length >= this.currentField.maximumColumns) {
        return
      }

      this.table.columns.push('')
      this.table.rows.forEach(row => row.push(''))
    },

    removeColumn(column) {
      this.table.columns.splice(column, 1)
      this.table.rows.forEach(row => row.splice(column, 1))
    },

    addRow() {
      if (this.table.rows.length >= this.currentField.maximumRows) {
        return
      }

      this.table.rows.push(this.table.columns.map(() => ''))
    },

    removeRow(index) {
      this.table.rows.splice(index, 1)
    },
  },
}
</script>
