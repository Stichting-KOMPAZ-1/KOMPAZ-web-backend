import FormField from './FormField.vue'

// Only a form component: the fields built on this are form-only, and Nova's own boolean group
// draws anything else.
Nova.booting(app => {
  app.component('form-checkbox-list', FormField)
})
