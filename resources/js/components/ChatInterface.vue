<template>
  <div class="flex flex-col h-full">
    <div ref="messagesContainer" class="flex-1 overflow-y-auto space-y-4 p-4">
      <div v-if="messages.length === 0 && !isLoading" class="text-center text-gray-500 py-12">
        <p class="text-lg">Ask a question about your documents</p>
      </div>

      <div v-for="msg in messages" :key="msg.id" :class="['flex', msg.role === 'user' ? 'justify-end' : 'justify-start']">
        <div :class="['max-w-2xl rounded-lg px-4 py-3', msg.role === 'user' ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200']">
          <p class="text-sm whitespace-pre-wrap">{{ msg.content }}</p>
          <div v-if="msg.sources && msg.sources.length > 0" class="mt-2 pt-2 border-t border-gray-200">
            <p class="text-xs font-medium text-gray-500 mb-1">Sources:</p>
            <div v-for="(source, idx) in msg.sources" :key="idx" class="text-xs text-gray-400 mb-1">
              <span class="font-medium">{{ source.document_title }}</span>
              <span v-if="source.page_number"> — p.{{ source.page_number }}</span>
              <span> ({{ Math.round(source.similarity_score * 100) }}%)</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="isStreaming && messages.length > 0" class="flex justify-start">
        <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
          <span class="inline-block w-2 h-4 bg-blue-600 animate-pulse"></span>
        </div>
      </div>

      <div v-if="error" class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
        {{ error }}
      </div>
    </div>

    <div class="border-t border-gray-200 p-4 bg-white">
      <form @submit.prevent="handleSubmit" class="flex gap-2">
        <input
          v-model="question"
          type="text"
          placeholder="Ask a question..."
          class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          :disabled="isLoading || isStreaming"
          maxlength="1000"
        />
        <button
          v-if="isStreaming"
          type="button"
          @click="store.abortStream()"
          class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700"
        >
          Stop
        </button>
        <button
          v-else
          type="submit"
          :disabled="isLoading || !question.trim()"
          class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          Send
        </button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, nextTick, watch } from 'vue'
import { useChatStore } from '../stores/chatStore'
import { storeToRefs } from 'pinia'

const store = useChatStore()
const { messages, isLoading, isStreaming, error } = storeToRefs(store)
const question = ref('')
const messagesContainer = ref<HTMLElement | null>(null)

watch(messages, async () => {
  await nextTick()
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}, { deep: true })

async function handleSubmit() {
  if (!question.value.trim() || isLoading.value) return
  const q = question.value
  question.value = ''
  await store.sendMessage(q)
}
</script>
