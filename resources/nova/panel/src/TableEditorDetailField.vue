<template>
  <PanelItem :index="index" :field="field">
    <template #value>
      <div v-if="field.value" class="overflow-x-auto">
        <table class="min-w-full text-left">
          <thead>
            <tr>
              <th
                v-for="(header, column) in field.value.columns"
                :key="`header-${column}`"
                class="px-3 py-2 font-bold border-b border-gray-200 dark:border-gray-700 whitespace-nowrap"
              >
                {{ header }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in field.value.rows" :key="`row-${index}`">
              <td
                v-for="(cell, column) in row"
                :key="`cell-${index}-${column}`"
                class="px-3 py-2 border-b border-gray-100 dark:border-gray-800 align-top"
              >
                {{ cell }}
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="field.value.rows.length === 0" class="text-gray-500 mt-2">{{ field.emptyLabel }}</p>
      </div>
    </template>
  </PanelItem>
</template>

<script>
/**
 * A table block's table on a part's own page: the headers and the rows as they are read, plain
 * text, scrolling sideways when it is wider than the page.
 */
export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
}
</script>
