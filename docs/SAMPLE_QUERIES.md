# Sample Queries

Sample questions for manual testing against seeded demo data (43,857 documents, 12 projects, 8 users, 2023-01-02 → 2026-05-15).

---

## 1. User-focused (English)

| # | Query | Expected filter |
|---|-------|----------------|
| 1 | `Does Sarah Chen have Project Orion reports?` | user=Sarah, project=Orion |
| 2 | `Show me Marcus Johnson's Project Helios reports` | user=Marcus, project=Helios |
| 3 | `What did James Okafor report on Project Quantum?` | user=James, project=Quantum |
| 4 | `Elena Rodriguez Project Nova reports` | user=Elena, project=Nova |
| 5 | `Admin User Project Atlas reports` | user=Admin, project=Atlas |

## 2. User-focused (Burmese)

| # | Query | Expected filter |
|---|-------|----------------|
| 6 | `အောင်ဇေယျာ Project Orion report ရှိလား?` | user=အောင်, project=Orion |
| 7 | `နေဝင်းအောင် မြန်မာ့စီးပွားရေး report ရှိလား?` | user=နေဝင်း, project=မြန်မာ့စီးပွားရေး |
| 8 | `ခင်မြမြ ပညာရေးစနစ် report တွေပြပါ` | user=ခင်, project=ပညာရေးစနစ် |
| 9 | `အောင်ဇေယျာ ဒစ်ဂျစ်တယ်အသွင်ပြောင်းလဲရေး report ရှိလား?` | user=အောင်, project=ဒစ်ဂျစ်တယ် |
| 10 | `နေဝင်းအောင် ကျေးလက်ဖွံ့ဖြိုးရေး report ပြပါ` | user=နေဝင်း, project=ကျေးလက် |

## 3. Project-only queries

| # | Query | Expected filter |
|---|-------|----------------|
| 11 | `Show me Project Fusion reports` | project=Fusion |
| 12 | `Project Zenith reports` | project=Zenith |
| 13 | `မြန်မာ့စီးပွားရေး report တွေပြပါ` | project=မြန်မာ့စီးပွားရေး |
| 14 | `ပညာရေးစနစ် report ရှိလား?` | project=ပညာရေးစနစ် |

## 4. Date-scoped queries

| # | Query | Expected filter |
|---|-------|----------------|
| 15 | `Sarah Chen Project Orion reports from last week` | user=Sarah, project=Orion, date≥May 9 |
| 16 | `Show me Project Nova reports from March 2025` | project=Nova, date=2025-03 |
| 17 | `What did Marcus Johnson report yesterday?` | user=Marcus, date=yesterday |
| 18 | `James Okafor Project Quantum reports this month` | user=James, project=Quantum, date=May 2026 |
| 19 | `မနေ့က အောင်ဇေယျာ Project Orion report ရှိလား?` | user=အောင်, project=Orion, date=yesterday |
| 20 | `လွန်ခဲ့တဲ့တစ်ပတ် Project Atlas report တွေပြပါ` | project=Atlas, date=last week |
| 20b | `အောင်ဇေယျာ Project Orion report 2026-04 လအတွက်ရှိလား?` | user=အောင်, project=Orion, date=2026-04 (date-range filter + vector search; FTS strips date patterns to avoid `-04` negation) |

## 5. Complex combinations

| # | Query | Expected filter |
|---|-------|----------------|
| 21 | `Show me Project Helios reports by Marcus Johnson from Q4 2024` | user=Marcus, project=Helios, date=Oct–Dec 2024 |
| 22 | `အောင်ဇေယျာ မြန်မာ့စီးပွားရေး Q1 2025 report ရှိလား?` | user=အောင်, project=မြန်မာ့စီးပွားရေး, date=Jan–Mar 2025 |
| 23 | `Sarah Chen Project Orion reports from June to August 2023` | user=Sarah, project=Orion, date=Jun–Aug 2023 |
| 24 | `Project Apex quarterly reports by Admin User` | user=Admin, project=Apex |
| 25 | `နေဝင်းအောင် ကျေးလက်ဖွံ့ဖြိုးရေး 2024 report တွေပြပါ` | user=နေဝင်း, project=ကျေးလက်, date=2024 |

## 6. Edge cases

| # | Query | Expected behavior |
|---|-------|-------------------|
| 26 | `Show me all reports` | No filters → broad search, likely hits many |
| 27 | `What did Sarah Chen report today?` | user=Sarah, date=today (Saturday → 0 results, refusal) |
| 28 | `ရှိလား report` | Minimal Burmese + English, falls back to "report" |
| 29 | `Project DoesNotExist reports` | No project match → broad search, likely refusal |
| 30 | `အောင်ဇေယျာ ဒီနေ့ Project Uranus report ရှိလား?` | user=အောင်, project=Uranus (no match → no project filter) |

## Quick smoke test (Burmese → English pairs)

```
အောင်ဇေယျာ Project Orion report ရှိလား?
Does Aung Zeya have Project Orion reports?

နေဝင်းအောင် မြန်မာ့စီးပွားရေး report ရှိလား?
Does Nay Win Aung have Myanmar Economy reports?

မနေ့က ခင်မြမြ ပညာရေးစနစ် report တွေပြပါ
Show Khin Myat's Education System reports from yesterday
```

## Verify: curl commands

```bash
# Burmese query
TOKEN=$(php artisan tinker --execute='echo \App\Models\User::first()->api_token;')
curl -s http://localhost:8000/api/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"question":"အောင်ဇေယျာ Project Orion report ရှိလား?","stream":false}'

# English query  
TOKEN=$(php artisan tinker --execute='echo \App\Models\User::first()->api_token;')
curl -s http://localhost:8000/api/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"question":"Does Sarah Chen have Project Orion reports?","stream":false}'
```

## 7. Alias Expansion

Term alias mappings automatically expand queries before embedding and FTS search. No special syntax needed.

| # | Query | How alias expansion helps |
|---|-------|---------------------------|
| 31 | `အိုရီယွန် project reports` | `အိုရီယွန် → Orion` alias causes the system to also search for "Orion", matching documents where project = "Orion" |
| 32 | `CNN model accuracy` | `CNN → Convolutional Neural Network` alias adds the canonical term, matching chunks mentioning either "CNN" or "Convolutional Neural Network" |
| 33 | `OR project status` | `OR → Orion` alias expands the search to also find "Orion" documents |

> **Note:** Seeder generates **1–3 reports per working day** per user-project pair. Weekends have no data — queries scoped to today (Saturday) will return refusal.
>
> **Query processing note:** `refineFtsQuery()` in `RAGPipelineService.php` strips detected filter terms (user name, project, date range) from the FTS query so they don't compete with content terms. It also strips complete date patterns (`YYYY-MM-DD`, `YYYY-MM`) **before** individual year stripping to prevent bare month/day fragments like `-04` from being interpreted as PostgreSQL FTS negation operators. Hyphen-prefixed numeric tokens are filtered out at the end as well. This means date-listing questions (e.g. "April 2026 reports") rely on **vector search** with date-range filters rather than FTS for the date portion.
