<template>
  <PanelItem :index="index" :field="field">
    <template #value>
      <VideoPreview :preview="field.preview" :labels="field" />
    </template>
  </PanelItem>
</template>

<script>
import VideoPreview from './VideoPreview.vue'

/** The preview on a detail page. */
export default {
  components: { VideoPreview },

  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
}
</script>
