<template>
  <PanelItem :index="index" :field="field">
    <template #value>
      <ol v-if="field.value && field.value.length > 0" class="space-y-4">
        <li v-for="(instruction, position) in field.value" :key="`instruction-${position}`" class="space-y-1">
          <!--
            Picture, heading, text — the order the reader sees them in. The number goes beside the
            heading rather than at the top of the item, because with the picture first a number on
            its own line would be numbering the picture.
          -->
          <img
            v-if="instruction.imageUrl"
            :src="instruction.imageUrl"
            :alt="instruction.title ?? field.imageLabel"
            class="h-28 w-full max-w-sm object-cover rounded"
          />

          <p class="font-bold">
            <span class="text-gray-500">{{ position + 1 }}.</span>
            {{ instruction.title }}
          </p>

          <div class="prose dark:prose-invert max-w-none" v-html="instruction.body" />
        </li>
      </ol>
      <p v-else class="text-gray-500">{{ field.emptyLabel }}</p>
    </template>
  </PanelItem>
</template>

<script>
/**
 * A "Stap" block's instructions on a part's own page, drawn the way the card the reader gets is:
 * the picture, then the numbered heading, then the text as it is stored — markup, already cleaned
 * on its way in.
 */
export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
}
</script>
