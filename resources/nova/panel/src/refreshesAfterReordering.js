/**
 * Makes a drag in a sortable table fetch the list again once the new order is saved.
 *
 * nova-sortable keeps its own copy of the rows while they are dragged and, after saving, never
 * reads them back — unlike its "move to start / end" buttons, which do. The first drag on a page
 * worked; a second one reported success and changed nothing until the page was reloaded, because
 * the table was no longer drawing what the server had. Reading the list back after every save is
 * what a reload did, and it also leaves the table showing what was actually stored.
 *
 * The request is the package's, word for word, to the paths this application answers itself
 * (NovaReorderController).
 */
export default function refreshesAfterReordering(app) {
  const SortableTable = app.component('ResourceTable')

  if (!SortableTable) {
    return
  }

  app.component('ResourceTable', {
    extends: SortableTable,

    methods: {
      async updateOrder() {
        this.reorderLoading = true

        try {
          await Nova.request().post(
            `/nova-vendor/nova-sortable/sort/${this.resourceName}/update-order`,
            {
              resourceId: null,
              resourceIds: this.fakeResources.map(resource => resource.id.value),
              viaResource: this.viaResource,
              viaResourceId: this.viaResourceId,
              viaRelationship: this.viaRelationship,
              relationshipType: this.relationshipType,
              relatedResource: this.viaResource,
            }
          )

          Nova.success(this.__('novaSortable.reorderSuccessful'))
        } catch (error) {
          Nova.error(this.__('novaSortable.reorderError'))
        }

        await this.refreshResourcesList()

        this.reorderLoading = false
      },
    },
  })
}
