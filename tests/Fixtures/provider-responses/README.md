# Provider Response Fixtures

All fixtures in this directory are **hand-authored** from each provider's documented API response
shape. They are NOT captured from live provider APIs.

The response formats are derived from reading each provider's `toResponse()` implementation in
`src/Provider/`. Fixtures exercise every branch of each normalizer:
- Content extraction
- Tool call parsing
- Usage token mapping
- Finish reason mapping
- `extra` passthrough of unmapped fields

Fixture values are synthetic but structurally correct.
