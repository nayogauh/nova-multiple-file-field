<template>
  <PanelItem :index="index" :field="field">
    <template #value>
      <ul v-if="files.length" class="space-y-2">
        <li
          v-for="(file, i) in files"
          :key="i"
          class="flex items-center space-x-3 rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
        >
          <img
            v-if="file.isImage && file.url"
            :src="file.url"
            class="h-10 w-10 flex-shrink-0 rounded object-cover"
            alt=""
          />
          <span
            v-else
            class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded bg-gray-100 text-xs font-bold uppercase text-gray-500 dark:bg-gray-800"
          >
            {{ extOf(file.name) }}
          </span>

          <div class="min-w-0 flex-1">
            <a
              v-if="file.url"
              :href="file.url"
              target="_blank"
              rel="noopener"
              class="block truncate text-sm font-medium text-primary-500 hover:underline"
            >{{ file.name }}</a>
            <span v-else class="block truncate text-sm font-medium">{{ file.name }}</span>
            <span class="text-xs text-gray-400">{{ formatSize(file.size) }}</span>
          </div>

          <a
            v-if="file.url"
            :href="file.url"
            :download="file.name"
            class="text-gray-400 transition-colors hover:text-primary-500"
            :title="__('Download')"
          >
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </a>
        </li>
      </ul>

      <p v-else class="text-sm text-gray-400">&mdash;</p>
    </template>
  </PanelItem>
</template>

<script>
export default {
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],

  computed: {
    files() {
      const value = this.field.value || []
      return Array.isArray(value) ? value : []
    },
  },

  methods: {
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
