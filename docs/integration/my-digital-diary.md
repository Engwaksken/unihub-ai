# Pilot integration: My Digital Diary (P4-1, P4-2)

**Status:** this is a ready-to-apply kit; nothing has been applied yet. The My Digital Diary repository was not available to this project, and the PRD says not to change production apps without approval. The diary team applies this on a branch against **staging**.

```
Flutter app ──(user's normal app token)──▶ Diary Laravel backend ──(project key, HTTPS)──▶ ai.kemmytech.com
                                              │ owns ai_requests rows per user
                                              └ never sends the key or gateway job id to Flutter
```

## 1. Backend (Laravel)
1. **Install the client package.** It's in `integrations/laravel-central-ai` (8 tests, plus an opt-in live test):
   ```json
   "repositories": [{ "type": "path", "url": "../unihub-ai/integrations/laravel-central-ai" }]
   ```
   Then run `composer require unihub/central-ai-client`. A private VCS repository works too.
2. **Set the environment** (PRD §09). Store the key in the server's secret store, never in git:
   ```
   AI_BASE_URL=https://ai.kemmytech.com
   AI_API_KEY=uhk_test_...   # staging key from the CMS (Projects → My Digital Diary → API keys), shown once
   ```
3. **Create the table:** `php artisan vendor:publish --tag=central-ai-migrations && php artisan migrate`. This creates `ai_requests`, with input and output encrypted using the app's `APP_KEY`.
   - Change `user_id` to `uuid` if the diary uses UUID users.
4. **Add the routes:** copy `examples/AiPlanController.php` and its three routes, which sit behind the app's existing auth (for example Sanctum) and a throttle.

   | Route | Returns |
   |---|---|
   | `POST /ai/daily-plan` `{input}` | `202 {id, status, message, poll_after_seconds}` |
   | `GET /ai/requests/{id}` | Current state; advances the request, rate-limited to one gateway poll per 3 s |
   | `DELETE /ai/requests/{id}` | Cancel, while still queued |
   - Another user's request returns **404**.
5. **Retention:** delete `ai_requests` rows (or at least `input` and `output`) once the user has saved or dismissed the plan, at most 24 h after creation. This mirrors the gateway's result TTL. Use a scheduled `AiRequest::where('created_at','<',now()->subDay())->delete()`.

### Behaviour the package already implements
- **Retries:** one idempotency key per request, reused on every retry, so the gateway never creates duplicates or charges quota twice.
- **429, 503 or network errors:** the request stays `pending` and is resubmitted after `Retry-After`. The app shows "The assistant is busy; we will retry automatically."
- **422 or 403** (input too long, task not allowed): `failed`, with a safe message. Gateway messages are never forwarded.
- **Deadline:** after 300 s the request becomes `expired`. The polling deadline is bounded.
- **Untrusted output:** treat the output as untrusted text. Display it escaped; never execute it or parse it as HTML. Never use it to post financial entries, approve anything, or set prices (PRD §08).

## 2. Flutter UX (P4-2)

| Backend `status` | UI |
|---|---|
| `pending` | Spinner and "Sending…", or the busy message. Keep polling. |
| `queued` | "Waiting for the assistant…" plus a **Cancel** button |
| `running` | "The assistant is writing…" (no cancel) |
| `completed` | Show `output` as plain text, with Save to diary / Discard |
| `failed` | Show `message`, with a **Try again** button that creates a NEW request |
| `expired` | "This request took too long", with **Try again** |
| `cancelled` | Return to the editor |

- **Polling:** poll `GET /ai/requests/{id}` every `poll_after_seconds` (3 s), backing off ×1.5 up to 10 s. Stop when `poll_after_seconds` is null.
- **Offline:** pause polling and show "You're offline — we'll check again when you reconnect". The backend keeps the request, so resume with the same `id`, and never resubmit while one is pending.
- **Lifecycle:** survive app backgrounding by storing the request `id` locally.
- **Accessibility:** status text must be readable by screen readers, so don't rely on the spinner alone.
- **No keys in the app:** there is never an `AI_API_KEY`, gateway URL or gateway job id in the Flutter build. CI should grep the APK/IPA for `uhk_`.

## 3. Pilot checklist (G4)
- [ ] Staging project `my-digital-diary-staging` exists, with the `daily_plan` task allowed. The gateway seeder creates this.
- [ ] A staging key is issued through the CMS and stored in the diary staging secrets.
- [ ] The live package test passes against staging:
  ```
  CENTRAL_AI_LIVE_URL=https://ai.kemmytech.com CENTRAL_AI_LIVE_KEY=... vendor/bin/phpunit --filter LiveGateway
  ```
- [ ] The benchmark has been run and its report attached to the handover:
  ```
  php tests/benchmark/run.php --base=https://ai.kemmytech.com --key=... --sample-cmd="free -m"
  ```
  Before running, raise the project's rpm/day quota temporarily in the CMS, and restore it afterwards.
- [ ] Flutter states were demonstrated, including offline and busy (429).
- [ ] The pilot project owner has reviewed the outputs of edge prompts p26–p30 (prompt injection, low mood, medication).
- [ ] The pilot project owner has signed off.
