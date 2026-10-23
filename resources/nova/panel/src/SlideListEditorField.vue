<template>
  <DefaultField
    :field="currentField"
    :errors="errors"
    :show-help-text="showHelpText"
    :full-width-content="true"
  >
    <template #field>
      <!--
        `contain: inline-size` keeps this as wide as the form and no wider. Trix's toolbar does not
        wrap, and without it the slides took the toolbar's width and stuck out of their block.
      -->
      <div class="space-y-4" :dusk="fieldAttribute" style="contain: inline-size">
        <div
          v-for="(slide, index) in slides"
          :key="slide.key"
          class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-3"
        >
          <div class="flex items-center gap-2">
            <!-- The reader sees "2/5" beside a slide; the operator sees the same count here. -->
            <span class="font-bold text-gray-500">
              {{ currentField.slideLabel }} {{ index + 1 }} / {{ slides.length }}
            </span>

            <div class="ml-auto flex items-center gap-1">
              <!-- Faded inline when there is nowhere to move: Nova's CSS has no `disabled:` classes. -->
              <button
                type="button"
                class="px-2 text-gray-400"
                :style="{ opacity: index === 0 ? 0.3 : 1 }"
                :title="currentField.moveUpLabel"
                :aria-label="currentField.moveUpLabel"
                :disabled="isReadonly || index === 0"
                @click="move(index, -1)"
              >
                ↑
              </button>
              <button
                type="button"
                class="px-2 text-gray-400"
                :style="{ opacity: index === slides.length - 1 ? 0.3 : 1 }"
                :title="currentField.moveDownLabel"
                :aria-label="currentField.moveDownLabel"
                :disabled="isReadonly || index === slides.length - 1"
                @click="move(index, 1)"
              >
                ↓
              </button>
              <button
                type="button"
                class="px-2 text-gray-400 hover:text-red-500"
                :title="currentField.removeSlideLabel"
                :aria-label="currentField.removeSlideLabel"
                :disabled="isReadonly"
                @click="removeSlide(index)"
              >
                ×
              </button>
            </div>
          </div>

          <!--
            Picture first, then the title, then the body: the order the reader sees them in, so the
            form reads like the slide it builds. The picture can be replaced, never taken away,
            because a slide is the picture with words beside it; a picture chosen and not yet saved
            can be undone.

            Sized with inline styles, not classes: the panel's stylesheet is Nova's, which carries
            only the Tailwind classes Nova itself uses, and `h-16 w-24 object-cover` are not among
            them — so the thumbnail was drawn at full size and pushed the button off the screen.
          -->
          <div class="flex items-center" style="gap: 0.75rem">
            <img
              v-if="slide.previewUrl || slide.imageUrl"
              :src="slide.previewUrl || slide.imageUrl"
              :alt="currentField.imageLabel"
              class="rounded"
              :style="thumbnailStyle"
            />
            <span
              v-else
              class="flex items-center justify-center rounded border border-dashed border-gray-300 dark:border-gray-600 text-xs text-gray-400"
              :style="thumbnailStyle"
            >
              {{ currentField.noImageLabel }}
            </span>

            <div class="space-y-1" style="min-width: 0">
              <p class="text-sm">{{ currentField.imageLabel }} <span class="text-red-500">*</span></p>

              <div class="flex items-center" style="gap: 0.5rem; flex-wrap: wrap">
                <Button
                  variant="outline"
                  size="small"
                  :disabled="isReadonly"
                  @click="openPicker(slide)"
                >
                  {{ slide.imageUrl || slide.file ? currentField.replaceImageLabel : currentField.chooseImageLabel }}
                </Button>

                <button
                  v-if="slide.file"
                  type="button"
                  class="text-sm text-gray-500 underline"
                  :disabled="isReadonly"
                  @click="undoChoice(slide)"
                >
                  {{ currentField.undoImageLabel }}
                </button>
              </div>

              <p v-if="slide.file" class="text-xs text-gray-500 truncate">{{ slide.file.name }}</p>
            </div>

            <!-- The browser's own control says "Choose file" in English; the button above opens it. -->
            <input
              :ref="element => (pickers[slide.key] = element)"
              type="file"
              accept="image/*"
              class="hidden"
              :aria-label="`${currentField.imageLabel} ${index + 1}`"
              :disabled="isReadonly"
              @change="chooseImage(slide, $event)"
            />
          </div>

          <input
            v-model="slide.title"
            type="text"
            class="form-control form-input form-control-bordered w-full"
            :maxlength="currentField.maximumTitleLength"
            :placeholder="`${currentField.titlePlaceholder} *`"
            :aria-label="`${currentField.titlePlaceholder} ${index + 1}`"
            :disabled="isReadonly"
          />

          <!--
            Nova's own Trix, the editor every other prose field in the panel uses
            (App\Nova\Fields\RichText). Attachments stay off: a picture belongs in the slide's own
            picture, a row that can be found again, not in markup (rules 12 and 13). No `name`, so
            Trix's hidden input submits nothing; the body travels in this field's JSON list.
          -->
          <Trix
            :value="slide.body"
            :placeholder="currentField.bodyPlaceholder"
            :with-files="false"
            :disabled="isReadonly"
            @change="body => (slide.body = body)"
          />
        </div>

        <p v-if="slides.length === 0" class="text-gray-500">{{ currentField.emptyLabel }}</p>

        <Button
          variant="ghost"
          size="small"
          :disabled="isReadonly || slides.length >= currentField.maximumSlides"
          @click="addSlide"
        >
          {{ currentField.addSlideLabel }}
        </Button>
      </div>
    </template>
  </DefaultField>
</template>

<script>
import { Button } from 'laravel-nova-ui'
import { FormField, HandlesValidationErrors } from 'laravel-nova'

/**
 * A carousel's slides (KOM-100): a picture, a title and rich text per slide, in the order the
 * reader pages through them, with arrows to change that order.
 *
 * The list is sent as JSON under this field's own attribute, and each chosen picture beside it as a
 * file under the key the field hands over (`picturePrefix` and the position) — a picture cannot
 * travel inside a JSON string, and a key with a dot in it is read as a nested one. A saved
 * slide sends its `id`, so the row is kept rather than replaced, and its picture is kept unless a
 * new one is chosen. Untouched — nothing typed or chosen in any slide — the field sends nothing, so
 * a "Carrousel" added with "+ blok" and then left alone is dropped like any empty block.
 */
export default {
  components: { Button },

  mixins: [HandlesValidationErrors, FormField],

  data: () => ({
    slides: [],
    // Only for Vue to tell rows apart while they move; an index would hand one slide's editor to
    // the next one.
    nextKey: 0,
    // Each slide's hidden file input, by its key, so its button can open it.
    pickers: {},
    // The size and crop a slide's picture has in the frontend's carousel, 260 by 232, so an operator
    // sees here what a reader will see. Never wider than the form on a narrow screen.
    thumbnailStyle: { width: '260px', maxWidth: '100%', aspectRatio: '260 / 232', objectFit: 'cover', flexShrink: 0 },
  }),

  methods: {
    setInitialValue() {
      const value = this.currentField.value

      this.slides = Array.isArray(value)
        ? value.map(slide => this.row(slide.id ?? null, slide.title ?? '', slide.body ?? '', slide.imageUrl ?? null))
        : []

      if (this.slides.length === 0) {
        this.addSlide()
      }
    },

    fill(formData) {
      if (this.isBlank()) {
        return
      }

      formData.append(
        this.fieldAttribute,
        JSON.stringify(this.slides.map(({ id, title, body }) => ({ id, title, body })))
      )

      this.slides.forEach((slide, index) => {
        if (slide.file) {
          formData.append(`${this.currentField.picturePrefix}${index}`, slide.file)
        }
      })
    },

    isBlank() {
      return this.slides.every(
        slide =>
          String(slide.title ?? '').trim() === '' &&
          String(slide.body ?? '').replace(/<[^>]*>/g, '').trim() === '' &&
          !slide.file &&
          !slide.imageUrl
      )
    },

    row(id, title, body, imageUrl) {
      return { key: this.nextKey++, id, title, body, imageUrl, file: null, previewUrl: null }
    },

    addSlide() {
      if (this.slides.length >= this.currentField.maximumSlides) {
        return
      }

      this.slides.push(this.row(null, '', '', null))
    },

    removeSlide(index) {
      const [slide] = this.slides.splice(index, 1)

      this.forgetPreview(slide)
    },

    move(index, direction) {
      const target = index + direction

      if (target < 0 || target >= this.slides.length) {
        return
      }

      const [slide] = this.slides.splice(index, 1)
      this.slides.splice(target, 0, slide)
    },

    openPicker(slide) {
      this.pickers[slide.key]?.click()
    },

    chooseImage(slide, event) {
      const file = event.target.files?.[0] ?? null

      // Cancelling the browser's dialog keeps what was there rather than emptying the slide.
      if (!file) {
        return
      }

      this.forgetPreview(slide)
      slide.file = file
      slide.previewUrl = URL.createObjectURL(file)
    },

    /** Back to the picture the slide had when the form opened, or to none for a new slide. */
    undoChoice(slide) {
      this.forgetPreview(slide)
      slide.file = null

      const picker = this.pickers[slide.key]

      if (picker) {
        picker.value = ''
      }
    },

    forgetPreview(slide) {
      if (slide.previewUrl) {
        URL.revokeObjectURL(slide.previewUrl)
      }

      slide.previewUrl = null
    },
  },
}
</script>
