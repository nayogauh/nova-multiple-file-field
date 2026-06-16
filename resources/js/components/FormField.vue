<template>
  <DefaultField
    :field="field"
    :errors="errors"
    :show-help-text="showHelpText"
    :full-width-content="true"
  >
    <template #field>
      <!-- Drop zone -->
      <div
        class="rounded-lg border-2 border-dashed transition-colors"
        :class="[
          isDragging
            ? 'border-primary-400 bg-primary-50 dark:bg-primary-900/20'
            : 'border-gray-200 dark:border-gray-700',
          isFull ? 'opacity-60' : 'cursor-pointer',
        ]"
        @dragover.prevent="onDragOver"
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop"
        @click="!isFull && openFilePicker()"
      >
        <div class="flex flex-col items-center justify-center px-4 py-6 text-center">
          <p class="text-sm text-gray-600 dark:text-gray-400">
            <span class="font-bold text-primary-500">{{ __('Click to choose') }}</span>
            {{ __('or drag & drop files here') }}
          </p>
          <p v-if="hint" class="mt-1 text-xs text-gray-400">{{ hint }}</p>
        </div>

        <input
          ref="fileInput"
          type="file"
          class="hidden"
          multiple
          :accept="field.acceptedTypes || undefined"
          @change="onFileInput"
        />
      </div>

      <!-- File list -->
      <ul v-if="allFiles.length" class="mt-3 space-y-2">
        <li
          v-for="(file, index) in allFiles"
          :key="file.key"
          class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
        >
          <div class="flex min-w-0 items-center space-x-3">
            <img
              v-if="file.preview"
              :src="file.preview"
              class="h-10 w-10 flex-shrink-0 rounded object-cover"
              alt=""
            />
            <span
              v-else
              class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded bg-gray-100 text-xs font-bold uppercase text-gray-500 dark:bg-gray-800"
            >
              {{ file.ext }}
            </span>
            <div class="min-w-0">
              <a
                v-if="file.url"
                :href="file.url"
                target="_blank"
                rel="noopener"
                class="block truncate text-sm font-medium text-primary-500 hover:underline"
                @click.stop
              >{{ file.name }}</a>
              <span v-else class="block truncate text-sm font-medium">{{ file.name }}</span>
              <span class="text-xs text-gray-400">
                {{ formatSize(file.size) }}
                <span v-if="file.isNew" class="ml-1 text-green-500">· {{ __('new') }}</span>
              </span>
            </div>
          </div>

          <button
            type="button"
            class="ml-3 text-gray-400 transition-colors hover:text-red-500"
            :title="__('Remove')"
            @click.stop="removeFile(index)"
          >
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
          </button>
        </li>
      </ul>
    </template>
  </DefaultField>
</template>

<script>
let uid = 0

export default {
  // Props provided by Nova to every form field component.
  props: [
    'resourceName',
    'resourceId',
    'resourceRelationshipName',
    'field',
    'errors',
    'mode',
    'viaResource',
    'viaResourceId',
    'viaRelationship',
  ],

  data() {
    return {
      existingFiles: [],
      newFiles: [],
      isDragging: false,
    }
  },

  created() {
    this.setInitialValue()
  },

  mounted() {
    // Nova fills the outgoing FormData by calling `field.fill(formData)` on the
    // field's metadata object (see CreateForm/UpdateForm), NOT on this component
    // instance. So we must register our fill on `this.field` for it to run —
    // this is exactly what Nova's `FormField` mixin does in its mounted() hook.
    // Without it, our staged files are never appended and the server receives an
    // empty value (triggering a spurious "required" error).
    this.field.fill = this.fill
  },

  beforeUnmount() {
    // Release object URLs created for image previews.
    this.newFiles.forEach(f => f.preview && URL.revokeObjectURL(f.preview))
  },

  computed: {
    showHelpText() {
      return this.field.helpText && this.field.helpText.length > 0
    },

    allFiles() {
      return [...this.existingFiles, ...this.newFiles]
    },

    isFull() {
      const max = this.field.maxFiles
      return max != null && this.allFiles.length >= max
    },

    hint() {
      const parts = []
      if (this.field.acceptedTypes) parts.push(this.field.acceptedTypes)
      if (this.field.maxFiles) parts.push(this.__(':n max', { n: this.field.maxFiles }))
      if (this.field.maxFileSize) parts.push(this.formatSize(this.field.maxFileSize * 1024) + ' / file')
      return parts.join(' · ')
    },
  },

  methods: {
    /*
     * Build the local file lists from the value resolved by the PHP field.
     */
    setInitialValue() {
      const value = this.field.value || []
      this.existingFiles = (Array.isArray(value) ? value : []).map(f => ({
        key: `existing-${uid++}`,
        name: f.name,
        path: f.path,
        size: f.size,
        url: f.url,
        ext: this.extOf(f.name),
        preview: f.isImage ? f.url : null,
        isNew: false,
      }))
    },

    /*
     * Called by Nova when the form is submitted. Appends our payload to formData.
     */
    fill(formData) {
      this.newFiles.forEach(f => {
        formData.append(`${this.field.attribute}[]`, f.file, f.name)
      })

      // Tell the server which already-stored files must be kept.
      const keep = this.existingFiles.map(f => f.path)
      formData.append(`${this.field.attribute}__keep`, JSON.stringify(keep))
    },

    openFilePicker() {
      this.$refs.fileInput.click()
    },

    onFileInput(e) {
      this.addFiles(e.target.files)
      e.target.value = ''
    },

    onDragOver() {
      if (!this.isFull) this.isDragging = true
    },

    onDragLeave() {
      this.isDragging = false
    },

    onDrop(e) {
      this.isDragging = false
      if (!this.isFull) this.addFiles(e.dataTransfer.files)
    },

    addFiles(fileList) {
      const max = this.field.maxFiles
      const maxSize = this.field.maxFileSize

      Array.from(fileList).forEach(file => {
        if (max != null && this.allFiles.length >= max) {
          Nova.error(this.__('You may not attach more than :n files.', { n: max }))
          return
        }
        if (maxSize != null && file.size > maxSize * 1024) {
          Nova.error(this.__(':name exceeds the :n KB limit.', { name: file.name, n: maxSize }))
          return
        }

        this.newFiles.push({
          key: `new-${uid++}`,
          file,
          name: file.name,
          size: file.size,
          url: null,
          ext: this.extOf(file.name),
          preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
          isNew: true,
        })
      })
    },

    removeFile(index) {
      const existingCount = this.existingFiles.length
      if (index < existingCount) {
        this.existingFiles.splice(index, 1)
      } else {
        const removed = this.newFiles.splice(index - existingCount, 1)[0]
        if (removed && removed.preview) URL.revokeObjectURL(removed.preview)
      }
    },

    extOf(name) {
      const parts = (name || '').split('.')
      return parts.length > 1 ? parts.pop().toLowerCase() : 'file'
    },

    formatSize(bytes) {
      if (bytes == null) return ''
      const units = ['B', 'KB', 'MB', 'GB']
      let i = 0
      let value = bytes
      while (value >= 1024 && i < units.length - 1) {
        value /= 1024
        i++
      }
      return `${value.toFixed(value < 10 && i > 0 ? 1 : 0)} ${units[i]}`
    },
  },
}
</script>
