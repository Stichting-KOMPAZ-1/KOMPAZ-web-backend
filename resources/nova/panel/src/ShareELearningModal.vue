<template>
  <Modal :show="show" @close-via-escape="$emit('close')" role="dialog" size="lg">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden">
      <ModalHeader v-text="data.title" />

      <ModalContent class="space-y-6">
        <p class="text-gray-500">{{ data.eLearning }} · {{ data.organization }}</p>

        <div class="space-y-2">
          <label class="block font-bold" for="share-e-learning-link">{{ data.labels.link }}</label>
          <div class="flex items-center gap-2">
            <input
              id="share-e-learning-link"
              ref="link"
              type="text"
              readonly
              :value="data.link"
              class="form-control form-input form-control-bordered w-full"
              @focus="$event.target.select()"
            />
            <Button variant="solid" @click="copy">{{ data.labels.copy }}</Button>
          </div>
        </div>

        <div class="space-y-2">
          <span class="block font-bold">{{ data.labels.qrCode }}</span>
          <img
            :src="data.qrCodeUrl"
            :alt="data.labels.qrCode"
            width="192"
            height="192"
            class="block border border-gray-200 dark:border-gray-700 rounded"
          />
          <a :href="data.qrCodeDownloadUrl" download class="link-default inline-block">
            {{ data.labels.download }}
          </a>
        </div>
      </ModalContent>

      <ModalFooter>
        <div class="flex items-center ml-auto">
          <Button variant="link" state="mellow" @click="$emit('close')">
            {{ data.labels.close }}
          </Button>
        </div>
      </ModalFooter>
    </div>
  </Modal>
</template>

<script>
import { Button } from 'laravel-nova-ui'

/**
 * The answer to "Delen" on an e-learning: its link for one organization, to copy, and its QR code,
 * to download (KOM-95). Everything it shows — the words included — comes from the action, so this
 * only draws it.
 *
 * Copying asks the browser's clipboard, which only a secure page may use; where that is refused,
 * the link is selected in its field instead and the operator is told to copy it themselves.
 */
export default {
  components: { Button },

  emits: ['confirm', 'close'],

  props: {
    show: { type: Boolean, default: false },
    data: { type: Object, required: true },
  },

  methods: {
    async copy() {
      try {
        await navigator.clipboard.writeText(this.data.link)
        Nova.success(this.data.labels.copied)
      } catch (error) {
        this.$refs.link.select()
        Nova.error(this.data.labels.copyFailed)
      }
    },
  },
}
</script>
