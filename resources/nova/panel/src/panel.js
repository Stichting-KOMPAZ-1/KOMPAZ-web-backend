import CheckboxListField from './CheckboxListField.vue'
import refreshesAfterReordering from './refreshesAfterReordering'

// Everything this application adds to Nova's frontend, in one script. It is registered after
// nova-sortable's in the Nova service provider, so the table that package installs is already
// there to be extended.
Nova.booting(app => {
  app.component('form-checkbox-list', CheckboxListField)

  refreshesAfterReordering(app)
})
