<template>
  <Modal :show="show" @close-via-escape="$emit('close')" role="dialog" size="md">
    <div class="kompaz-share bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden">
      <header class="kompaz-share__header">
        <div>
          <h2 class="kompaz-share__title">{{ data.title }}</h2>
          <p class="kompaz-share__intro">{{ data.labels.intro }}</p>
        </div>
        <button
          type="button"
          class="kompaz-share__close"
          :aria-label="data.labels.close"
          @click="$emit('close')"
        >
          <Icon name="x-mark" />
        </button>
      </header>

      <div class="kompaz-share__body">
        <div class="kompaz-share__group">
          <label class="kompaz-share__label" for="share-e-learning-organization">
            {{ data.labels.organization }}
          </label>
          <select
            id="share-e-learning-organization"
            v-model="organizationId"
            class="kompaz-share__select"
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
          <div class="kompaz-share__group">
            <label class="kompaz-share__label" for="share-e-learning-link">{{ data.labels.link }}</label>
            <textarea
              id="share-e-learning-link"
              ref="link"
              rows="2"
              readonly
              :value="chosen.link"
              class="kompaz-share__link"
              @focus="$event.target.select()"
            />
            <button type="button" class="kompaz-share__button" @click="copy">
              <Icon name="clipboard-document" />
              {{ data.labels.copy }}
            </button>
          </div>

          <div class="kompaz-share__group">
            <span class="kompaz-share__label">{{ data.labels.qrCode }}</span>
            <img
              :key="chosen.id"
              :src="chosen.qrCodeUrl"
              :alt="data.labels.qrCode"
              width="128"
              height="128"
              class="kompaz-share__qr"
            />
            <a :href="chosen.qrCodeDownloadUrl" download class="kompaz-share__button">
              <Icon name="arrow-down-tray" />
              {{ data.labels.download }}
            </a>
          </div>
        </template>
      </div>
    </div>
  </Modal>
</template>

<script>
import { Icon } from 'laravel-nova-ui'

/**
 * "Delen" on an e-learning (KOM-95): pick an organization, and its link and QR code are right
 * there to copy and to download. Every organization the course is shared with arrives with the
 * action's answer, so choosing one only changes which is shown; with just one, it is chosen
 * already. Everything it shows — the words included — comes from the action.
 *
 * Drawn in KOMPAZ's own colours rather than Nova's blue, as the design has it; the classes are in
 * `resources/nova/panel/panel.css`, scoped to this dialog so the rest of the panel is untouched.
 *
 * Copying asks the browser's clipboard, which only a secure page may use; where that is refused,
 * the link is selected in its field instead and the operator is told to copy it themselves.
 */
export default {
  components: { Icon },

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
