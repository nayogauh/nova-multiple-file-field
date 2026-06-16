<template>
  <div class="flex items-center space-x-1">
    <template v-if="files.length">
      <svg class="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd" />
      </svg>
      <span class="whitespace-nowrap text-sm">
        {{ files.length }} {{ files.length > 1 ? __('files') : __('file') }}
      </span>
    </template>
    <span v-else class="text-gray-400">&mdash;</span>
  </div>
</template>

<script>
export default {
  props: ['resourceName', 'field'],

  computed: {
    files() {
      const value = this.field.value || []
      return Array.isArray(value) ? value : []
    },
  },
}
</script>
