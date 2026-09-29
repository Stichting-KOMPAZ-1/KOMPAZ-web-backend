<template>
  <PanelItem :index="index" :field="field">
    <template #value>
      <div v-if="field.current" class="space-y-1">
        <video
          v-if="field.current.previewUrl"
          :src="field.current.previewUrl"
          controls
          preload="metadata"
          class="w-full max-w-md rounded bg-black"
        />
        <p>{{ field.currentLabel }} ({{ megabytes(field.current.byteCount) }})</p>
      </div>
      <p v-else class="text-gray-500">{{ field.noneLabel }}</p>
    </template>
  </PanelItem>
</template>

<script>
/**
 * The upload field on a detail page: the row's uploaded video, playable, and how large it is.
 * The player's address redirects to a short-lived link, asked the way an edit is asked.
 */
export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],

  methods: {
    megabytes(bytes) {
      return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`
    },
  },
}
</script>
