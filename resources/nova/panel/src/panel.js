import CheckboxListField from './CheckboxListField.vue'
import VideoUploadField from './VideoUploadField.vue'
import VideoUploadDetailField from './VideoUploadDetailField.vue'
import VideoPreviewField from './VideoPreviewField.vue'
import VideoPreviewDetailField from './VideoPreviewDetailField.vue'
import StandaloneActionButton from './StandaloneActionButton.vue'
import { rememberActionDropdown } from './novaActionDropdown'
import refreshesAfterReordering from './refreshesAfterReordering'

// Everything this application adds to Nova's frontend, in one script. It is registered after
// nova-sortable's in the Nova service provider, so the table that package installs is already
// there to be extended.
Nova.booting(app => {
  app.component('form-checkbox-list', CheckboxListField)
  app.component('form-video-upload', VideoUploadField)
  app.component('detail-video-upload', VideoUploadDetailField)
  app.component('form-video-preview', VideoPreviewField)
  app.component('detail-video-preview', VideoPreviewDetailField)

  // The create control on the listings Nova does not give a create button: one standalone action
  // behind an ellipsis reads as a button that opens a menu repeating itself. Registered over Nova's
  // own component, which it hands back to for every other use of a dropdown.
  rememberActionDropdown(app.component('ActionDropdown'))
  app.component('ActionDropdown', StandaloneActionButton)

  refreshesAfterReordering(app)
})
