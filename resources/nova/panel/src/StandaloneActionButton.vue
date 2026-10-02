<template>
  <!-- Anything but the one case this exists for is Nova's own control, untouched. -->
  <!-- Its slots too: a row's menu brings its own ellipsis as `trigger`, and a detail page its
       Replicate and Delete items as `menu`. -->
  <component :is="original" v-if="!asButton" v-bind="$props" @actionExecuted="emitExecuted">
    <template v-for="(_, name) in $slots" #[name]="slotProps">
      <slot :name="name" v-bind="slotProps || {}" />
    </template>
  </component>

  <div v-else>
    <component
      v-if="modalVisible"
      :is="action.component"
      :show="modalVisible"
      class="text-left"
      :working="working"
      :selected-resources="selectedResources"
      :resource-name="resourceName"
      :action="action"
      :errors="errors"
      @confirm="run"
      @close="close"
    />

    <Button
      variant="solid"
      :dusk="triggerDuskAttribute"
      :loading="working"
      @click.stop="open"
    >
      {{ action.name }}
    </Button>
  </div>
</template>

<script>
import { Button } from 'laravel-nova-ui'
import { Errors, Localization } from 'laravel-nova'
import { originalActionDropdown } from './novaActionDropdown'

/**
 * The create control on a listing whose create goes through a use case.
 *
 * Those listings have exactly one standalone action, and Nova puts every standalone action behind
 * an ellipsis dropdown. With one action in it that reads as a button that opens a menu repeating
 * its own label — and it sits beside Modules and E-learnings, which Nova gives a real create
 * button because they are written by its own form. This renders the real button instead: Nova's
 * own Button, labelled from the action, opening the action's own modal.
 *
 * The request is Nova's, restated, because the composable that makes it (`useActions`) is reachable
 * only from inside Nova's own build. Two parts of it matter and are the reason this is not simply
 * a click-through to the hidden menu item: a refusal arrives as a 422 whose `errors` go back into
 * the open dialog — which is what `refusalField` on the PHP side exists to produce — and the modal
 * stays open while that is read. Closing on a refusal is the bug this shape avoids.
 */
export default {
  components: { Button },

  mixins: [Localization],

  emits: ['actionExecuted'],

  props: {
    resource: {},
    resourceName: {},
    viaResource: {},
    viaResourceId: {},
    viaRelationship: {},
    relationshipType: {},
    actions: { type: Array, default: () => [] },
    selectedResources: { type: [Array, String], default: () => [] },
    endpoint: { type: String, default: null },
    triggerDuskAttribute: { type: String, default: null },
    showHeadings: { type: Boolean, default: false },
  },

  data: () => ({
    modalVisible: false,
    working: false,
    errors: new Errors(),
  }),

  computed: {
    original: () => originalActionDropdown(),

    action() {
      return this.actions[0]
    },

    /**
     * One standalone action, above a listing. A second one is a menu again: two buttons competing
     * for the same corner is the thing a dropdown is actually for.
     */
    asButton() {
      return (
        this.triggerDuskAttribute === 'index-standalone-action-dropdown' &&
        this.actions.length === 1 &&
        this.action?.authorizedToRun !== false
      )
    },
  },

  methods: {
    open() {
      this.errors = new Errors()

      if (this.action.withoutConfirmation) {
        this.run()

        return
      }

      this.modalVisible = true
    },

    close() {
      this.modalVisible = false
    },

    emitExecuted() {
      this.$emit('actionExecuted')
    },

    run() {
      this.working = true
      Nova.$progress.start()

      Nova.request({
        method: 'post',
        url: this.endpoint || `/nova-api/${this.resourceName}/action`,
        params: {
          action: this.action.uriKey,
          pivotAction: false,
          viaResource: this.viaResource,
          viaResourceId: this.viaResourceId,
          viaRelationship: this.viaRelationship,
        },
        data: this.formData(),
      })
        .then(response => {
          this.close()
          this.report(response.data)
          this.emitExecuted()
          Nova.$emit('action-executed')
        })
        .catch(error => {
          // Left open on purpose, carrying the field errors: a refusal about something the operator
          // typed is answered under the input they typed it in.
          if (error.response && error.response.status >= 400 && error.response.status < 500) {
            this.errors = new Errors(error.response.data.errors)
            Nova.error(this.__('There was a problem executing the action.'))
          }
        })
        .finally(() => {
          this.working = false
          Nova.$progress.done()
        })
    },

    formData() {
      const formData = new FormData()

      if (this.selectedResources === 'all') {
        formData.append('resources', 'all')
      } else {
        this.selectedResources.forEach(resource =>
          formData.append(
            'resources[]',
            typeof resource === 'object' ? resource.id.value : resource
          )
        )
      }

      this.action.fields.forEach(field => field.fill(formData))

      return formData
    },

    report(data) {
      if (data?.danger) {
        Nova.error(data.danger)

        return
      }

      Nova.success(data?.message || this.__('The action was executed successfully.'))
    },
  },
}
</script>
