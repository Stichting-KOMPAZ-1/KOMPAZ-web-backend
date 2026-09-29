<template>
  <DefaultField
    :field="currentField"
    :errors="errors"
    :show-help-text="showHelpText"
    :full-width-content="fullWidthContent"
  >
    <template #field>
      <VideoPreview :preview="currentField.preview" :labels="currentField" />
    </template>
  </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova'
import VideoPreview from './VideoPreview.vue'

/** The preview on a form. It is shown, never sent: the form's value for it is nothing. */
export default {
  components: { VideoPreview },

  mixins: [HandlesValidationErrors, FormField],

  methods: {
    setInitialValue() {
      this.value = null
    },

    fill() {},
  },
}
</script>
