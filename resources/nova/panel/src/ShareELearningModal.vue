<template>
  <Modal :show="show" @close-via-escape="$emit('close')" role="dialog" size="lg">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden">
      <ModalHeader v-text="data.title" />

      <ModalContent class="space-y-6">
        <p class="text-gray-500">{{ data.eLearning }}</p>

        <div class="space-y-2">
          <label class="block font-bold" for="share-e-learning-organization">
            {{ data.labels.organization }}
          </label>
          <select
            id="share-e-learning-organization"
            v-model="organizationId"
            class="form-control form-select form-select-bordered w-full"
          >
            <option v-if="data.organizations.length > 1" value="" disabled>
              {{ data.labels.choose }}
            </option>
            <option
              v-for="organization in data.organizations"
              :key="organization.id"
              :value="organization.id"
            >
              {{ organization.name }}
            </option>
          </select>
        </div>

        <template v-if="chosen">
          <div class="space-y-2">
            <label class="block font-bold" for="share-e-learning-link">{{ data.labels.link }}</label>
            <div class="flex items-center gap-2">
              <input
                id="share-e-learning-link"
                ref="link"
                type="text"
                readonly
                :value="chosen.link"
                class="form-control form-input form-control-bordered w-full"
                @focus="$event.target.select()"
              />
              <Button variant="solid" @click="copy">{{ data.labels.copy }}</Button>
            </div>
          </div>

          <div class="space-y-2">
            <span class="block font-bold">{{ data.labels.qrCode }}</span>
            <img
              :key="chosen.id"
              :src="chosen.qrCodeUrl"
              :alt="data.labels.qrCode"
              width="192"
              height="192"
              class="block border border-gray-200 dark:border-gray-700 rounded"
            />
            <a :href="chosen.qrCodeDownloadUrl" download class="link-default inline-block">
              {{ data.labels.download }}
            </a>
          </div>
        </template>
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
 * "Delen" on an e-learning (KOM-95): pick an organization, and its link and QR code are right
 * there to copy and to download. Every organization the course is shared with arrives with the
 * action's answer, so choosing one only changes which is shown; with just one, it is chosen
 * already. Everything it shows — the words included — comes from the action.
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

  data() {
    const only = this.data.organizations.length === 1 ? this.data.organizations[0].id : ''

    return { organizationId: only }
  },

  computed: {
    chosen() {
      return this.data.organizations.find(organization => organization.id === this.organizationId) ?? null
    },
  },

  methods: {
    async copy() {
      try {
        await navigator.clipboard.writeText(this.chosen.link)
        Nova.success(this.data.labels.copied)
      } catch (error) {
        this.$refs.link.select()
        Nova.error(this.data.labels.copyFailed)
      }
    },
  },
}
</script>
