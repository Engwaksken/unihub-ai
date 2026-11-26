# unihub/central-ai-client

This is a Laravel client for the Unihub Central AI gateway: asynchronous `POST /v1/jobs` and `GET /v1/jobs/{id}`. It is **server-side only**; the project key must never reach a mobile build.

| Class | Job |
|---|---|
| `CentralAiClient` | `submit()`, `get()` and `cancel()`. Errors arrive as `CentralAiException` (`errorCode`, `status`, `retryAfter`, `retryable()`, `userMessage()`). |
| `AiRequestService` | Owns per-user `ai_requests` rows. Covers idempotent retry, Retry-After, the poll interval and the client deadline. `present()` returns the shape safe to send to the app. |
| `AiRequest` | Eloquent model; input and output are encrypted. |

For setup and the Flutter UX, see `docs/integration/my-digital-diary.md`.

```bash
composer install && vendor/bin/phpunit                    # 8 tests (live test skipped)
CENTRAL_AI_LIVE_URL=... CENTRAL_AI_LIVE_KEY=... vendor/bin/phpunit --filter LiveGateway
```
