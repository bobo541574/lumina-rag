<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;

class DocumentModuleSeeder extends Seeder
{
    public function run(): void
    {
        if (Document::count() > 0) {
            return;
        }

        $admin = User::where('email', 'admin@lumina.test')->first();

        $doc1 = Document::create([
            'user_id' => $admin?->id,
            'title' => 'Q4 2025 Financial Report',
            'original_filename' => 'Q4_2025_Financial_Report.pdf',
            'file_path' => 'documents/q4_2025_financial_report.pdf',
            'file_size' => 2_456_789,
            'page_count' => 12,
            'mime_type' => 'application/pdf',
            'file_hash' => md5('q4_2025_financial_report'),
            'status' => 'completed',
            'chunks_count' => 5,
            'processed_at' => now()->subHours(2),
        ]);

        $doc2 = Document::create([
            'user_id' => $admin?->id,
            'title' => 'RAG System Architecture Guide',
            'original_filename' => 'rag_architecture.md',
            'file_path' => 'documents/rag_architecture.md',
            'file_size' => 34_567,
            'page_count' => null,
            'mime_type' => 'text/markdown',
            'file_hash' => md5('rag_architecture_guide'),
            'status' => 'completed',
            'chunks_count' => 4,
            'processed_at' => now()->subDay(),
        ]);

        $doc3 = Document::create([
            'user_id' => $admin?->id,
            'title' => 'Product Roadmap 2026',
            'original_filename' => 'roadmap_2026.docx',
            'file_path' => 'documents/roadmap_2026.docx',
            'file_size' => 892_123,
            'page_count' => 8,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_hash' => md5('product_roadmap_2026'),
            'status' => 'completed',
            'chunks_count' => 3,
            'processed_at' => now()->subHours(12),
        ]);

        Document::create([
            'user_id' => $admin?->id,
            'title' => 'Machine Learning Best Practices',
            'original_filename' => 'ml_best_practices.pdf',
            'file_path' => 'documents/ml_best_practices.pdf',
            'file_size' => 5_234_890,
            'page_count' => 47,
            'mime_type' => 'application/pdf',
            'file_hash' => md5('ml_best_practices_v2'),
            'status' => 'processing',
            'chunks_count' => 0,
        ]);

        Document::create([
            'user_id' => $admin?->id,
            'title' => 'Legacy System Migration Notes',
            'original_filename' => 'migration_notes.txt',
            'file_path' => 'documents/migration_notes.txt',
            'file_size' => 12_345,
            'page_count' => null,
            'mime_type' => 'text/plain',
            'file_hash' => md5('legacy_migration_notes'),
            'status' => 'failed',
            'chunks_count' => 0,
            'error_message' => 'Text extraction failed: file appears to be empty after preamble',
        ]);

        $chunks = [
            [
                'document_id' => $doc1->id,
                'content' => 'Q4 2025 revenue reached $47.2 million, representing a 23% year-over-year increase. Growth was driven primarily by the enterprise segment, which contributed $28.5 million, up 31% from Q4 2024. The SMB segment grew 12% to $12.8 million, while government contracts added $5.9 million.',
                'chunk_index' => 0,
                'page_number' => 1,
                'char_start' => 0,
                'char_end' => 282,
                'token_count' => 65,
                'metadata' => ['section' => 'Executive Summary', 'quarter' => 'Q4 2025'],
            ],
            [
                'document_id' => $doc1->id,
                'content' => 'Operating expenses for Q4 totaled $32.1 million, compared to $28.4 million in the same period last year. R&D spending increased to $8.7 million as we expanded our AI/ML engineering team. Sales and marketing expenses were $14.2 million, reflecting the launch of our new partner program.',
                'chunk_index' => 1,
                'page_number' => 3,
                'char_start' => 283,
                'char_end' => 561,
                'token_count' => 72,
                'metadata' => ['section' => 'Operating Expenses', 'quarter' => 'Q4 2025'],
            ],
            [
                'document_id' => $doc1->id,
                'content' => 'Gross margin improved to 72.4%, up from 68.1% in Q4 2024. The improvement is attributed to lower cloud infrastructure costs following the migration to ARM-based servers, which reduced compute expenses by approximately 18%. We expect further optimization in Q1 2026 as we complete the migration.',
                'chunk_index' => 2,
                'page_number' => 5,
                'char_start' => 562,
                'char_end' => 850,
                'token_count' => 68,
                'metadata' => ['section' => 'Gross Margin Analysis', 'quarter' => 'Q4 2025'],
            ],
            [
                'document_id' => $doc1->id,
                'content' => 'Customer acquisition cost (CAC) decreased to $4,200 from $5,100 in Q4 2024, driven by improved conversion rates from our self-serve onboarding flow. The enterprise sales cycle shortened from 95 to 72 days on average. Net revenue retention remained strong at 118%, indicating high customer satisfaction.',
                'chunk_index' => 3,
                'page_number' => 7,
                'char_start' => 851,
                'char_end' => 1145,
                'token_count' => 75,
                'metadata' => ['section' => 'Sales Efficiency', 'quarter' => 'Q4 2025'],
            ],
            [
                'document_id' => $doc1->id,
                'content' => 'Cash and equivalents stood at $23.4 million as of December 31, 2025, compared to $18.9 million at the end of Q3. The increase reflects the $8 million Series B extension closed in November. We project 18 months of runway at current burn rates, with a target of achieving cash-flow positive by Q3 2026.',
                'chunk_index' => 4,
                'page_number' => 10,
                'char_start' => 1146,
                'char_end' => 1435,
                'token_count' => 70,
                'metadata' => ['section' => 'Cash Position', 'quarter' => 'Q4 2025'],
            ],
            [
                'document_id' => $doc2->id,
                'content' => 'The Retrieval-Augmented Generation (RAG) pipeline consists of five core stages: document ingestion, text chunking, embedding generation, vector search, and LLM completion. Each stage is implemented as a modular service with a clearly defined interface, allowing individual components to be swapped or upgraded independently.',
                'chunk_index' => 0,
                'page_number' => null,
                'char_start' => 0,
                'char_end' => 310,
                'token_count' => 68,
                'metadata' => ['section' => 'Pipeline Overview'],
            ],
            [
                'document_id' => $doc2->id,
                'content' => 'Documents are ingested through the DocumentModule, which handles file validation (MIME type checking, size limits, SHA-256 deduplication), text extraction via format-specific parsers, and recursive character text splitting. The chunking algorithm prioritizes natural boundaries: paragraphs first, then sentences, then punctuation, falling back to character-level splitting only when necessary.',
                'chunk_index' => 1,
                'page_number' => null,
                'char_start' => 311,
                'char_end' => 662,
                'token_count' => 80,
                'metadata' => ['section' => 'Document Ingestion'],
            ],
            [
                'document_id' => $doc2->id,
                'content' => 'The embedding service uses OpenAI text-embedding-ada-002 to convert text chunks into 1536-dimensional vectors. Batches of up to 100 chunks are processed in a single API call for efficiency. Results are cached using MD5 content hashes with a 24-hour TTL, preventing redundant API calls when documents are re-processed.',
                'chunk_index' => 2,
                'page_number' => null,
                'char_start' => 663,
                'char_end' => 973,
                'token_count' => 68,
                'metadata' => ['section' => 'Embedding Generation'],
            ],
            [
                'document_id' => $doc2->id,
                'content' => 'Vector search is performed using PostgreSQL pgvector with cosine distance. The system indexes embeddings using IVFFlat with 100 lists for approximate nearest neighbor search. Results are filtered by a similarity threshold of 0.65, and the top 5 chunks are passed to the LLM along with the original question for answer generation.',
                'chunk_index' => 3,
                'page_number' => null,
                'char_start' => 974,
                'char_end' => 1280,
                'token_count' => 72,
                'metadata' => ['section' => 'Search and Retrieval'],
            ],
            [
                'document_id' => $doc3->id,
                'content' => 'Q1 2026 will focus on launching the self-service analytics dashboard, completing the SOC 2 Type II certification, and releasing the mobile SDK v2.0. The analytics dashboard will provide real-time usage metrics, customizable reports, and anomaly detection powered by our ML pipeline.',
                'chunk_index' => 0,
                'page_number' => 1,
                'char_start' => 0,
                'char_end' => 270,
                'token_count' => 58,
                'metadata' => ['section' => 'Q1 2026 Milestones', 'quarter' => 'Q1 2026'],
            ],
            [
                'document_id' => $doc3->id,
                'content' => 'Q2 2026 introduces the enterprise SSO integration suite (SAML, OIDC, SCIM), advanced RBAC with custom role definitions, and the initial release of our AI-powered document summarization feature. We are partnering with Okta and Azure AD for the SSO rollout. Beta testing begins in March with 10 enterprise customers.',
                'chunk_index' => 1,
                'page_number' => 3,
                'char_start' => 271,
                'char_end' => 568,
                'token_count' => 75,
                'metadata' => ['section' => 'Q2 2026 Milestones', 'quarter' => 'Q2 2026'],
            ],
            [
                'document_id' => $doc3->id,
                'content' => 'H2 2026 focuses on platform scalability and internationalization. Key initiatives include multi-region deployment support, GDPR compliance enhancements, a Japanese language interface, and the introduction of usage-based billing tiers. We plan to open a London office in Q3 to support the EMEA expansion.',
                'chunk_index' => 2,
                'page_number' => 6,
                'char_start' => 569,
                'char_end' => 844,
                'token_count' => 65,
                'metadata' => ['section' => 'H2 2026 Initiatives', 'quarter' => 'H2 2026'],
            ],
        ];

        foreach ($chunks as $chunk) {
            DocumentChunk::create($chunk);
        }
    }
}
