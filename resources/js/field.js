import '../css/field.css'

import IndexField from './components/IndexField.vue'
import DetailField from './components/DetailField.vue'
import FormField from './components/FormField.vue'

Nova.booting((app, store) => {
  app.component('index-multiple-file', IndexField)
  app.component('detail-multiple-file', DetailField)
  app.component('form-multiple-file', FormField)
})
