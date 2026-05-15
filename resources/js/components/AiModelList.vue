<template>
  <div class="bg-white border border-surface-200 rounded-card overflow-hidden">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 bg-surface-50 border-b border-surface-200">
      <h3 class="text-sm font-semibold text-surface-700 uppercase tracking-wider">AI Models</h3>
    </div>

    <!-- Skeleton (initial fetch) -->
    <div v-if="loading && models.length === 0" aria-busy="true" aria-label="Loading AI models">
      <div v-for="g in 2" :key="g" class="px-4 py-3 border-b border-surface-100 last:border-0">
        <div class="h-3 w-32 bg-surface-200 rounded animate-pulse mb-2" />
        <div v-for="i in 2" :key="i" class="py-2 flex items-center justify-between gap-4">
          <div class="space-y-1.5 flex-1">
            <div class="h-3.5 bg-surface-200 rounded animate-pulse" :style="{ maxWidth: `${40 + ((i * 11) % 30)}%` }" />
            <div class="h-2.5 w-40 bg-surface-100 rounded animate-pulse" />
          </div>
          <div class="flex items-center gap-1">
            <div class="h-7 w-12 bg-surface-200 rounded-lg animate-pulse" />
            <div class="h-7 w-14 bg-surface-200 rounded-lg animate-pulse" />
          </div>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <AppEmptyState
      v-else-if="models.length === 0"
      icon="info"
      title="No AI models configured"
      description="Add an embedding or LLM model to start using the system."
    />

    <!-- Model groups -->
    <template v-else>
      <section
        v-for="group in groups"
        :key="group.key"
        class="px-4 py-3 border-b border-surface-100 last:border-0"
      >
        <h4 class="text-xs font-semibold text-surface-500 uppercase mb-2">{{ group.title }}</h4>

        <p v-if="group.models.length === 0" class="text-xs text-surface-400 py-2">
          {{ group.emptyText }}
        </p>

        <div
          v-for="m in group.models"
          :key="m.id"
          class="py-2 border-b border-surface-50 last:border-0"
        >
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div class="min-w-0">
              <span class="text-sm font-medium text-surface-900">{{ m.name }}</span>
              <span class="text-xs text-surface-500 ml-2">{{ m.provider }} / {{ m.model }}</span>
              <span v-if="m.dimensions" class="text-xs text-surface-400 ml-1">
                ({{ m.dimensions }}d)
              </span>
              <AppBadge v-if="m.is_active" variant="success" size="xs" class="ml-2">active</AppBadge>
            </div>
            <div class="flex gap-2 flex-shrink-0">
              <AppButton
                variant="ghost"
                size="sm"
                :aria-label="`Edit ${m.name}`"
                @click="$emit('edit', m.id)"
              >
                Edit
              </AppButton>
              <AppButton
                variant="danger"
                size="sm"
                :aria-label="`Delete ${m.name}`"
                @click="$emit('delete', m.id)"
              >
                Delete
              </AppButton>
            </div>
          </div>
          <!-- Description rendered as HTML (Trix output). Sanitize server-side. -->
          <div v-if="m.description" class="mt-1 ml-1 text-xs text-surface-500" v-html="m.description" />
        </div>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import AppButton from './ui/AppButton.vue'
import AppBadge from './ui/AppBadge.vue'
import AppEmptyState from './ui/AppEmptyState.vue'
import type { AiModel } from '../types'

const props = withDefaults(defineProps<{
  models: AiModel[]
  loading?: boolean
}>(), {
  loading: false,
})

defineEmits<{
  edit: [id: string]
  delete: [id: string]
}>()

const groups = computed(() => [
  {
    key: 'embedding',
    title: 'Embedding Models',
    emptyText: 'No embedding models configured',
    models: props.models.filter((m) => m.type === 'embedding'),
  },
  {
    key: 'llm',
    title: 'LLM Models',
    emptyText: 'No LLM models configured',
    models: props.models.filter((m) => m.type === 'llm'),
  },
])
</script>
