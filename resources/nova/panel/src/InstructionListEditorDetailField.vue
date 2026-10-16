<template>
  <PanelItem :index="index" :field="field">
    <template #value>
      <ol v-if="field.value && field.value.length > 0" class="space-y-3 list-decimal list-inside">
        <li v-for="(instruction, position) in field.value" :key="`instruction-${position}`">
          <span v-if="instruction.title" class="font-bold">{{ instruction.title }}</span>
          <div class="prose dark:prose-invert max-w-none" v-html="instruction.body" />
          <img
            v-if="instruction.imageUrl"
            :src="instruction.imageUrl"
            :alt="field.imageLabel"
            class="mt-1 h-24 w-24 object-cover rounded"
          />
        </li>
      </ol>
      <p v-else class="text-gray-500">{{ field.emptyLabel }}</p>
    </template>
  </PanelItem>
</template>

<script>
/**
 * A "Stap" block's instructions on a part's own page: numbered, each with its heading, its text as
 * it is stored — markup, already cleaned on its way in — and its picture.
 */
export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
}
</script>
