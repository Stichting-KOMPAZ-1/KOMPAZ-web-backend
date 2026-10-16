<template>
  <PanelItem :index="index" :field="field">
    <template #value>
      <ul v-if="field.value && field.value.length > 0" class="space-y-1">
        <li v-for="(option, index) in field.value" :key="`option-${index}`" class="flex items-start gap-2">
          <span
            class="shrink-0 mt-1 h-4 w-4 rounded-sm border-2 border-gray-300 dark:border-gray-600"
            aria-hidden="true"
          />
          <span>{{ option }}</span>
        </li>
      </ul>
      <p v-else class="text-gray-500">{{ field.emptyLabel }}</p>
    </template>
  </PanelItem>
</template>

<script>
/**
 * A checkbox block's options on a part's own page: the labels as they are read, each beside an
 * empty box. Nothing here is ticked, because nothing is stored about ticking.
 */
export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
}
</script>
