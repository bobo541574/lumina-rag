<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\DocumentModule\Services\TextChunkingService;
use Modules\SettingsModule\Models\AiModel;

class ReportDemoSeeder extends Seeder
{
    private const BATCH_SIZE = 500;

    private const CHUNK_SIZE = 1000;

    private const CHUNK_OVERLAP = 200;

    public function run(): void
    {
        $this->createDemoUsers();

        $startTime = microtime(true);
        $this->command?->info('Generating report data...');

        $chunker = app(TextChunkingService::class);
        $users = User::all()->keyBy('id');
        $assignments = $this->getAssignments();
        $templates = $this->getContentTemplates();
        $today = now()->startOfDay();
        $startDate = Carbon::parse('2023-01-02');

        $embeddingModelId = null;
        $embeddingModel = null;
        try {
            $activeEmbedding = AiModel::where('type', 'embedding')
                ->where('provider', 'ollama')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();
            if ($activeEmbedding === null) {
                $activeEmbedding = AiModel::active()->embedding()->orderBy('sort_order')->first();
            }
            if ($activeEmbedding !== null) {
                $embeddingModelId = $activeEmbedding->id;
                $embeddingModel = $activeEmbedding->model;
            }
        } catch (\Throwable) {
            // DB not ready
        }

        $allDocRecords = [];
        $allChunkRecords = [];
        $totalDocs = 0;
        $totalChunks = 0;

        $reportTypes = ['daily', 'weekly', 'monthly', 'quarterly'];
        $typeWeights = [0.50, 0.30, 0.15, 0.05];

        foreach ($assignments as $assign) {
            $user = $users->get($assign['user_id']);
            if ($user === null) {
                continue;
            }

            foreach ($assign['projects'] as $projectName) {
                $projectTemplates = $templates[$projectName] ?? $templates['__default__'];

                for ($d = $startDate->copy(); $d->lte($today); $d->addDay()) {
                    if ($d->isWeekend()) {
                        continue;
                    }

                    $numReports = random_int(1, 3);
                    for ($r = 0; $r < $numReports; $r++) {
                        // Pick type by weight
                        $type = $this->weightedRandom($reportTypes, $typeWeights);
                        $typeTemplates = $projectTemplates[$type] ?? $projectTemplates['weekly'];

                        // Pick 3-8 templates for longer content that splits into multiple chunks
                        $numTemplates = random_int(3, 8);
                        $paragraphs = [];
                        for ($i = 0; $i < $numTemplates; $i++) {
                            $template = $typeTemplates[array_rand($typeTemplates)];
                            $paragraphs[] = $this->fillTemplate($template, $user, $projectName, $d);
                        }
                        $body = implode("\n\n", $paragraphs);

                        // Chunk the body, then prepend metadata to each chunk
                        $chunks = $chunker->chunk($body, self::CHUNK_SIZE, self::CHUNK_OVERLAP);
                        $header = "Report by: {$user->name}\nProject: {$projectName}\nDate: {$d->format('Y-m-d')}\n\n";
                        $numChunks = count($chunks);

                        $docId = (string) Str::ulid();
                        $title = $this->makeTitle($user, $projectName, $d, $type);
                        $allDocRecords[] = [
                            'id' => $docId,
                            'user_id' => $user->id,
                            'title' => $title,
                            'project' => $projectName,
                            'report_date' => $d->format('Y-m-d'),
                            'original_filename' => Str::slug($title).'-'.Str::lower(Str::random(6)).'.md',
                            'file_path' => 'demo/'.Str::slug($title).'-'.Str::lower(Str::random(6)).'.md',
                            'file_size' => random_int(5_000, 80_000),
                            'mime_type' => 'text/markdown',
                            'file_hash' => md5($title.$d->format('Y-m-d').$user->id.random_int(0, PHP_INT_MAX)),
                            'status' => 'completed',
                            'embedding_model_id' => $embeddingModelId,
                            'embedding_model' => $embeddingModel,
                            'chunks_count' => $numChunks,
                            'processed_at' => $d->copy()->addHours(random_int(1, 12))->format('Y-m-d H:i:s'),
                            'created_at' => $d->format('Y-m-d H:i:s'),
                            'updated_at' => $d->format('Y-m-d H:i:s'),
                        ];
                        $totalDocs++;

                        foreach ($chunks as $i => $chunk) {
                            $prefixed = $header.$chunk['content'];
                            $allChunkRecords[] = [
                                'id' => (string) Str::ulid(),
                                'document_id' => $docId,
                                'content' => $prefixed,
                                'chunk_index' => $i,
                                'page_number' => $chunk['page_number'] ?? null,
                                'char_start' => $chunk['char_start'],
                                'char_end' => $chunk['char_end'],
                                'token_count' => (int) ceil(mb_strlen($prefixed) / 4),
                                'metadata' => json_encode([
                                    'project' => $projectName,
                                    'user' => $user->name,
                                    'report_date' => $d->format('Y-m-d'),
                                ]),
                                'created_at' => $d->format('Y-m-d H:i:s'),
                            ];
                        }
                        $totalChunks += $numChunks;

                        if (count($allDocRecords) >= self::BATCH_SIZE) {
                            $this->flushBatch($allDocRecords, $allChunkRecords);
                            $allDocRecords = [];
                            $allChunkRecords = [];
                        }
                    }
                }
            }
        }

        if ($allDocRecords !== []) {
            $this->flushBatch($allDocRecords, $allChunkRecords);
        }

        DB::statement("UPDATE document_chunks SET tsv_content = to_tsvector('english', content) WHERE tsv_content IS NULL");
        $ftsCount = DB::selectOne('SELECT count(*) as cnt FROM document_chunks WHERE tsv_content IS NOT NULL')->cnt;

        $elapsed = round(microtime(true) - $startTime, 2);
        $this->command?->info("Done — {$totalDocs} documents, {$totalChunks} chunks, {$ftsCount} with FTS in {$elapsed}s");
    }

    private function weightedRandom(array $items, array $weights): string
    {
        $rand = mt_rand() / mt_getrandmax();
        $cumulative = 0.0;
        foreach ($items as $i => $item) {
            $cumulative += $weights[$i];
            if ($rand <= $cumulative) {
                return $item;
            }
        }

        return $items[0];
    }

    private function makeTitle(User $user, string $project, Carbon $date, string $type): string
    {
        $prefix = match ($type) {
            'daily' => 'Daily Report',
            'weekly' => 'Weekly Report',
            'monthly' => 'Monthly Report',
            'quarterly' => 'Quarterly Review',
            default => 'Report',
        };

        return "{$project} — {$prefix} ({$date->format('M d, Y')})";
    }

    private function fillTemplate(string $template, User $user, string $project, Carbon $date): string
    {
        $replacements = [
            '{user_name}' => $user->name,
            '{project}' => $project,
            '{date}' => $date->format('Y-m-d'),
            '{date_display}' => $date->format('F j, Y'),
            '{month}' => $date->format('F'),
            '{year}' => $date->format('Y'),
            '{quarter}' => 'Q'.(int) (($date->month - 1) / 3 + 1).' '.$date->format('Y'),
            '{team}' => $this->random(['engineering', 'product', 'data science', 'infrastructure', 'research', 'design', 'QA', 'security']),
            '{metric_pct}' => (string) random_int(50, 99),
            '{metric_num}' => (string) random_int(100, 9999),
            '{metric_k}' => (string) random_int(10, 999),
            '{metric_ms}' => (string) random_int(20, 950),
            '{change_pct}' => (string) random_int(5, 80),
            '{change_dir}' => $this->random(['increase', 'decrease', 'improvement']),
            '{bug_count}' => (string) random_int(2, 47),
            '{sprint_points}' => (string) random_int(12, 32),
            '{dollars}' => '$'.(string) random_int(10, 500).'K',
            '{hours}' => (string) random_int(2, 48),
        ];

        return strtr($template, $replacements);
    }

    private function random(array $arr): string
    {
        return $arr[array_rand($arr)];
    }

    private function cleanupDemoData(): void
    {
        $this->command?->info('Cleaning up demo data...');
        $demoDocIds = DB::table('documents')
            ->where('file_path', 'like', 'demo/%')
            ->pluck('id');

        if ($demoDocIds->isNotEmpty()) {
            $chunkCount = DB::table('document_chunks')
                ->whereIn('document_id', $demoDocIds)
                ->delete();
            $docCount = DB::table('documents')
                ->whereIn('id', $demoDocIds)
                ->delete();
            $this->command?->info("Deleted {$docCount} demo documents and {$chunkCount} chunks.");
        }
    }

    private function createDemoUsers(): void
    {
        $users = [
            [
                'name' => 'Admin User',
                'email' => 'admin@lumina.test',
                'password' => Hash::make('password123'),
            ],
            [
                'name' => 'Sarah Chen',
                'email' => 'sarah.chen@acmecorp.com',
                'password' => Hash::make('securePass1'),
            ],
            [
                'name' => 'Marcus Johnson',
                'email' => 'marcus.j@startup.io',
                'password' => Hash::make('welcome2026'),
            ],
            [
                'name' => 'Elena Rodriguez',
                'email' => 'elena.r@datawise.ai',
                'password' => Hash::make('Password!23'),
            ],
            [
                'name' => 'James Okafor',
                'email' => 'james.okafor@enterprise.co',
                'password' => Hash::make('J0urn3y!'),
            ],
            [
                'name' => 'အောင်ဇေယျာ (Aung Zeya)',
                'email' => 'aung.zeya@myanmar.dev',
                'password' => Hash::make('demo1234'),
            ],
            [
                'name' => 'နေဝင်းအောင် (Nay Win Aung)',
                'email' => 'nay.win.aung@myanmar.dev',
                'password' => Hash::make('demo1234'),
            ],
            [
                'name' => 'ခင်မြမြ (Khin Myat)',
                'email' => 'khin.myat@myanmar.dev',
                'password' => Hash::make('demo1234'),
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                [
                    ...$user,
                    'api_token' => bin2hex(random_bytes(40)),
                ],
            );
        }
    }

    private function getAssignments(): array
    {
        $users = User::whereIn('email', [
            'admin@lumina.test',
            'sarah.chen@acmecorp.com',
            'marcus.j@startup.io',
            'elena.r@datawise.ai',
            'james.okafor@enterprise.co',
            'aung.zeya@myanmar.dev',
            'nay.win.aung@myanmar.dev',
            'khin.myat@myanmar.dev',
        ])->get()->keyBy('email');

        return [
            ['user_id' => $users['sarah.chen@acmecorp.com']?->id, 'projects' => ['Project Orion', 'Project Nova', 'Project Fusion']],
            ['user_id' => $users['marcus.j@startup.io']?->id, 'projects' => ['Project Apex', 'Project Helios', 'Project Fusion']],
            ['user_id' => $users['elena.r@datawise.ai']?->id, 'projects' => ['Project Zenith', 'Project Nova', 'Project Orion']],
            ['user_id' => $users['james.okafor@enterprise.co']?->id, 'projects' => ['Project Nova', 'Project Apex', 'Project Quantum']],
            ['user_id' => $users['aung.zeya@myanmar.dev']?->id, 'projects' => ['Project Orion', 'Project Atlas', 'ဒစ်ဂျစ်တယ်အသွင်ပြောင်းလဲရေး', 'မြန်မာ့စီးပွားရေး']],
            ['user_id' => $users['nay.win.aung@myanmar.dev']?->id, 'projects' => ['Project Atlas', 'ကျေးလက်ဖွံ့ဖြိုးရေး', 'မြန်မာ့စီးပွားရေး']],
            ['user_id' => $users['khin.myat@myanmar.dev']?->id, 'projects' => ['Project Quantum', 'ပညာရေးစနစ်', 'Project Orion']],
            ['user_id' => $users['admin@lumina.test']?->id, 'projects' => ['Project Helios', 'Project Apex', 'Project Atlas']],
        ];
    }

    private function flushBatch(array $docRecords, array $chunkRecords): void
    {
        DB::transaction(function () use ($docRecords, $chunkRecords): void {
            foreach (array_chunk($docRecords, 200) as $batch) {
                DB::table('documents')->insert($batch);
            }
            foreach (array_chunk($chunkRecords, 500) as $batch) {
                $columns = implode(', ', array_keys($batch[0]));
                $values = [];
                $bindings = [];
                foreach ($batch as $row) {
                    $placeholders = [];
                    foreach ($row as $col => $val) {
                        if ($col === 'metadata' && is_string($val)) {
                            $placeholders[] = '?::jsonb';
                        } else {
                            $placeholders[] = '?';
                        }
                        $bindings[] = $val;
                    }
                    $values[] = '('.implode(', ', $placeholders).')';
                }
                DB::statement(
                    "INSERT INTO document_chunks ({$columns}) VALUES ".implode(', ', $values),
                    $bindings,
                );
            }
        });
    }

    private function getContentTemplates(): array
    {
        return [
            '__default__' => [
                'daily' => [
                    'Today the team focused on {project} deliverables. We made significant progress on the {team} backlog, completing {sprint_points} story points. Key achievement: reduced {metric_pct}% of technical debt in the core module. Blockers identified: {bug_count} issues pending review from the architecture board. Estimated completion by EOD {date_display}.',
                    'Daily standup highlights for {project}: {team} team shipped the {metric_num}th iteration of the recommendation pipeline. Performance metrics show {change_pct}% {change_dir} in query latency ({metric_ms}ms p95). A {bug_count}-bug triage session was conducted this morning. No critical P0 issues remain open.',
                    'Report for {date_display}: {project} pipeline is running smoothly. Processed {metric_k}K records with {metric_pct}% accuracy threshold. The {team} team identified a schema drift issue that affected {bug_count} partitions. Hotfix deployed to production. Post-mortem scheduled for tomorrow.',
                ],
                'weekly' => [
                    "This week at {project}: {sprint_points} story points completed across {team} squads. Major milestones achieved: completed the migration of legacy services, delivered {metric_num} new API endpoints, and closed {bug_count} bugs. Velocity is trending {change_dir} by {change_pct}% week-over-week. Next week's focus: performance optimization and documentation.",
                    'Weekly summary — {project} progress: The {team} team achieved {metric_pct}% code coverage (up {change_pct}% from last sprint). Deployed {metric_num} changes to production with zero incidents. Customer-reported issues decreased by {change_pct}%. On-call rotation handled {bug_count} alerts. Planning session for next sprint completed.',
                    "Key wins for {project} this week: (1) Completed Phase {random_int(1,4)} of the infrastructure overhaul, (2) Shipped {metric_num} feature flags to production, (3) Resolved {bug_count} support tickets. Risks identified: dependency on external API migration may impact next week's timeline. Mitigation plan in place.",
                    '{project} weekly health check — Uptime: {metric_pct}.{random_int(0,9)}%, Error budget: {random_int(60,99)}% remaining, P95 latency: {metric_ms}ms, Traffic: {metric_k}K requests/day. The {team} team is on track for the {date_display} release. Two production incidents this week: both resolved within SLA.',
                ],
                'monthly' => [
                    '{month} {year} — {project} monthly summary. Key metrics: Active users grew to {metric_k}K ({change_pct}% {change_dir} MoM). Revenue reached {dollars}, exceeding target by {change_pct}%. System processed {metric_num} transactions with {metric_pct}.{random_int(0,9)}% uptime. Customer acquisition cost decreased by {change_pct}% through improved conversion funnels.',
                    'Monthly report for {project}: The {team} team delivered {sprint_points} points across {random_int(2,4)} sprints. Shipped {metric_num} features to production. Resolved {bug_count} customer issues with average response time of {hours}h. Engineering headcount grew by {random_int(5,25)}% this quarter. Budget utilization at {metric_pct}%.',
                    '{project} {month} review — OKR progress:  {random_int(1,4)} objectives on track, {random_int(0,2)} behind schedule. KR1 (performance): achieved {metric_pct}% of target. KR2 (reliability): {metric_pct}.{random_int(0,9)}% uptime. KR3 (adoption): {metric_k}K new users. Focus for next month: closing the gap on behind-schedule items through resource reallocation.',
                ],
                'quarterly' => [
                    '{quarter} quarterly review for {project}. This quarter we achieved {metric_pct}% of our OKR targets. Major accomplishments: (1) Launched {random_int(2,5)} major features, (2) Expanded to {random_int(1,3)} new markets, (3) Achieved {metric_pct}% customer satisfaction score. Revenue grew {change_pct}% QoQ to {dollars}. Team grew from {random_int(5,15)} to {random_int(10,30)} members.',
                    'Q{random_int(1,4)} {year} review: {project} exceeded expectations in {random_int(2,4)} key areas. The {team} team delivered exceptional results with {metric_pct}% on-time delivery rate. Key challenges included scaling infrastructure for {metric_k}K concurrent users and migrating legacy data stores. Both successfully completed this quarter.',
                    'Strategic initiatives for {project} this quarter: (1) Platform modernization — {metric_pct}% complete, (2) AI/ML capability buildout — {metric_pct}% complete, (3) Global expansion — launched in {random_int(1,4)} new regions. Total investment: {dollars}. Expected ROI: {change_pct}% within 12 months. Risk register updated with {bug_count} new items.',
                ],
            ],

            'Project Orion' => [
                'daily' => [
                    'Orion daily: Team completed the API rate limiting implementation. All {metric_num} endpoints now enforce per-tenant limits with proper 429 responses. WebSocket reconnection bug fixed. Frontend deployed new dashboard to staging. Metrics dashboard shows p95 latency at {metric_ms}ms, well within the 500ms SLO. Load testing environment still down due to K8s cluster upgrade — blocked on infrastructure team.',
                    'Orion standup: Notification service migration from RabbitMQ to Kafka is {metric_pct}% complete. Migration script tested successfully on staging with {metric_k}K messages. Schema registry configured with Avro format. Team identified {bug_count} edge cases in the consumer offset management. Rollback plan documented and approved.',
                    'Orion daily progress: User management RBAC module completed and deployed to canary. Permissions sync runs in under {metric_ms}ms for {metric_k}K users. Testing revealed a race condition in concurrent role assignments — fix in progress. Documentation updated for the new admin API endpoints. E2E test suite expanded by {bug_count} new test cases.',
                ],
                'weekly' => [
                    'Orion weekly: Sprint {random_int(10,20)} delivered {sprint_points} of {random_int(18,25)} planned points ({metric_pct}% completion). Key deliveries: (1) Multi-region database migration, (2) Notification preferences UI, (3) Admin audit log export. Rollover items: rate limiter edge cases, batch user import. Velocity stable at {sprint_points} points. Team morale survey: 4.2/5.0.',
                    'Orion this week: Canary v2.1.0 at {random_int(10,50)}% traffic with zero errors. Event-driven architecture for notifications performing well — processing {metric_k}K events/day. Kafka migration ahead of schedule ({metric_pct}% done). Rollout plan: {random_int(25,50)}% tomorrow, {random_int(50,75)}% day after, full rollout by Friday. On-call rotation covered by {team} team.',
                ],
                'monthly' => [
                    'Orion {month} {year} monthly report: Active users reached {metric_k}K ({change_pct}% MoM growth). Revenue at {dollars} MRR. Platform processed {metric_num} API requests. Average response time: {metric_ms}ms (p95). Uptime: {metric_pct}.{random_int(0,9)}%. Top incidents this month: {bug_count} — all resolved within SLA. Feature adoption rate for v2.0: {metric_pct}% of user base.',
                    'Orion monthly review: Engineering velocity at {sprint_points} points/sprint. Shipped {random_int(2,5)} major features: real-time collaboration, search reindexing, mobile nav redesign. Bug closure rate: {metric_pct}% ({bug_count} closed, {random_int(3,12)} remaining). Customer NPS: {random_int(42,68)}. Team additions: {random_int(1,4)} new engineers onboarded.',
                ],
                'quarterly' => [
                    'Orion Q{random_int(1,4)} {year} review: All {random_int(3,5)} OKRs on track. Revenue target exceeded by {change_pct}% ({dollars} vs {dollars} plan). Customer count: {metric_k}K ({change_pct}% growth). Product milestones: v2.0 GA, v2.1 beta, mobile SDK v2 launch. Engineering metrics: deployment frequency increased {change_pct}%, MTTR reduced to {hours}h. Team: {random_int(15,30)} engineers across {random_int(3,5)} squads.',
                ],
            ],

            'Project Nova' => [
                'daily' => [
                    'Nova daily: Recommendation engine A/B test running with {random_int(5,15)}% traffic. Current results — CTR uplift: {change_pct}%, conversion uplift: {change_pct}%. Data pipeline migration from legacy ETL to Kafka streaming complete. Processing {metric_k}K events/min with {metric_ms}ms latency. Data quality: {metric_pct}.{random_int(0,9)}% accuracy. Dead letter queue: {bug_count} messages in last 24h.',
                    'Nova standup: GPU cluster provisioning for ML training complete — showing {random_int(2,4)}x speedup. Model training pipeline automated via GitHub Actions. TensorRT optimization testing continues — inconsistent results across GPU architectures. Fallback plan: ONNX Runtime if not resolved by Friday. Data labeling pipeline behind schedule by {random_int(5,14)} days due to vendor delays.',
                ],
                'weekly' => [
                    'Nova weekly: Model precision improved to {metric_pct}% (up from {random_int(70,85)}% last week). Collaborative filtering fine-tuned with content-based fallback for cold-start users. A/B test expanded to {random_int(10,25)}% traffic. User acceptance testing starts next week. Risk: TensorRT inconsistency across GPU architectures may require ONNX Runtime fallback. Data labeling {random_int(5,20)} days delayed.',
                    'Nova progress this week: {team} team completed the feature store implementation. Online feature serving latency: {metric_ms}ms p99. Offline batch pipeline processes {metric_num} features/hour. ML model registry set up with model versioning and lineage tracking. Champion/challenger framework active for {random_int(2,5)} models. Next week: model explainability dashboard.',
                ],
                'monthly' => [
                    'Nova {month} {year} report: ML platform processed {metric_k}K predictions/day. Online serving: {metric_ms}ms p99 latency. Model accuracy: {metric_pct}% (stable). Training data volume: {metric_num} new records this month. Feature store: {random_int(50,200)} features available. A/B experiments running: {random_int(2,6)}. Engineering velocity: {sprint_points} points. Team: {random_int(8,16)} members.',
                ],
                'quarterly' => [
                    'Nova Q{random_int(1,4)} {year} review: ML platform milestones achieved. Recommendation engine launched with {change_pct}% revenue uplift. Data pipeline migrated to streaming architecture. GPU cluster provisioned with {random_int(2,8)} nodes. Model accuracy improved {change_pct}%. Team grew from {random_int(5,8)} to {random_int(8,15)}. Budget: {dollars} spent of {dollars} allocated. Q{random_int(1,4)} focus:{random_int(1,3)}x model serving scale and real-time personalization.',
                ],
            ],

            'Project Apex' => [
                'daily' => [
                    'Apex daily: v{random_int(3,4)}.{random_int(0,2)}.{random_int(0,5)} release prep ongoing. Release candidate {random_int(1,5)} deployed to staging. E2E test suite: {metric_pct}% pass rate ({bug_count} failures). Critical P0 bug in concurrent document editing identified — traced to missing lock in OT algorithm. Fix in code review. Search indexer null pointer patched and verified.',
                    'Apex standup: Production deployment of v{random_int(3,4)}.{random_int(0,3)}.{random_int(0,5)} completed — zero downtime in {random_int(8,20)} min. Post-deploy metrics: error rate {random_int(0,5)}.{random_int(0,9)}% ({metric_pct}% below SLO), p95 latency {metric_ms}ms. Real-time collaboration feature adoption: {metric_k}K active users in first {hours}h.',
                ],
                'weekly' => [
                    'Apex weekly: Sprint delivered {sprint_points} points. Key releases: real-time collaboration, search reindex (<200ms P95), mobile nav redesign. Bug triage: {bug_count} new issues ({random_int(1,3)} P0, {random_int(2,5)} P1, rest P2). All P0s assigned and in progress. Customer feedback on collaboration features: {metric_pct}% positive. NPS: {random_int(35,65)}.',
                    'Apex this week: Post-release metrics healthy. New user activation improved {change_pct}% with redesigned onboarding. Search relevance score: {metric_pct}%. Mobile crash rate: {random_int(1,5)}.{random_int(0,9)}% (below {random_int(3,8)}% threshold). Rollback plan maintained for {hours}h post-deployment window. Customer support tickets: {bug_count} ({change_pct}% WoW).',
                ],
                'monthly' => [
                    'Apex {month} {year} report: MRR {dollars} ({change_pct}% MoM). Active customers: {metric_k}K. Platform uptime: {metric_pct}.{random_int(0,9)}%. Feature adoption: collaboration {metric_pct}%, mobile {metric_pct}%, search {metric_pct}%. Engineering: {sprint_points} points delivered, {bug_count} bugs resolved. Customer health: {metric_pct}% green, {random_int(10,25)}% at-risk, {random_int(3,12)}% critical.',
                ],
                'quarterly' => [
                    'Apex Q{random_int(1,4)} {year} results: Revenue {dollars} ({change_pct}% growth). Net revenue retention: {random_int(110,130)}%. Customer count: {metric_k}K. Product launches: v{random_int(3,4)}.{random_int(0,2)} with collaboration features, mobile SDK v2. Engineering: {sprint_points} points/sprint avg, {bug_count} bugs fixed. Team: {random_int(15,35)} members. Security: penetration test passed, MFA mandatory for all enterprise accounts.',
                ],
            ],

            'Project Helios' => [
                'daily' => [
                    'Helios daily: Active users {metric_k}K. Avg session: {random_int(5,12)} min. API requests: {metric_num}. Onboarding conversion: {metric_pct}%. Revenue {dollars}. Support tickets today: {bug_count}. Server health: all {random_int(8,16)} DB nodes healthy. Replication lag <{random_int(50,200)}ms.',
                ],
                'weekly' => [
                    'Helios weekly: Server maintenance completed — all {random_int(8,16)} DB nodes patched with rolling updates. Zero downtime achieved. Maintenance window: {random_int(30,90)} min ({random_int(25,75)}% of scheduled). PostgreSQL upgraded to {random_int(15,16)}.{random_int(1,4)}. pgvector v0.7.0 active with HNSW index support. Replication lag <{random_int(50,200)}ms. All services healthy.',
                    'Helios progress: {team} team completed load testing for {metric_k}K concurrent users. Results: p95 {metric_ms}ms ({change_pct}% improvement). Auto-scaling policy tuned. CDN cache hit ratio: {metric_pct}%. Error budget: {metric_pct}% remaining. This week also shipped {sprint_points} points and resolved {bug_count} customer issues.',
                ],
                'monthly' => [
                    'Helios {month} {year} summary: Active users {metric_k}K ({change_pct}% MoM). Avg session: {random_int(5,12)}.{random_int(0,9)} min. Onboarding activation: {metric_pct}% (up from {random_int(30,50)}%). API requests: {metric_num}M with {metric_pct}.{random_int(0,9)}% uptime. Revenue {dollars} MRR. Enterprise: {random_int(50,65)}% of revenue ({random_int(10,15)}% of customers). Support tickets: {bug_count}, avg response {hours}h. Churn: {random_int(2,5)}.{random_int(0,9)}%.',
                    'Helios monthly: Self-serve conversion {random_int(3,6)}%. NPS {random_int(40,65)}. Top support issues: billing ({random_int(25,40)}%), feature requests ({random_int(20,35)}%), integrations ({random_int(15,28)}%). Expansion revenue: {change_pct}% growth. Docs overhaul {metric_pct}% complete. Infrastructure cost reduced {change_pct}% through right-sizing. Team: {random_int(10,20)} members.',
                ],
                'quarterly' => [
                    'Helios Q{random_int(1,4)} {year}: All OKRs achieved. Revenue grew {change_pct}% to {dollars}. Customer count: {metric_k}K. Enterprise launches in {random_int(2,4)} regions. Platform reliability: {metric_pct}.{random_int(0,9)}% uptime, {bug_count} incidents. Feature releases: {random_int(10,20)} major features. Security: no critical vulnerabilities. Team expanded to {random_int(12,25)}. Next quarter: AI features and internationalization.',
                ],
            ],

            'Project Zenith' => [
                'daily' => [
                    'Zenith daily: Research experiments showing {change_pct}% improvement in inference speed with new transformer architecture. Model variants tested: Tiny ({random_int(1,4)}M params), Base ({random_int(8,18)}M params), Large ({random_int(30,60)}M params). Base variant best accuracy-speed trade-off: {metric_num} inferences/sec on single T4 GPU. INT8 quantization reduces size {change_pct}% with {random_int(1,5)}.{random_int(0,9)}% accuracy drop.',
                    'Zenith standup: Model serving infrastructure preparation ongoing. Triton Inference Server configured. API wrapper with error handling in code review. Integration tests written for {metric_pct}% of endpoints. Research paper draft {random_int(20,80)}% complete. Conference submission deadline: {random_int(15,60)} days away. Collaboration with University of Tokyo confirmed.',
                ],
                'weekly' => [
                    'Zenith research week {random_int(1,6)}: Model quantization results — FP32 → INT8 via QAT: {random_int(1,5)}.{random_int(0,9)}% accuracy drop (acceptable). PTQ: {random_int(3,8)}.{random_int(0,9)}% drop. Recommended approach: QAT for production. Quantized model size: {random_int(8,20)}MB ({change_pct}% reduction). Inference speed: {random_int(2,4)}x on CPU, {random_int(1,3)}x on GPU. All validation tests passing.',
                    "Zenith weekly: {team} team achieved {metric_pct}% model accuracy on benchmark datasets. Feature selection algorithm completed. Paper 'Quantum-Enhanced Feature Selection' accepted at ICML {year}. Quantum circuit uses {random_int(10,20)} qubits — feasible for near-term devices. Open-source implementation in review. Patent filing initiated for core algorithm.",
                ],
                'monthly' => [
                    'Zenith {month} {year} report: Research milestones — {metric_pct}% improvement in inference speed. Model accuracy: {metric_pct}%. Benchmarks completed on {random_int(2,5)} datasets. Model size: {random_int(8,50)}MB ({metric_pct}% reduction with INT8). Production pilot: {metric_pct}% complete. Research collaborations active: {random_int(2,5)} academic partners, {random_int(1,3)} industry partners. Budget: {dollars} spent. Team: {random_int(5,12)} researchers and engineers.',
                ],
                'quarterly' => [
                    'Zenith Q{random_int(1,4)} {year} review: Research roadmap {metric_pct}% complete. Major milestones: (1) New transformer architecture with {change_pct}% speedup, (2) Quantum-classical hybrid algorithm demonstrated, (3) {random_int(1,3)} papers submitted to top conferences. Production pilot launched with {random_int(2,8)} customers. Patents filed: {random_int(1,4)}. Team: {random_int(8,15)} members. Budget: {dollars} utilized. Next quarter: expand to {random_int(2,5)} more enterprise customers.',
                ],
            ],

            'Project Atlas' => [
                'daily' => [
                    'Atlas daily: Database migration script completed — query performance improved {change_pct}%. New indexing strategy active in production. User authentication module upgraded with refresh token support. Session management redesigned. Security improvements: {random_int(2,5)} vulnerabilities patched. Mobile app v2.0 ratings: {random_int(3,5)}.{random_int(0,9)} stars.',
                    'Atlas standup: Server migration from AWS Singapore to Tokyo region complete. Downtime: {random_int(3,12)} min. Latency reduced {change_pct}% ({metric_ms}ms avg). Cost savings: {dollars}/month. User satisfaction: {metric_pct}%. CDN configuration optimized for Asian markets. Next: migrate DR site to Osaka region.',
                ],
                'weekly' => [
                    'Atlas weekly: Platform upgrade completed — version {random_int(2,3)}.{random_int(0,5)}.{random_int(0,9)} deployed to {metric_pct}% of users. New features: real-time analytics, advanced reporting, custom dashboards. Performance: {metric_ms}ms p95 latency ({change_pct}% improvement). Uptime: {metric_pct}%.{random_int(0,9)}%. Customer feedback: {metric_pct}% positive. Next week: mobile push notifications and offline mode.',
                    'Atlas progress: {team} team focused on platform stability. Infrastructure automation via Terraform: {metric_pct}% coverage. Environment provisioning: {random_int(15,45)} min (down from {random_int(24,72)}h). Cost optimization: right-sized {random_int(10,30)}% of resources, saving {dollars}/month. Incident response drills completed with {metric_pct}% success rate.',
                ],
                'monthly' => [
                    'Atlas {month} {year} report: Active users {metric_k}K ({change_pct}% MoM). Transactions: {metric_num}M processed. Uptime: {metric_pct}.{random_int(0,9)}%. Revenue: {dollars} MRR. Feature adoption: analytics {metric_pct}%, mobile {metric_pct}%, API {metric_pct}%. Engineering: {sprint_points} points, {bug_count} bugs fixed. Customer support: {bug_count} tickets, {hours}h avg response. Team: {random_int(10,25)} members.',
                ],
                'quarterly' => [
                    'Atlas Q{random_int(1,4)} {year} results: All major milestones achieved. Platform scaled to {metric_k}K active users. Revenue grew {change_pct}% QoQ to {dollars}. Product launches: mobile v2.0, analytics dashboard, API marketplace. Infrastructure: migrated to Tokyo region, {metric_pct}% cost reduction. Security: penetration test passed, ISO 27001 certification in progress. Team growth: {random_int(5,15)} new hires. Next: AI assistant feature, Japan market expansion.',
                ],
            ],

            'Project Quantum' => [
                'daily' => [
                    'Quantum daily: Quantum-classical hybrid algorithm benchmarked — {random_int(2,5)}x speedup over classical methods for portfolio optimization. Ran on IBM {random_int(100,150)}-qubit processor with {metric_pct}.{random_int(0,9)}% fidelity. Error mitigation techniques explored. Fidelity improvement path identified: expect >{metric_pct}% with zero-noise extrapolation. Research paper abstract due {random_int(10,45)} days.',
                    "Quantum standup: Quantum algorithm simulations completed — {metric_k}K circuits simulated. Grover's search: {metric_pct}% success probability. Shor's factoring: {random_int(8,24)}-bit numbers factored. Surface code error correction: threshold {random_int(5,15)}.{random_int(0,9)}%. QML hybrid algorithm for image classification: {metric_pct}% accuracy on benchmark dataset.",
                ],
                'weekly' => [
                    'Quantum weekly: Security review completed for Quantum API gateway. mTLS authentication implemented on all endpoints. API key rotation policy: every {random_int(60,120)} days. Request signing via Ed25519. Audit: no critical findings. ISO 27001 compliance achieved for quantum infrastructure. Risk assessment completed for hybrid architecture. All data at rest encrypted with AES-256-GCM.',
                    'Quantum progress: {team} team published research findings on quantum feature selection. ICML {year} paper accepted. Collaboration with University of Tokyo and IBM Quantum Network. Open-source implementation ready for release under MIT license. Patent application filed for core algorithm. Next: extend algorithm to handle {metric_k}K-dimension datasets.',
                ],
                'monthly' => [
                    "Quantum {month} {year} research report: Grover's and Shor's algorithms simulated on classical hardware. Error correction: surface code threshold {metric_pct}%. Quantum ML hybrid algorithm: {metric_pct}% accuracy. Research paper 'Quantum-Enhanced Feature Selection' accepted at ICML {year}. Patent application filed. Collaborations active: {random_int(2,5)} academic partners. Budget: {dollars} spent. Team: {random_int(4,10)} researchers.",
                ],
                'quarterly' => [
                    'Quantum Q{random_int(1,4)} {year} review: Research output — {random_int(1,3)} papers published, {random_int(0,2)} patents filed. Algorithm performance: {random_int(2,5)}x speedup over classical. Hardware access: IBM {random_int(100,150)}-qubit processor, fidelity {metric_pct}%. Error correction milestones: threshold improved {change_pct}%. Team: {random_int(6,15)} researchers. Budget: {dollars} spent. Next quarter: deploy hybrid algorithm on production infrastructure.',
                ],
            ],

            'Project Fusion' => [
                'daily' => [
                    'Fusion daily Status: Cross-team collaboration for {project} continues. Integration testing for shared API contract v{random_int(1,3)}.{random_int(0,5)} underway. {bug_count} failing tests identified — {metric_pct}% fixed. Documentation sync across {random_int(3,6)} teams. Code review queue: {random_int(5,20)} PRs pending.',
                ],
                'weekly' => [
                    'Fusion weekly: Cross-team collaboration score: {random_int(3,5)}.{random_int(0,5)}/5.0 ({change_pct}% improvement). Shared API contracts defined: {random_int(5,15)}. Code review turnaround: {hours}h (target <8h). Merge conflict rate: {random_int(1,5)}.{random_int(0,9)}%. Integration test coverage: {metric_pct}% (up from {random_int(40,65)}%). Issues caught early through contract testing: {bug_count} potential incidents prevented.',
                    'Fusion this week: {team} team finalized API contracts between recommendation engine and search service. Event schema approved by architecture board. {sprint_points} points completed. Integration tests expanded to {random_int(10,20)} microservices. Architecture decision records published: {random_int(2,6)}. Cross-team sync meetings: {random_int(2,6)} conducted.',
                ],
                'monthly' => [
                    'Fusion {month} monthly: Cross-project alignment score: {random_int(3,5)}.{random_int(0,5)}. Shared services: {random_int(5,15)} APIs documented and versioned. Contract test coverage: {metric_pct}%. Teams adopting common standards: {random_int(3,8)}. Platform engineering: CI/CD pipeline shared across {random_int(4,10)} teams. Deployment frequency: {random_int(5,20)}/week. Rollback success: {metric_pct}%. Engineering efficiency savings: {hours}h/week estimated.',
                ],
                'quarterly' => [
                    'Fusion Q{random_int(1,4)} {year}: Cross-team integration initiative {metric_pct}% complete. Common API platform launched with {random_int(5,15)} services. Contract testing mandatory for {random_int(5,12)} teams. Developer productivity: CI/CD time reduced {change_pct}%, environment provisioning {change_pct}% faster. Cost savings from shared infrastructure: {dollars}/month. Next quarter: expand to remaining {random_int(2,5)} teams.',
                ],
            ],

            // ── Myanmar / Burmese project templates ───────────────────────
            'ဒစ်ဂျစ်တယ်အသွင်ပြောင်းလဲရေး' => [
                'daily' => [
                    '{project} — ယနေ့ဆောင်ရွက်ချက်များ — အွန်လိုင်းဝန်ဆောင်မှုသစ်အတွက် လက်ခံစမ်းသပ်မှု ပြုလုပ်ခဲ့ပါတယ်။ ဝန်ဆောင်မှုပေါင်း {metric_num} ခုကို စမ်းသပ်နိုင်ခဲ့ပြီး {metric_pct}% အောင်မြင်ပါတယ်။ အသုံးပြုသူ {metric_k}K ဦးအတွက် အကောင့်များ ပြင်ဆင်ပြီးပါပြီ။ လာမည့်ရက်များတွင် ကျေးလက်ဒေသများသို့ တိုးချဲ့ဆောင်ရွက်သွားမည်။',
                    '{project} — e-Government portal အတွက် ဝန်ဆောင်မှုအသစ် {random_int(2,6)} ခု ထပ်မံထည့်သွင်းနိုင်ခဲ့ပါတယ်။ စုစုပေါင်းဝန်ဆောင်မှု {metric_num} ခုအထိ ရှိလာပါပြီ။ Portal အသုံးပြုသူ {metric_k}K ဦး ရှိလာပါတယ်။ ငွေပေးချေမှုစနစ်သစ်ကို ကျေးရွာ {random_int(5,15)} ရွာတွင် စတင်အသုံးပြုနိုင်ပြီဖြစ်ပါတယ်။',
                ],
                'weekly' => [
                    '{project} အပတ်စဉ်သုံးသပ်ချက် — ဒစ်ဂျစ်တယ်သင်တန်းပေါင်း {random_int(3,8)} ခုကို ကျင်းပပြုလုပ်နိုင်ခဲ့ပြီး သင်တန်းသား {metric_k}K ဦး တက်ရောက်ခဲ့ပါတယ်။ သင်တန်းသားစိတ်ကျေနပ်မှု {metric_pct}% ရရှိပါတယ်။ အွန်လိုင်းလိုင်စင်ထုတ်ပေးရေးစနစ်ကို စတင်နိုင်ခဲ့ပြီး လျှောက်ထားမှု {metric_num} ခုကို ၂၄ နာရီအတွင်း ထုတ်ပေးနိုင်ခဲ့ပါတယ်။',
                ],
                'monthly' => [
                    '{month} {year} လစဉ်အစီရင်ခံစာ — ဌာနဆိုင်ရာ {random_int(5,12)} ခုမှ ဝန်ထမ်း {metric_k}K ဦးအား ဒစ်ဂျစ်တယ်ကျွမ်းကျင်မှုသင်တန်းများ ပို့ချနိုင်ခဲ့ပါတယ်။ ဝန်ဆောင်မှုပေါင်း {metric_num} ခုကို အွန်လိုင်းမှတစ်ဆင့် ဆောင်ရွက်ပေးနိုင်ခဲ့ပါတယ်။ Portal အသုံးပြုသူ {metric_k}K ဦးရှိလာပါတယ်။ ကျေးလက်ဒေသ {random_int(5,20)} ခုသို့ အင်တာနက်ချိတ်ဆက်မှု ဖြန့်ကျက်နိုင်ခဲ့ပါတယ်။',
                ],
                'quarterly' => [
                    '{quarter} သုံးလပတ်သုံးသပ်ချက် — စီမံကိန်းရည်မှန်းချက် {metric_pct}% ပြည့်မီအောင် ဆောင်ရွက်နိုင်ခဲ့ပါတယ်။ အောင်မြင်မှုများ — (၁) e-Government portal စတင်ဖွင့်လှစ်ခြင်း၊ (၂) လိုင်စင်ထုတ်ပေးရေးစနစ် ဒစ်ဂျစ်တယ်အသွင်ပြောင်းခြင်း၊ (၃) ဝန်ထမ်း {metric_k}K ဦးအား သင်တန်းပေးခြင်း။ ရင်းနှီးမြှုပ်နှံမှု {dollars} သုံးစွဲခဲ့ပြီး အကျိုးကျေးဇူး {metric_pct}% ရရှိပါတယ်။',
                ],
            ],

            'မြန်မာ့စီးပွားရေး' => [
                'daily' => [
                    '{project} — MSME စစ်တမ်းကောက်ယူမှု ပြီးစီးခဲ့ပါတယ်။ လုပ်ငန်းရှင် {metric_k}K ဦး ပါဝင်ဖြေကြားခဲ့ပါတယ်။ ဒစ်ဂျစ်တယ်နည်းပညာအသုံးပြုမှု {metric_pct}% ရှိပြီး ငွေကြေးထောက်ပံ့မှု {change_pct}% လိုအပ်နေပါတယ်။ ဆန်စပါးတန်ချိန် {metric_k}K တင်ပို့နိုင်ခဲ့ပြီး ဝင်ငွေ {dollars} ရရှိပါတယ်။',
                ],
                'weekly' => [
                    '{project} အပတ်စဉ်ခွဲခြမ်းစိတ်ဖြာချက် — MSME လုပ်ငန်းများ၏ {metric_pct}% သည် ဒစ်ဂျစ်တယ်နည်းပညာအသုံးပြုမှု နည်းပါးနေပါတယ်။ ကျေးလက်ဒေသရှိ စီးပွားရေးလုပ်ငန်းရှင်များအတွက် သင်တန်း {random_int(2,5)} ခု ကျင်းပခဲ့ပါတယ်။ ငွေပေးချေမှုစနစ်သစ်ကို ကျေးရွာ {random_int(5,15)} ရွာတွင် စတင်အသုံးပြုနိုင်ပြီဖြစ်ပါတယ်။',
                ],
                'monthly' => [
                    '{project} {month} {year} — MSME ကဏ္ဍသည် GDP ၏ {metric_pct}% ကို ပံ့ပိုးပေးနိုင်ပါတယ်။ စိုက်ပျိုးရေးကဏ္ဍ {change_pct}% တိုးတက်မှု၊ ကုန်ထုတ်လုပ်မှုကဏ္ဍ {metric_pct}% တိုးတက်မှုရှိပါတယ်။ ပို့ကုန်ဝင်ငွေ {dollars} ရရှိပါတယ်။ FDI ဒေါ်လာ {metric_num} သန်း ဝင်ရောက်ခဲ့ပါတယ်။ အလုပ်အကိုင် {metric_k}K ခန့် ဖန်တီးပေးနိုင်ခဲ့ပါတယ်။',
                ],
                'quarterly' => [
                    '{quarter} သုံးလပတ်စီးပွားရေးခွဲခြမ်းစိတ်ဖြာချက် — စီးပွားရေးတိုးတက်မှုနှုန်း {metric_pct}% ရှိပါတယ်။ MSME လုပ်ငန်း {metric_k}K ခုအား ငွေကြေးထောက်ပံ့မှု {dollars} ပေးအပ်နိုင်ခဲ့ပါတယ်။ ရင်းနှီးမြှုပ်နှံမှုအခွင့်အလမ်းသစ်များ — နည်းပညာ၊ စွမ်းအင်၊ အခြေခံအဆောက်အအုံ။ စီမံကိန်း {random_int(5,15)} ခုကို အကောင်အထည်ဖော်လျက်ရှိပါတယ်။',
                ],
            ],

            'ကျေးလက်ဖွံ့ဖြိုးရေး' => [
                'daily' => [
                    '{project} — ကျေးလက်လမ်းဖောက်လုပ်ရေးစီမံကိန်း {metric_pct}% ပြီးမြောက်ပါပြီ။ လမ်းအရှည် {metric_num} ကီလိုမီတာ ဖောက်လုပ်ပြီးစီးပါတယ်။ ရေတွင်းသစ် {random_int(5,15)} တူးဖော်ပြီးစီးပါတယ်။ ကျေးရွာ {random_int(3,8)} ရွာတွင် သောက်သုံးရေ {metric_pct}% ရရှိနိုင်ပြီဖြစ်ပါတယ်။ စိုက်ပျိုးရေးသင်တန်း {random_int(2,5)} ခု ပို့ချနိုင်ခဲ့ပါတယ်။',
                ],
                'weekly' => [
                    '{project} အပတ်စဉ်တိုးတက်မှု — ကျေးရွာ {random_int(3,8)} ရွာတွင် သောက်သုံးရေရရှိရေးစီမံကိန်း ဆက်လက်အကောင်အထည်ဖော်လျက်ရှိပါတယ်။ ရေတွင်းသစ် {random_int(3,8)} တူးဖော်ပြီးပါပြီ။ လယ်သမား {metric_k}K ဦးအတွက် ခေတ်မီစိုက်ပျိုးရေးနည်းပညာသင်တန်း ပို့ချနိုင်ခဲ့ပါတယ်။ မိုးရာသီအတွက် ရေထိန်းစနစ်များ တည်ဆောက်ရန် စီစဉ်နေပါတယ်။',
                ],
                'monthly' => [
                    '{project} {month} {year} — ကျေးရွာ {random_int(8,20)} ရွာတွင် အခြေခံအဆောက်အအုံစီမံကိန်းများ အကောင်အထည်ဖော်နိုင်ခဲ့ပါတယ်။ လမ်းအရှည် {metric_num} ကီလိုမီတာ ဖောက်လုပ်ပြီးစီးပါတယ်။ သောက်သုံးရေ {metric_pct}% ရရှိနိုင်ပြီဖြစ်ပါတယ်။ အိမ်ထောင်စု {metric_k}K စုအတွက် မီးလင်းရေး ဆောင်ရွက်ပေးနိုင်ခဲ့ပါတယ်။ ရင်းနှီးမြှုပ်နှံမှု {dollars} သုံးစွဲခဲ့ပါတယ်။',
                ],
                'quarterly' => [
                    '{quarter} ကျေးလက်ဖွံ့ဖြိုးရေးသုံးသပ်ချက် — ကျေးရွာ {metric_num} ရွာတွင် စီမံကိန်း {random_int(10,25)} ခု အကောင်အထည်ဖော်ပြီးစီးပါတယ်။ လမ်းဖောက်လုပ်ရေး {random_int(10,50)} ကီလိုမီတာ၊ ရေတွင်း {random_int(20,60)} တူးဖော်ပြီးစီးပါတယ်။ စိုက်ပျိုးရေးသင်တန်း {random_int(10,30)} ခု ပို့ချနိုင်ခဲ့ပါတယ်။ အိမ်ထောင်စု {metric_k}K စု အကျိုးခံစားခွင့်ရရှိပါတယ်။',
                ],
            ],

            'ပညာရေးစနစ်' => [
                'daily' => [
                    '{project} — ဒစ်ဂျစ်တယ်သင်ကြားရေးပလက်ဖောင်းအတွက် အကြောင်းအရာအသစ် {random_int(3,8)} ခု ပြင်ဆင်ပြီးစီးပါတယ်။ သင်္ချာ၊ သိပ္ပံနှင့် အင်္ဂလိပ်စာဘာသာရပ်များအတွက် interactive lesson များ ထည့်သွင်းနိုင်ခဲ့ပါတယ်။ ကျောင်း {random_int(5,15)} ကျောင်းမှ ဆရာ/ဆရာမ {metric_k}K ဦး ပါဝင်ဆောင်ရွက်လျက်ရှိပါတယ်။',
                ],
                'weekly' => [
                    '{project} အပတ်စဉ်အစီရင်ခံစာ — တိုင်းဒေသကြီး {random_int(2,5)} ခုရှိ ကျောင်း {random_int(10,30)} ကျောင်းတွင် ဒစ်ဂျစ်တယ်သင်ကြားရေးနည်းပညာသင်တန်း ပို့ချနိုင်ခဲ့ပါတယ်။ သင်တန်းသားစိတ်ကျေနပ်မှု {metric_pct}% ရရှိပါတယ်။ အွန်လိုင်းစာမေးပွဲစနစ် စမ်းသပ်မှု အောင်မြင်ပါတယ်။',
                ],
                'monthly' => [
                    '{month} {year} — ဒစ်ဂျစ်တယ်ပညာရေးပလက်ဖောင်းတွင် ကျောင်းသား {metric_k}K ဦး စာရင်းသွင်းထားပါတယ်။ သင်တန်း {metric_num} ခုကို အွန်လိုင်းမှတစ်ဆင့် သင်ကြားပေးနိုင်ခဲ့ပါတယ်။ {metric_pct}% သော ကျောင်းသားများသည် အပတ်စဉ် ပလက်ဖောင်းကို အသုံးပြုပါတယ်။ စာမေးပွဲရမှတ် {change_pct}% တိုးတက်မှုရှိပါတယ်။',
                ],
                'quarterly' => [
                    '{quarter} ပညာရေးစနစ်သုံးသပ်ချက် — ကျောင်းသား {metric_k}K ဦးအတွက် ဒစ်ဂျစ်တယ်သင်ကြားရေးပလက်ဖောင်းကို အကောင်အထည်ဖော်နိုင်ခဲ့ပါတယ်။ သင်တန်း {metric_num} ခု ဖန်တီးနိုင်ခဲ့ပါတယ်။ ကျောင်းသားစာမေးပွဲရမှတ် {change_pct}% တိုးတက်လာပါတယ်။ ဆရာ/ဆရာမ {metric_k}K ဦးအား သင်တန်းပေးနိုင်ခဲ့ပါတယ်။ ရှေ့ဆက်ဆောင်ရွက်ရန် — ကျေးလက်ဒေသရှိ ကျောင်းများသို့ တိုးချဲ့ခြင်း၊ ဘာသာရပ်အသစ်များ ထည့်သွင်းခြင်း။',
                ],
            ],
        ];
    }
}
