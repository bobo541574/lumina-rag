<template>
  <div class="flex gap-6 h-[calc(100vh-12rem)]">
    <div class="w-72 flex-shrink-0 bg-white border border-gray-200 rounded-lg overflow-hidden">
      <div class="p-4 border-b border-gray-200">
        <button @click="startNewChat" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
          New Chat
        </button>
      </div>
      <div class="overflow-y-auto p-2" style="max-height: calc(100% - 65px)">
        <SessionList
          :sessions="sessions"
          :activeId="currentSessionId"
          @select="selectSession"
          @delete="deleteSession"
        />
      </div>
    </div>
    <div class="flex-1 bg-white border border-gray-200 rounded-lg overflow-hidden flex flex-col">
      <ChatInterface />
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, computed } from 'vue'
import { useChatStore } from '../stores/chatStore'
import { storeToRefs } from 'pinia'
import ChatInterface from '../components/ChatInterface.vue'
import SessionList from '../components/SessionList.vue'

const store = useChatStore()
const { sessions, currentSessionId } = storeToRefs(store)

onMounted(() => {
  store.fetchSessions()
})

async function selectSession(id: string) {
  await store.fetchSession(id)
}

async function deleteSession(id: string) {
  store.deleteSession(id)
}

function startNewChat() {
  store.startNewChat()
}
</script>
