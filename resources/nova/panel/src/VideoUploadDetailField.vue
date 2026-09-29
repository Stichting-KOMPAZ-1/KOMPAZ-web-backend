<template>
  <PanelItem :index="index" :field="field">
    <template #value>
      <p v-if="field.current">
        {{ field.currentLabel }} ({{ megabytes(field.current.byteCount) }})
      </p>
      <p v-else class="text-gray-500">{{ field.noneLabel }}</p>
    </template>
  </PanelItem>
</template>

<script>
/**
 * The upload field on a detail page: whether the row has an uploaded video, and how large it is.
 * The video itself is played by the frontend, on a link the API signs for a reader.
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
