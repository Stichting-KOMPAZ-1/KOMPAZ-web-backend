<template>
  <DefaultField
    :field="currentField"
    :errors="errors"
    :show-help-text="showHelpText"
    :full-width-content="true"
  >
    <template #field>
      <div class="space-y-4" :dusk="fieldAttribute">
        <div
          v-for="(instruction, index) in instructions"
          :key="`instruction-${index}`"
          class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-2"
        >
          <!--
            Picture first, then the heading, then the text: the order the reader sees them in, so
            the form reads like the card it builds rather than like the columns behind it.
          -->
          <div class="flex items-center gap-3">
            <img
              v-if="instruction.imageUrl && !instruction.removeImage"
              :src="instruction.imageUrl"
              :alt="currentField.imageLabel"
              class="h-16 w-16 object-cover rounded"
            />

            <input
              type="file"
              accept="image/*"
              class="text-sm"
              :aria-label="`${currentField.imageLabel} ${index + 1}`"
              :disabled="isReadonly"
              @change="chooseImage(index, $event)"
            />

            <button
              v-if="(instruction.imageUrl || instruction.file) && !instruction.removeImage"
              type="button"
              class="text-sm text-gray-400 hover:text-red-500"
              :disabled="isReadonly"
              @click="removeImage(index)"
            >
              {{ currentField.removeImageLabel }}
            </button>
          </div>

          <div class="flex items-center gap-2">
            <!-- The number the reader sees is the position, so it follows the list rather than being typed. -->
            <span class="shrink-0 font-bold text-gray-500">{{ index + 1 }}.</span>

            <input
              v-model="instruction.title"
              type="text"
              class="form-control form-input form-control-bordered w-full"
              :maxlength="currentField.maximumTitleLength"
              :placeholder="currentField.titlePlaceholder"
              :aria-label="`${currentField.titlePlaceholder} ${index + 1}`"
              :disabled="isReadonly"
            />

            <button
              type="button"
              class="text-gray-400 hover:text-red-500"
              :title="currentField.removeInstructionLabel"
              :aria-label="currentField.removeInstructionLabel"
              :disabled="isReadonly"
              @click="removeInstruction(index)"
            >
              ×
            </button>
          </div>

          <!--
            Nova's own Trix, the editor every other prose field in the panel uses
            (App\Nova\Fields\RichText), rather than a second one of our own. It is registered
            globally by name, so it is reachable from here without importing trix again.

            Attachments stay off for the reason RichText states: a picture inside learning content
            is a row that can be found again — an image block, or this instruction's own picture —
            not a file buried in markup with nothing pointing at it (rules 12 and 13).

            No `name`, so the hidden input Trix renders submits nothing of its own; the body travels
            in this field's JSON list like every other value here.
          -->
          <Trix
            :value="instruction.body"
            :placeholder="currentField.bodyPlaceholder"
            :with-files="false"
            :disabled="isReadonly"
            @change="body => (instruction.body = body)"
          />
        </div>

        <p v-if="instructions.length === 0" class="text-gray-500">{{ currentField.emptyLabel }}</p>

        <Button
          variant="ghost"
          size="small"
          :disabled="isReadonly || instructions.length >= currentField.maximumInstructions"
          @click="addInstruction"
        >
          {{ currentField.addInstructionLabel }}
        </Button>
      </div>
    </template>
  </DefaultField>
</template>

<script>
import { Button } from 'laravel-nova-ui'
import { FormField, HandlesValidationErrors } from 'laravel-nova'

/**
 * A "Stap" block's instructions (KOM-99): a picture, a heading and rich text per instruction, in
 * the order the reader sees them.
 *
 * The list is sent as JSON under this field's own attribute, and each chosen picture beside it as a
 * file at `<attribute>_images.<position>` — a picture cannot travel inside a JSON string, and this
 * keeps the two halves at paths either side can work out without taking the other's apart.
 *
 * An instruction already saved sends its `id`, so the row is kept rather than replaced, and its
 * picture is kept unless a new one is chosen or it is taken away on purpose. Untouched — nothing
 * typed in any instruction — the field sends nothing, so a "Stap" added with "+ blok" and then left
 * alone is dropped like any empty block rather than refused.
 */
export default {
  components: { Button },

  mixins: [HandlesValidationErrors, FormField],

  data: () => ({
    instructions: [],
  }),

  methods: {
    setInitialValue() {
      const value = this.currentField.value

      this.instructions = Array.isArray(value)
        ? value.map(instruction => ({
            id: instruction.id ?? null,
            title: instruction.title ?? '',
            body: instruction.body ?? '',
            imageUrl: instruction.imageUrl ?? null,
            file: null,
            removeImage: false,
          }))
        : []

      if (this.instructions.length === 0) {
        this.addInstruction()
      }
    },

    fill(formData) {
      if (this.isBlank()) {
        return
      }

      formData.append(
        this.fieldAttribute,
        JSON.stringify(
          this.instructions.map(instruction => ({
            id: instruction.id,
            title: instruction.title,
            body: instruction.body,
            removeImage: instruction.removeImage,
          }))
        )
      )

      this.instructions.forEach((instruction, index) => {
        if (instruction.file) {
          formData.append(`${this.fieldAttribute}_images.${index}`, instruction.file)
        }
      })
    },

    isBlank() {
      return this.instructions.every(
        instruction =>
          String(instruction.title ?? '').trim() === '' &&
          String(instruction.body ?? '').trim() === '' &&
          !instruction.file &&
          !instruction.imageUrl
      )
    },

    addInstruction() {
      if (this.instructions.length >= this.currentField.maximumInstructions) {
        return
      }

      this.instructions.push({ id: null, title: '', body: '', imageUrl: null, file: null, removeImage: false })
    },

    removeInstruction(index) {
      this.instructions.splice(index, 1)
    },

    chooseImage(index, event) {
      const file = event.target.files?.[0] ?? null

      this.instructions[index].file = file
      // Choosing one is the opposite of taking one away, whichever happened first.
      this.instructions[index].removeImage = false
    },

    removeImage(index) {
      this.instructions[index].file = null
      this.instructions[index].removeImage = true
    },
  },
}
</script>
