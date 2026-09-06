# SDK stabilization tasks

Repository: Robo-Meister/flow-scribe-sdk-php. Base: 8347d1e1b80ab7c7981b3c3bc19650d517255429.
These three SDK-only tasks can be reviewed independently of server access-policy work.

| Task | Implemented SDK scope | Acceptance evidence |
| --- | --- | --- |
| FLOWSCRIBE-PHP-SDK-REQUEST-CONTRACT-001 | Preserve JSON multipart configuration; clean generated files on encoding/write/HTTP/transport failures; add optional org/workspace/correlation options to health, metadata and status reads; retain scope on diagnostics fallback | File content/MIME assertions, caller-owned file retention, cleanup and scoped reads |
| FLOWSCRIBE-PHP-SDK-RESULT-ERROR-CONTRACT-001 | Preserve raw legacy and future review responses; require a JSON object; preserve response correlation on decode errors, body correlation_id on HTTP errors and cURL error numbers; retain job states | Legacy/prospective response fixtures, queued/completed/failed/blocked states, malformed responses, HTTP errors and timeout |
| FLOWSCRIBE-PHP-SDK-REGRESSION-BASELINE-001 | One-command local fixture tests, PHP 8.2/8.3 CI, documented auth/endpoint matrix and release boundaries | php tests/test.php, syntax checks and Composer validation |

Implementation and tests are included in this PR. Workflow results, rather than this document, establish whether runtime tests passed.

## Compatibility decisions

- Existing method calls and array responses remain supported; new options and the exception's transport error field are optional.
- Malformed 2xx responses containing JSON arrays/scalars/null now raise an exception. A JSON object remains the API response contract.
- Review defaults are requests to the server. Missing review_payload is never fabricated in PHP.
- HTTP success for a job-status read is distinct from successful OCR; inspect data.job.status.
- No automatic retry, anonymous fallback, subscription bypass, tag publication or RC Composer update is introduced.

## Dependencies kept outside this repository

- FLOWSCRIBE-FREE-API-ACCESS-001: default-free server policy and unchanged identity/data access checks.
- FLOWSCRIBE-SYNC-REVIEW-CONTRACT-001: the real /process review envelope and optional preview/storage support.
- RC-FLOWSCRIBE-SDK-CONVERGENCE-001: adapt RC's existing OcrClient and choose/pin a released package.
- Durable async execution, upload deduplication and callback addressing/signing: FlowScribe and RC server tasks.
- Context Engine and Command Center integration: later ecosystem work.

The status and legacy-response fixtures reflect the inspected FlowScribe ec54b6c57dd4c30dc1244a3043085a42285675bc implementation. The review fixture is explicitly prospective. No live OCR accuracy or deployment compatibility claim is made.
