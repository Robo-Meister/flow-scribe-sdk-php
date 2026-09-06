# Changelog

## Unreleased

- Allow organisation/workspace/correlation options on health, metadata and both status methods.
- Preserve organisation/workspace headers during diagnostics fallback.
- Clean temporary config files on all exits; validate writes and encode JSON before allocating a file.
- Preserve error correlation from response headers or the server's correlation_id and expose cURL transport error numbers.
- Reject non-object JSON responses, including HTTP 2xx arrays/scalars/null, while preserving raw responses.
- Add a local HTTP regression runner, PHP 8.2/8.3 CI and explicit server/SDK compatibility documentation.

No release tag is created by these changes. The server's free-access policy, review envelope and durable async/callback handling require separate changes.
