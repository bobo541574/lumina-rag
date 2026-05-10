<?php

declare(strict_types=1);

namespace Modules\ChatModule\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;

class ChatModuleSeeder extends Seeder
{
    public function run(): void
    {
        if (ChatSession::count() > 0) {
            return;
        }

        $admin = User::where('email', 'admin@lumina.test')->first();
        $sarah = User::where('email', 'sarah.chen@acmecorp.com')->first();

        $session1 = ChatSession::create([
            'user_id' => $sarah?->id,
            'title' => 'Q4 2025 revenue breakdown and margin analysis',
            'message_count' => 8,
            'last_activity_at' => now()->subMinutes(30),
        ]);

        $session2 = ChatSession::create([
            'user_id' => $admin?->id,
            'title' => 'How to deploy the RAG pipeline in a Kubernetes cluster',
            'message_count' => 6,
            'last_activity_at' => now()->subHours(3),
        ]);

        $session3 = ChatSession::create([
            'user_id' => $admin?->id,
            'title' => 'Product roadmap timeline for SSO and internationalization',
            'message_count' => 4,
            'last_activity_at' => now()->subDay(),
            'is_archived' => true,
        ]);

        $session4 = ChatSession::create([
            'user_id' => $sarah?->id,
            'title' => 'Comparing our gross margins to industry benchmarks',
            'message_count' => 6,
            'last_activity_at' => now()->subMinutes(15),
        ]);

        $time = now()->subMinutes(30);

        $messages = [
            // Session 1: Sarah asking about Q4 financials
            ['session_id' => $session1->id, 'role' => 'user', 'content' => 'What was our total revenue for Q4 2025 and how does it break down by segment?', 'token_count' => 18, 'sources' => null, 'created_at' => $time],
            ['session_id' => $session1->id, 'role' => 'assistant', 'content' => 'Q4 2025 total revenue reached $47.2 million, a 23% year-over-year increase. The breakdown is:\n\n- **Enterprise**: $28.5M (up 31% YoY) — strongest performer\n- **SMB**: $12.8M (up 12% YoY)\n- **Government**: $5.9M\n\nEnterprise growth was the primary driver, contributing 60% of total revenue.', 'token_count' => 72, 'sources' => [
                ['document_id' => 'doc-1', 'document_title' => 'Q4 2025 Financial Report', 'chunk_index' => 0, 'page_number' => 1, 'similarity_score' => 0.92, 'excerpt' => 'Q4 2025 revenue reached $47.2 million...'],
            ], 'created_at' => $time->copy()->addSeconds(3)],

            ['session_id' => $session1->id, 'role' => 'user', 'content' => 'How did our operating expenses change compared to last year?', 'token_count' => 12, 'sources' => null, 'created_at' => $time->copy()->addMinutes(2)],
            ['session_id' => $session1->id, 'role' => 'assistant', 'content' => 'Operating expenses for Q4 2025 totaled $32.1 million, up from $28.4 million in Q4 2024 (a 13% increase). Key drivers:\n\n- **R&D**: $8.7M — increased due to AI/ML team expansion\n- **Sales & Marketing**: $14.2M — reflects the new partner program launch\n\nThe increase is proportionate to revenue growth, and our operating margin remained healthy.', 'token_count' => 85, 'sources' => [
                ['document_id' => 'doc-1', 'document_title' => 'Q4 2025 Financial Report', 'chunk_index' => 1, 'page_number' => 3, 'similarity_score' => 0.89, 'excerpt' => 'Operating expenses for Q4 totaled $32.1 million...'],
            ], 'created_at' => $time->copy()->addMinutes(2)->addSeconds(4)],

            ['session_id' => $session1->id, 'role' => 'user', 'content' => 'What is our current cash position and how long will it last?', 'token_count' => 14, 'sources' => null, 'created_at' => $time->copy()->addMinutes(5)],
            ['session_id' => $session1->id, 'role' => 'assistant', 'content' => 'As of December 31, 2025, we have **$23.4 million** in cash and equivalents, up from $18.9 million at the end of Q3. The increase was driven by the $8 million Series B extension closed in November.\n\nAt current burn rates, we have approximately **18 months of runway**. We are targeting cash-flow positivity by **Q3 2026**.', 'token_count' => 78, 'sources' => [
                ['document_id' => 'doc-1', 'document_title' => 'Q4 2025 Financial Report', 'chunk_index' => 4, 'page_number' => 10, 'similarity_score' => 0.95, 'excerpt' => 'Cash and equivalents stood at $23.4 million...'],
            ], 'created_at' => $time->copy()->addMinutes(5)->addSeconds(5)],

            ['session_id' => $session1->id, 'role' => 'user', 'content' => 'How is gross margin trending and what is driving the improvement?', 'token_count' => 15, 'sources' => null, 'created_at' => $time->copy()->addMinutes(8)],
            ['session_id' => $session1->id, 'role' => 'assistant', 'content' => 'Gross margin improved to **72.4%** in Q4 2025, up from 68.1% in Q4 2024. The 4.3 percentage point improvement is primarily attributed to:\n\n1. Migration to ARM-based servers, reducing compute costs by ~18%\n2. Continued optimization of cloud infrastructure\n3. Higher-margin enterprise mix shifting the overall blend\n\nWe expect further margin expansion in Q1 2026 as the server migration completes.', 'token_count' => 95, 'sources' => [
                ['document_id' => 'doc-1', 'document_title' => 'Q4 2025 Financial Report', 'chunk_index' => 2, 'page_number' => 5, 'similarity_score' => 0.91, 'excerpt' => 'Gross margin improved to 72.4%, up from 68.1%...'],
            ], 'created_at' => $time->copy()->addMinutes(8)->addSeconds(4)],

            // Session 2: Admin asking about RAG deployment
            ['session_id' => $session2->id, 'role' => 'user', 'content' => 'How do I deploy the RAG pipeline in a Kubernetes cluster?', 'token_count' => 14, 'sources' => null, 'created_at' => $time->copy()->subHours(3)],
            ['session_id' => $session2->id, 'role' => 'assistant', 'content' => 'The RAG pipeline can be deployed in Kubernetes using the following architecture:\n\n1. **API gateway** (NGINX Ingress) routes to the Laravel app\n2. **PHP-FPM pods** handle HTTP requests (horizontal autoscaler)\n3. **Queue worker pods** process document jobs (separate deployment)\n4. **PostgreSQL with pgvector** as a StatefulSet\n5. **Redis** for cache and session management\n\nEach module can be scaled independently based on load.', 'token_count' => 102, 'sources' => [
                ['document_id' => 'doc-2', 'document_title' => 'RAG System Architecture Guide', 'chunk_index' => 0, 'page_number' => null, 'similarity_score' => 0.88, 'excerpt' => 'The Retrieval-Augmented Generation (RAG) pipeline consists of five core stages...'],
            ], 'created_at' => $time->copy()->subHours(3)->addSeconds(3)],

            ['session_id' => $session2->id, 'role' => 'user', 'content' => 'What are the resource requirements for each component?', 'token_count' => 12, 'sources' => null, 'created_at' => $time->copy()->subHours(3)->addMinutes(3)],
            ['session_id' => $session2->id, 'role' => 'assistant', 'content' => 'Recommended resource allocations:\n\n- **PHP-FPM pods**: 2 CPU, 2GB RAM (3-5 replicas per namespace)\n- **Queue workers**: 1 CPU, 1GB RAM (2-3 replicas for document processing)\n- **PostgreSQL**: 8 CPU, 32GB RAM with 100GB SSD (consider RDS or Cloud SQL for production)\n- **Redis**: 2 CPU, 4GB RAM (ElastiCache or Memorystore)\n- **Vite/Static assets**: Served via CDN, not from K8s\n\nFor the embedding service, ensure your pods have stable outbound internet access to api.openai.com.', 'token_count' => 115, 'sources' => [
                ['document_id' => 'doc-2', 'document_title' => 'RAG System Architecture Guide', 'chunk_index' => 1, 'page_number' => null, 'similarity_score' => 0.85, 'excerpt' => 'Documents are ingested through the DocumentModule...'],
            ], 'created_at' => $time->copy()->subHours(3)->addMinutes(3)->addSeconds(5)],

            ['session_id' => $session2->id, 'role' => 'user', 'content' => 'How does the embedding service scale with large document volumes?', 'token_count' => 14, 'sources' => null, 'created_at' => $time->copy()->subHours(2)->addMinutes(45)],
            ['session_id' => $session2->id, 'role' => 'assistant', 'content' => 'The embedding service processes batches of up to 100 chunks per API call. For large volumes:\n\n1. Chunks are queued through the job system\n2. Multiple queue workers can process batches in parallel\n3. MD5 caching prevents redundant API calls (24h TTL)\n4. Each 100-chunk batch takes approximately 2-5 seconds\n\nFor a 10,000-chunk document, expect ~5-10 minutes total processing time with 3 concurrent workers. The system automatically retries failures 3 times with exponential backoff.', 'token_count' => 108, 'sources' => [
                ['document_id' => 'doc-2', 'document_title' => 'RAG System Architecture Guide', 'chunk_index' => 2, 'page_number' => null, 'similarity_score' => 0.91, 'excerpt' => 'The embedding service uses OpenAI text-embedding-ada-002...'],
            ], 'created_at' => $time->copy()->subHours(2)->addMinutes(45)->addSeconds(4)],

            // Session 3: Product roadmap discussion (archived)
            ['session_id' => $session3->id, 'role' => 'user', 'content' => 'What are the key milestones for Q1 2026?', 'token_count' => 11, 'sources' => null, 'created_at' => $time->copy()->subDays(1)],
            ['session_id' => $session3->id, 'role' => 'assistant', 'content' => 'Q1 2026 has three major deliverables:\n\n1. **Self-service analytics dashboard** — real-time usage metrics with customizable reports\n2. **SOC 2 Type II certification** — complete the audit process\n3. **Mobile SDK v2.0** — with offline support and improved caching\n\nThe analytics dashboard includes anomaly detection powered by our ML pipeline, which will help customers identify unusual usage patterns proactively.', 'token_count' => 85, 'sources' => [
                ['document_id' => 'doc-3', 'document_title' => 'Product Roadmap 2026', 'chunk_index' => 0, 'page_number' => 1, 'similarity_score' => 0.93, 'excerpt' => 'Q1 2026 will focus on launching the self-service analytics dashboard...'],
            ], 'created_at' => $time->copy()->subDays(1)],

            ['session_id' => $session3->id, 'role' => 'user', 'content' => 'When is the SSO integration expected and which providers are supported?', 'token_count' => 15, 'sources' => null, 'created_at' => $time->copy()->subDays(1)->addMinutes(3)],
            ['session_id' => $session3->id, 'role' => 'assistant', 'content' => 'Enterprise SSO integration is planned for **Q2 2026**. Supported protocols:\n\n- **SAML 2.0**\n- **OpenID Connect (OIDC)**\n- **SCIM** for user provisioning\n\nWe are partnering with **Okta** and **Azure AD** for the initial rollout. Beta testing begins in March with 10 enterprise customers. This will be followed by advanced RBAC with custom role definitions.', 'token_count' => 82, 'sources' => [
                ['document_id' => 'doc-3', 'document_title' => 'Product Roadmap 2026', 'chunk_index' => 1, 'page_number' => 3, 'similarity_score' => 0.96, 'excerpt' => 'Q2 2026 introduces the enterprise SSO integration suite...'],
            ], 'created_at' => $time->copy()->subDays(1)->addMinutes(3)->addSeconds(3)],

            ['session_id' => $session3->id, 'role' => 'user', 'content' => 'What are the internationalization plans for H2 2026?', 'token_count' => 12, 'sources' => null, 'created_at' => $time->copy()->subDays(1)->addMinutes(6)],
            ['session_id' => $session3->id, 'role' => 'assistant', 'content' => 'H2 2026 internationalization plans include:\n\n1. **Multi-region deployment** support for data residency\n2. **GDPR compliance** enhancements\n3. **Japanese language** interface — our first non-English UI\n4. **Usage-based billing tiers** for flexible pricing\n5. **London office** opening in Q3 for EMEA operations\n\nThis expansion is driven by growing demand from European and Asian markets, which currently represent 22% of our pipeline.', 'token_count' => 95, 'sources' => [
                ['document_id' => 'doc-3', 'document_title' => 'Product Roadmap 2026', 'chunk_index' => 2, 'page_number' => 6, 'similarity_score' => 0.94, 'excerpt' => 'H2 2026 focuses on platform scalability and internationalization...'],
            ], 'created_at' => $time->copy()->subDays(1)->addMinutes(6)->addSeconds(4)],

            // Session 4: Sarah comparing margins (most recent)
            ['session_id' => $session4->id, 'role' => 'user', 'content' => 'How does our gross margin of 72.4% compare to industry benchmarks?', 'token_count' => 16, 'sources' => null, 'created_at' => $time->copy()->subMinutes(15)],
            ['session_id' => $session4->id, 'role' => 'assistant', 'content' => 'Our gross margin of **72.4%** compares favorably to industry benchmarks:\n\n- **SaaS median**: ~65-70%\n- **Infrastructure SaaS**: ~60-65%\n- **Enterprise SaaS top quartile**: ~75%+\n\nWe are slightly above the SaaS median and approaching top-quartile territory. The improvement from 68.1% last year shows our infrastructure optimization efforts are paying off, particularly the ARM-based server migration that reduced compute costs by 18%.', 'token_count' => 98, 'sources' => [
                ['document_id' => 'doc-1', 'document_title' => 'Q4 2025 Financial Report', 'chunk_index' => 2, 'page_number' => 5, 'similarity_score' => 0.87, 'excerpt' => 'Gross margin improved to 72.4%, up from 68.1%...'],
            ], 'created_at' => $time->copy()->subMinutes(15)],

            ['session_id' => $session4->id, 'role' => 'user', 'content' => 'What is driving our customer acquisition cost down?', 'token_count' => 12, 'sources' => null, 'created_at' => $time->copy()->subMinutes(12)],
            ['session_id' => $session4->id, 'role' => 'assistant', 'content' => 'Customer acquisition cost (CAC) decreased to **$4,200** from $5,100 in Q4 2024 — an 18% improvement. Key drivers:\n\n1. **Self-serve onboarding flow**: Improved conversion rates reduce sales touch\n2. **Shortened enterprise sales cycle**: 72 days vs 95 days previously\n3. **Net revenue retention of 118%**: Existing customers expand, reducing the need for new acquisition\n\nThe combination of lower CAC and high retention creates a strong unit economics profile for the business.', 'token_count' => 105, 'sources' => [
                ['document_id' => 'doc-1', 'document_title' => 'Q4 2025 Financial Report', 'chunk_index' => 3, 'page_number' => 7, 'similarity_score' => 0.90, 'excerpt' => 'Customer acquisition cost (CAC) decreased to $4,200...'],
            ], 'created_at' => $time->copy()->subMinutes(12)->addSeconds(4)],

            ['session_id' => $session4->id, 'role' => 'user', 'content' => 'Should we be concerned about the R&D spending increase?', 'token_count' => 13, 'sources' => null, 'created_at' => $time->copy()->subMinutes(8)],
            ['session_id' => $session4->id, 'role' => 'assistant', 'content' => 'The R&D increase to $8.7M (from $6.9M in Q4 2024) reflects deliberate investment in our AI/ML capabilities, not cost bloat. Context:\n\n- R&D as a percentage of revenue is **18.4%**, within the healthy range of 15-20% for growth-stage SaaS\n- These investments directly enable our ML-powered features (summarization, anomaly detection)\n- The expanded team is building capabilities that will drive the H2 2026 roadmap items\n\nThis is a strategic investment, and the ROI should materialize as new features drive enterprise deal sizes upward.', 'token_count' => 112, 'sources' => [
                ['document_id' => 'doc-1', 'document_title' => 'Q4 2025 Financial Report', 'chunk_index' => 1, 'page_number' => 3, 'similarity_score' => 0.82, 'excerpt' => 'Operating expenses for Q4 totaled $32.1 million...'],
            ], 'created_at' => $time->copy()->subMinutes(8)->addSeconds(5)],
        ];

        foreach ($messages as $msg) {
            ChatMessage::create($msg);
        }
    }
}
