<template>
  <PanelItem :index="index" :field="field">
    <template #value>
      <ol v-if="field.value && field.value.length > 0" class="space-y-4">
        <li v-for="(slide, position) in field.value" :key="slide.id" class="space-y-1">
          <p class="text-sm text-gray-500">{{ field.slideLabel }} {{ position + 1 }} / {{ field.value.length }}</p>
          <!--
            The size and crop the frontend's carousel gives a slide's picture, 260 by 232. Inline,
            because Nova's stylesheet has no `h-28` or `object-cover` and the classes drew it full size.
          -->
          <img
            :src="slide.imageUrl"
            :alt="slide.title"
            class="rounded"
            style="width: 260px; max-width: 100%; aspect-ratio: 260 / 232; object-fit: cover"
          />
          <p class="font-bold">{{ slide.title }}</p>
          <div v-if="slide.body" class="prose dark:prose-invert max-w-none" v-html="slide.body" />
        </li>
      </ol>
      <p v-else class="text-gray-500">{{ field.emptyLabel }}</p>
    </template>
  </PanelItem>
</template>

<script>
/**
 * A carousel's slides on a part's own page, in the order a reader pages through them: the picture,
 * the title and the body as it is stored — markup, already cleaned on its way in.
 */
export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
}
</script>
