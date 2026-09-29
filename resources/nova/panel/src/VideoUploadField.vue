<template>
  <DefaultField
    :field="currentField"
    :errors="errors"
    :show-help-text="showHelpText"
    :full-width-content="fullWidthContent"
  >
    <template #field>
      <div class="space-y-2" :dusk="fieldAttribute">
        <p v-if="showsCurrent" class="text-sm">
          {{ currentField.currentLabel }} ({{ megabytes(currentField.current.byteCount) }})
        </p>

        <!-- Nova's own drop zone, so this reads as the file field it is next to. -->
        <DropZone
          :files="[]"
          :accepted-types="currentField.accept"
          :disabled="busy || isReadonly"
          :input-dusk="fieldAttribute"
          @file-changed="files => picked(files[0])"
        />

        <p v-if="fileName" class="text-sm text-gray-500 truncate">{{ fileName }}</p>

        <div v-if="state === 'uploading'" class="space-y-1">
          <div class="h-2 w-full rounded bg-gray-200 dark:bg-gray-700 overflow-hidden">
            <div class="h-2 bg-primary-500" :style="{ width: `${progress}%` }" />
          </div>
          <p class="text-sm text-gray-500">{{ currentField.uploadingLabel }} {{ progress }}%</p>
        </div>

        <p v-else-if="state === 'checking'" class="text-sm text-gray-500">
          {{ currentField.checkingLabel }}
        </p>

        <p v-else-if="state === 'done'" class="text-sm text-green-600">
          {{ currentField.doneLabel }}
        </p>

        <p v-if="message" class="text-sm text-red-500">{{ message }}</p>
      </div>
    </template>
  </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova'

/**
 * Uploads a video straight to the video container, and gives the form only the upload's key.
 *
 * Picking a file asks this application for a link that can create one blob, writes the file to it
 * in blocks — Azure's block blob protocol: each block under an identifier of the same length, then
 * the list that makes them one blob — and asks this application to look at the result. Only then
 * does the field have a value, so a form saved before the upload finished sends nothing for it and
 * is refused in the product's words rather than saved with half a video.
 *
 * The writes to Azure go out with plain `fetch`: Nova's own client adds headers of its own, which
 * the container's CORS rule would refuse and which Azure has no use for.
 */
const ATTEMPTS = 3

export default {
  mixins: [HandlesValidationErrors, FormField],

  data: () => ({
    state: 'idle',
    progress: 0,
    fileName: null,
    message: null,
  }),

  computed: {
    busy() {
      return this.state === 'uploading' || this.state === 'checking'
    },

    showsCurrent() {
      return Boolean(this.currentField.current) && !this.value && !this.busy
    },
  },

  beforeUnmount() {
    window.removeEventListener('beforeunload', this.warnBeforeLeaving)
  },

  methods: {
    setInitialValue() {
      this.value = null
    },

    fill(formData) {
      if (this.value) {
        formData.append(this.fieldAttribute, this.value)
      }
    },

    async picked(file) {
      if (!file || this.busy) {
        return
      }

      this.value = null
      this.message = null
      this.fileName = file.name

      if (file.size > this.currentField.maximumBytes) {
        this.message = this.currentField.tooLargeLabel

        return
      }

      window.addEventListener('beforeunload', this.warnBeforeLeaving)

      try {
        this.value = await this.upload(file)
        this.state = 'done'
      } catch (error) {
        this.state = 'idle'
        this.message = this.refusalOf(error)
      } finally {
        window.removeEventListener('beforeunload', this.warnBeforeLeaving)
      }
    },

    async upload(file) {
      const path = this.currentField.uploadsPath

      this.state = 'uploading'
      this.progress = 0

      const { data: issued } = await Nova.request().post(path, { byteCount: file.size })
      const blockSize = issued.blockSizeBytes
      const blocks = []

      for (let offset = 0; offset < file.size; offset += blockSize) {
        // Every identifier in a blob has to be the same length, hence the padding.
        const id = btoa(String(blocks.length).padStart(6, '0'))

        await this.write(
          `${issued.uploadUrl}&comp=block&blockid=${encodeURIComponent(id)}`,
          file.slice(offset, offset + blockSize),
          {}
        )

        blocks.push(id)
        this.progress = Math.round((Math.min(offset + blockSize, file.size) / file.size) * 100)
      }

      const list = blocks.map(id => `<Latest>${id}</Latest>`).join('')

      await this.write(
        `${issued.uploadUrl}&comp=blocklist`,
        `<?xml version="1.0" encoding="utf-8"?><BlockList>${list}</BlockList>`,
        { 'Content-Type': 'application/xml' }
      )

      this.state = 'checking'

      await Nova.request().post(`${path}/${issued.id}/complete`)

      return issued.id
    },

    /** One write to Azure, tried again on failure: a dropped connection costs one block. */
    async write(url, body, headers) {
      let lastError = null

      for (let attempt = 1; attempt <= ATTEMPTS; attempt++) {
        try {
          const response = await fetch(url, { method: 'PUT', body, headers })

          if (response.ok) {
            return
          }

          lastError = new Error(`Azure answered ${response.status}`)
        } catch (error) {
          lastError = error
        }
      }

      throw lastError
    },

    /** The product's sentence for a refusal from this application, or a generic one. */
    refusalOf(error) {
      const body = error?.response?.data
      const errors = body?.errors ?? {}
      const first = Object.values(errors).flat()[0]

      return first ?? body?.detail ?? body?.message ?? this.currentField.failedLabel
    },

    megabytes(bytes) {
      return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`
    },

    warnBeforeLeaving(event) {
      event.preventDefault()
      event.returnValue = this.currentField.leaveWarning

      return this.currentField.leaveWarning
    },
  },
}
</script>
