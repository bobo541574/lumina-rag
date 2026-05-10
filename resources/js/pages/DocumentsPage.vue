<template>
  <div>
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Documents</h2>
    <DocumentUpload />
    <DocumentList :documents="documents" @delete="handleDelete" />
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useDocumentStore } from '../stores/documentStore'
import { storeToRefs } from 'pinia'
import DocumentUpload from '../components/DocumentUpload.vue'
import DocumentList from '../components/DocumentList.vue'

const store = useDocumentStore()
const { documents } = storeToRefs(store)

onMounted(() => {
  store.fetchDocuments()
})

async function handleDelete(id: string) {
  await store.deleteDocument(id)
}
</script>
