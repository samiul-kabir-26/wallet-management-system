# Task 12: Flutter App — User & Agent Panels

**Status:** Not started
**Revision:** v2 (2026-10-12). Rewritten against the backend as it stands after commit `bd8e962` (per-role wallets) and Task 13 (roles & permissions).
**Estimated Duration:** 25–40 hours (spread over several weeks if Flutter is new to you)
**Difficulty:** Medium–High (new language, new framework, financial UX)

---

## What changed since v1 of this plan

Read this first if you read the old version. Each line is a contract change the app must follow.

| Area | v1 said | Now (verified in code) |
|---|---|---|
| Wallets | One wallet per user | **One wallet per role.** A user can have a `USER` wallet and an `AGENT` wallet (`wallets.kind`, unique `(user_id, kind)`). The **token's ability** selects which one every endpoint uses. |
| `GET /wallets/me` | The user's wallet | The wallet whose `kind` matches the token (`user` → USER, `agent` → AGENT). 404 if that wallet doesn't exist. |
| Cash-in / cash-out body | `agent_id` | **`agent_phone`** (same BD regex as login). `agent_id` is gone. |
| Transfer body | `recipient_id` | Still `recipient_id` (integer). Gap G1 is half-solved: agents by phone, recipients still by ID. |
| History response | `data.items` | **`data.transactions`** + `data.pagination`. `docs/API.md` generic section is wrong on this. |
| History visibility | "My transactions" | **Wallet-scoped:** a `user` token sees rows touching the USER wallet, an `agent` token sees rows touching the AGENT wallet. Same person, different history per panel. Users never see `COMMISSION_PAYOUT`. |
| `*_balance_after` | Always present | Present **only for the viewer's own side**. Other sides' keys are **absent** (not `null`). `*_wallet_id` keys are absent for non-staff. |
| Idempotency | Global key | Scoped **per initiator** (unique `(initiated_by, idempotency_key)`). A replay returns the original transaction with the **same 201**. The payload is **not** compared. |
| Session check on start | `refresh-token` | `GET /auth/me` now exists and is the cheaper "is my token alive and who am I" call. |
| Auth response | — | `data.user.permissions` is always `[]` for user/agent tokens (permissions only apply to admin tokens). Ignore it in the app. |
| Admin-assigned roles | — | An admin can add USER or AGENT to an existing account (`assign-role`). Those wallets start at **0.00**, not 50.00, and no PIN is set. |
| Staff accounts | — | A staff member with an unfinished invite (password set, `password_changed_at` null) who logs in by PIN gets **403 "You must change your password before proceeding."** on every wallet/transaction/user route. |

---

## 0. Scope & Decisions Log

### What this app is

One Flutter app that serves **two panels**:

| Panel | Login route | Token ability | Wallet used | Who |
|---|---|---|---|---|
| User | `POST /api/v1/auth/user/login` | `user` | `USER` wallet | Holders of the `USER` role |
| Agent | `POST /api/v1/auth/agent/login` | `agent` | `AGENT` wallet | Holders of the `AGENT` role with `agent_info.status = APPROVED` |

The **admin panel is out of scope**. It stays on the web: password + OTP admin work is a desk task, custom roles and permissions (Task 13) are a staff concern, and keeping admin off phones shrinks the attack surface. The app must never call `/auth/admin/*`, `/roles`, `/permissions`, `/set-pin*`, `/pin-reset/initiate` or any `*/admin/*` route.

### Decisions (and why)

Where the requirements were ambiguous, I took the decision below. Revisit any of them deliberately rather than drifting away from them.

| # | Decision | Reason |
|---|---|---|
| D1 | **One app, two panels**, chosen on the login screen (Customer / Agent toggle). **One active session at a time.** | Mirrors the backend's route-per-panel model. A person holding both roles has two wallets and two histories; switching panel = log out, log in on the other route (prefill the phone). Holding two live tokens at once doubles the stolen-phone blast radius for little UX gain. |
| D2 | **State management: Riverpod** (`flutter_riverpod` + `riverpod_annotation`). | Compile-safe, testable without a widget tree, no `BuildContext` in business logic. Pinia knowledge transfers reasonably: a provider is roughly a store. |
| D3 | **HTTP: `dio`.** | Interceptors map 1:1 to the ones you wrote in `resources/js/services/api.ts`. Timeouts, cancellation and typed errors built in. |
| D4 | **Routing: `go_router`.** | Official package. Declarative routes, `redirect:` guards (the Flutter `useAuthGuard`), deep links (needed for PIN reset, §9.4). |
| D5 | **Models: `freezed` + `json_serializable`.** | Immutable models, `copyWith`, union types for UI states, generated parsing. |
| D6 | **Money: the `decimal` package, never `double`.** | The API returns amounts as JSON **floats** on `/wallets/me` and transactions, but as **strings** (`"50.00"`) on `/users/{id}`. Parse both into `Decimal` immediately. Send amounts as **strings with exactly 2 decimals** (`"500.00"`). The backend only checks `numeric|gt:0`, so the app is currently the only thing preventing `1.234` (see G8). |
| D7 | **Token storage: `flutter_secure_storage`** (Keychain / Android Keystore). Never `SharedPreferences`. | The Sanctum token is a bearer credential with full wallet authority for its ability, and **it never expires** (`SANCTUM_EXPIRATION=null`). |
| D8 | **No refresh-token pair.** Validate the session with `GET /auth/me` on cold start and resume; rotate the token with `POST /auth/refresh-token` at most once per app launch. Any `401` = session over → login. | The backend has no separate refresh token. `refresh-token` deletes the current token and returns a new one, and requires the current one to still be valid. If the rotation response is lost (network drop), the old token is already deleted → the user is logged out. That's acceptable once per launch, not on every resume. |
| D9 | **Idempotency key is generated once per confirmed intent, reused on retry, and never reused for a different intent.** | The single most important financial rule in the app (§7.2). Doubly important now: the backend does **not** compare payloads, so a reused key with a new amount silently returns the old transaction as a "success". |
| D10 | **App lock (`local_auth`) is an optional device-level guard, not a substitute for server-side PIN verification.** | G2 is still open. The client must never pretend to verify a PIN the server doesn't check. |
| D11 | **Counterparties:** agents are identified by **phone number** (QR or typed). Recipients of a transfer are identified by **user ID** (QR or typed) until G1 is fixed. | That is what the API accepts today. Agent phone numbers are public in this business anyway (they're printed on shop signs). |
| D12 | **Feature-first folder structure** (`features/auth`, `features/wallet`, …). | Same reasoning as the backend's `Modules/`: domain boundaries over technical layers. |
| D13 | **Environments via `--dart-define`** (`API_BASE_URL`). | Simplest thing that works. Add flavors when you ship to stores. |
| D14 | **Strings through `intl`/ARB from day one**, Bangla deferred. | Retrofitting i18n is painful; adding `app_bn.arb` later is cheap. |
| D15 | **Branch on HTTP status, never on message text.** Show the server `message` verbatim where §5.4 says so. | There is no machine-readable `code` field in the error envelope, and several messages in `docs/ERRORS.md` already differ from the code. String-matching would break silently. |
| D16 | **A newly registered agent's token is not used as a working agent session.** | Registration returns an `agent` token even while `agent_info.status = PENDING` (G5). The app routes to the pending screen and only an `agent/login` (which checks APPROVED) produces a session the agent shell may use. |

### Backend gaps and issues — decide before or alongside the app

These are **not Flutter tasks**. Each one is a small Laravel task, guided the usual way. Status verified against the current working tree.

| # | Gap / issue | Status | Impact on the app | Recommendation |
|---|---|---|---|---|
| **G0** | **In production (`APP_ENV=production`) the catch-all `Throwable` renderer in `bootstrap/app.php` turns validation errors (422) and throttle responses (429) into `500 "An error occurred."`.** Verified by rendering both exceptions with the environment set to production. | **Open — blocker** | Field errors, "phone already registered", "too many attempts" all become "Something went wrong" in production only. Every form in the app degrades. Local dev hides it. | Let `ValidationException`, `HttpResponseException`, `HttpExceptionInterface` (incl. invalid signature 403) and `AuthenticationException` fall through before the production catch-all. Add a test that runs with the production environment. Fix **before** any release, web or mobile. |
| G1 | No phone → recipient resolution for **transfer**. Cash-in/out already take `agent_phone`. | Half done | Users must type a numeric user ID or scan a QR to send money. | Make `TransferRequest` accept `recipient_phone` (mirror `agent_phone`), and optionally add `GET /api/v1/users/lookup?phone_number=…` (`ability:user`, throttled, returns `{ id, masked_name }`) so the Review step can show "Sending to R***l K***r". |
| G2 | **Transactions do not verify the PIN.** CLAUDE.md §10: "Same PIN for both login and transaction verification". No transaction Form Request accepts `pin`. | Open | A stolen unlocked phone (or token, which never expires) can move money. | Add `pin` to money-moving requests, verify in the service layer, throttle and lock out. Only then add a PIN step to Review. Until then the app must **not** ask for a PIN. **Do this before real money.** |
| G3 | Caps are not in `/wallets/me`; they come from `GET /users/{self}` → `data.user.caps` (strings). Caps are **one row per person, shared by both panels.** | Accepted | Home needs two calls. An agent's cash-outs as a customer and their customer transfers draw from the same cap. | Fine for now. Optionally embed caps in `WalletResource` later. |
| G4 | No fee rate or quote for non-admins. `system_fee_rate` (default 0.05) is admin-only. | Open | Cash-out can't show the exact fee before Confirm. The receipt shows it after. | Add `POST /transactions/cash-out/quote` (server computes fee; client displays). Better than exposing settings. |
| G5 | `POST /auth/register` with `role=AGENT` returns a working `agent` token while the agent is `PENDING`. `agent/login` correctly rejects PENDING. | Open (inconsistent) | A pending agent can call `/wallets/me`, `/transactions/history`, `/users/{self}`. Withdrawal fails 409. | Backend: for AGENT registration, return no token (or a token with no abilities) and a `status: PENDING` field. App: D16 regardless. |
| G6 | Docs drift. Known: `API.md` generic pagination says `data.items` (it's `data.transactions`); `/auth/me` docs show wrong middleware/fields; `WalletResource` docs omit `kind`, `blocked_by`, `blocked_at`, timestamps; `ERRORS.md` messages differ for invalid credentials, insufficient role, caps, invalid recipient, agent not approved; `UnverifiedAccountException` is 422 not 403. | Open | Don't trust docs blindly. | Before writing each model, hit the endpoint (`docs/postman_collection.json`) and save the **real** response into `test/fixtures/`. Fix the docs as you find drift. |
| G7 | Idempotency replay **does not compare the payload**, and for cash-in/cash-out/transfer the agent/recipient checks run **before** the replay lookup. | Open | (a) A key bug in the app = a fake "success" for a transaction that never happened. (b) A retry after a timeout can get 409/404 (e.g. agent suspended meanwhile) even though the original succeeded. | Backend: store a payload hash with the key; return 422/409 on mismatch; look up the key first. App: §7.2 handles (b) by never treating a 4xx on a *retry* as "definitely failed". |
| G8 | `amount` has no decimal-places or maximum rule; `top-up` has no limit and no caps. | Open | `"1.234"` would be accepted. Top-up can mint unlimited money. | Add `decimal:0,2` and a sane `max` to the money Form Requests; decide what top-up represents (demo vs real funding). |
| G9 | Case C PIN reset: the email is **not sent** (only `Log::info`), and the link is the **API** URL `POST /api/v1/auth/reset-pin/{user}?expires&signature` (15 min). No web landing page exists. | Open | A phone can't do anything useful with an API URL. | See §9.4: send a real mail, link to a landing URL, add a web fallback page. |
| G10 | Agent login returns the same 403 "You do not have permission to access this portal." for no role, PENDING, SUSPENDED and DELETED. | Open (optional) | The app can't tell "wait for approval" from "you're suspended". | Optional: distinct messages **after** the PIN check has passed (no enumeration risk at that point). App shows a generic "Agent access not available — contact support" until then. |
| G11 | Customer idempotency keys starting with `commission-payout-` can collide with the internal key written for that same user's cash-out commission row. | Open (backend) | None if the app uses plain UUID v4 (never has that prefix). | Backend: reject client keys with the reserved prefix, or store the internal key under a different initiator. |

---

## 1. Prerequisites — Learn Before You Build

You know Node.js, some Java, and now some Vue. Dart will feel like Java with Kotlin-ish ergonomics. Budget real time here.

### 1.1 Dart (2–4 hours)

- **Sound null safety:** `String` vs `String?`, `!`, `?.`, `??`, `late`. You'll need it immediately: many response keys are **absent** depending on who's looking (§7.8).
- **`final` vs `const`:** `const` constructors matter for rebuild performance.
- **Futures and `async`/`await`** — like JS Promises; errors are thrown, so `try`/`catch`.
- **Streams** (basic understanding only).
- **Classes, named and factory constructors** (used by `fromJson`).
- **Records and pattern matching** (`switch` expressions). Freezed unions use these.
- **Extension methods** (for `Decimal` formatting).

Resource: dart.dev/language → "Dart cheatsheet" codelab.

### 1.2 Flutter fundamentals (4–6 hours)

- Everything is a widget. `StatelessWidget` vs `StatefulWidget`.
- `build()` runs often. Never do I/O inside it.
- Layout: `Column`, `Row`, `Expanded`, `Padding`, `ListView.builder`, `SafeArea`, `Scaffold`.
- Forms: `Form`, `TextFormField`, `GlobalKey<FormState>`, `validator:`, `inputFormatters`.
- Material 3 theming: `ThemeData`, `ColorScheme.fromSeed`.

Resource: docs.flutter.dev → "Write your first Flutter app", "Layouts in Flutter".

### 1.3 Riverpod (2–3 hours)

- `Provider`, `FutureProvider`, `NotifierProvider` / `AsyncNotifierProvider`
- `ref.watch` (rebuild on change) vs `ref.read` (one-off, in callbacks)
- `AsyncValue.when(data:, loading:, error:)` — replaces the hand-rolled `loading`/`error` refs from Pinia
- `ref.invalidate(provider)` to refetch
- `autoDispose` (you'll scope idempotency keys with it)
- Code generation with `@riverpod`

**Vue → Flutter mental map:**

| Your web app | This app |
|---|---|
| `.vue` component | Widget |
| `ref()` / `reactive()` | `StatefulWidget` state or a small provider |
| Pinia store | Riverpod `Notifier` / `AsyncNotifier` |
| `computed` | A provider that `ref.watch`es others |
| Axios instance + interceptors | `Dio` + `Interceptor`s |
| Inertia pages + `useAuthGuard` | `go_router` + `redirect:` |
| Vee-Validate | `Form` + `validator:` |
| Toast store | `ScaffoldMessenger` helper |
| `localStorage` token | `flutter_secure_storage` |
| `authStore.can(...)` permissions | **Not used.** User/agent tokens carry no permissions; the panel is decided by the token ability. |

---

## 2. Environment Setup

### 2.1 Install tooling

1. Flutter SDK (stable), `flutter doctor` clean for at least Android.
2. Android Studio (SDK + emulator) and/or Xcode.
3. Flutter plugin for VS Code or Android Studio.
4. Android emulator (Pixel, API 34+).

### 2.2 Make the Laravel API reachable from a device

The backend runs under Sail, so it's on port 80 of the host unless you changed `APP_PORT`. Check `APP_URL`/`APP_PORT` in `.env`.

| Where the app runs | Base URL |
|---|---|
| Android emulator | `http://10.0.2.2[:APP_PORT]/api/v1` (`10.0.2.2` = host's localhost) |
| iOS simulator | `http://127.0.0.1[:APP_PORT]/api/v1` |
| Physical phone, same Wi-Fi | `http://<your-LAN-IP>[:APP_PORT]/api/v1` (Sail binds to all interfaces by default; allow the port in your firewall) |

- Android blocks cleartext HTTP. For **local dev only**, allow it through a debug-only `network_security_config.xml` scoped to your dev host. **Never** ship cleartext in release.
- CORS does not apply to native apps.

### 2.3 Repository location

**Decision:** a **separate repository** (e.g. `wallet-mobile`). Different toolchain, CI and release cadence; the contract is the HTTP API. If you insist on a monorepo, use `mobile/` at the root — never under `resources/`.

---

## 3. Project Creation & Dependencies

### 3.1 Create the project

```bash
flutter create --org com.yourcompany --platforms=android,ios wallet_mobile
```

### 3.2 Dependencies

Runtime:

| Package | Purpose |
|---|---|
| `flutter_riverpod`, `riverpod_annotation` | State (D2) |
| `dio` | HTTP (D3) |
| `go_router` | Routing (D4) |
| `freezed_annotation`, `json_annotation` | Models (D5) |
| `flutter_secure_storage` | Token storage (D7) |
| `decimal` | Money (D6) |
| `uuid` | Idempotency keys (D9) |
| `intl`, `flutter_localizations` (SDK) | Formatting, localisation (D14) |
| `qr_flutter` | Render "My QR" (§9.1) |
| `mobile_scanner` | Scan QR (§9.1) |
| `local_auth` | Optional app lock (D10) |

Dev:

| Package | Purpose |
|---|---|
| `build_runner` | Code generation |
| `freezed`, `json_serializable`, `riverpod_generator` | Generators |
| `riverpod_lint`, `custom_lint` | Catch Riverpod misuse |
| `mocktail` | Mocks |
| `http_mock_adapter` | Fake Dio responses |
| `flutter_lints` or `very_good_analysis` | Pick one, keep it strict |

Before adding anything else, ask the backend question: *what does it solve that the SDK or an existing dependency can't?*

### 3.3 Generation workflow

`dart run build_runner watch --delete-conflicting-outputs` while developing. **Decision:** commit generated `*.g.dart` / `*.freezed.dart` files.

---

## 4. Folder Structure

```
lib/
├── main.dart                     # ProviderScope, read env, run app
├── app.dart                      # MaterialApp.router, theme, localisation
├── core/
│   ├── config/env.dart           # API_BASE_URL from --dart-define
│   ├── network/
│   │   ├── dio_provider.dart
│   │   ├── auth_interceptor.dart
│   │   ├── error_interceptor.dart
│   │   └── api_exception.dart    # status, message, fieldErrors
│   ├── storage/secure_session_storage.dart
│   ├── money/money.dart          # Decimal parse/format/toApiAmount
│   ├── router/app_router.dart
│   ├── widgets/                  # AmountField, PhoneField, PinField, PrimaryButton,
│   │                             #   AsyncValueView, SkeletonList, ConfirmSheet
│   └── utils/validators.dart     # mirror backend rules
├── features/
│   ├── auth/                     # login, register, session, pending/blocked screens
│   ├── wallet/                   # /wallets/me, caps
│   ├── transactions/             # history, detail, direction logic, receipt
│   ├── money_flow/               # the shared §7.2 controller + screens
│   ├── user_ops/                 # top-up, send, cash-in, cash-out forms
│   ├── agent/                    # agent dashboard, withdrawal
│   ├── profile/
│   └── qr/
└── l10n/app_en.arb
test/
├── fixtures/                     # REAL responses saved from the running API
├── core/
└── features/
integration_test/
```

Each feature has `data/` (repositories: Dio → models or `ApiException`), `domain/` (freezed models, pure logic, no Flutter/Dio imports) and `presentation/` (screens, widgets, controllers; no raw Dio, no JSON). This is the mobile mirror of CLAUDE.md §6.

---

## 5. Core Infrastructure (build before any screen)

### 5.1 Environment config

- `String.fromEnvironment('API_BASE_URL')`; fail fast if empty.
- `flutter run --dart-define=API_BASE_URL=http://10.0.2.2/api/v1`
- Keep run configs in `.vscode/launch.json`.

### 5.2 Secure session storage

`SecureSessionStorage` stores: `token`, `abilities` (list), `panel` (`user`/`agent`), `userId`, `phoneNumber` (to prefill on panel switch). Methods: `read()`, `write(Session)`, `clear()`.

> **Rule (CLAUDE.md §9):** the token's **ability** decides what it may do and which wallet it sees — not the user's role list. A user holding both USER and AGENT has `roles: ["USER","AGENT"]` in every auth response; never route on `roles`. Route on `abilities`.

### 5.3 Dio + interceptors

One `Dio` via a provider:

- `baseUrl` from env; `connectTimeout` ~10s, `receiveTimeout` ~20s.
- Headers: `Accept: application/json` (without it Laravel may redirect instead of returning JSON errors).

Interceptors:

1. **`AuthInterceptor`** (`onRequest`): add `Authorization: Bearer <token>`.
2. **`ErrorInterceptor`** (`onError`): convert every `DioException` into `ApiException`. **Handle three body shapes:**
   - Standard envelope: `{ success: false, message, errors: {field: [msgs]} | [] }`
   - Validation (422): `{ message, errors }` — **no `success` key**
   - Laravel default (e.g. invalid signature 403): `{ message }` only

   Extract `statusCode`, `message` (fallback text if missing), `fieldErrors` (first message per field; `errors` may be an empty **list**, not a map — guard for it). Network/timeout errors become a distinct `NetworkException` (§7.2 treats them differently).
3. **`UnauthorizedInterceptor`**: on `401` from any endpoint except the login/register endpoints, clear storage and notify the session controller via a callback/provider (same idea as `setOnUnauthorizedCallback` in `api.ts`). The router then redirects to login.

**Never** auto-retry `POST`s in an interceptor. Money retries are explicit, same key (§7.2).

### 5.4 Error → UI mapping

One function turns an `ApiException` into UI. Branch on status (D15):

| Status | Where it comes from | UI |
|---|---|---|
| 401 | Bad token; on login: wrong phone/PIN, or no PIN set | Session over → login. On the login screen: "Incorrect phone number or PIN." |
| 403 | Blocked wallet, inactive account, missing role/not-approved agent (login), wrong ability, not your transaction, staff must change password, invalid/expired signature | Show the server message. Don't retry. Special-case the "must change your password" message only as a dedicated screen (§6.7) — this is the one place a message check is tolerated; keep it in one function with a test. |
| 404 | Agent phone not found / not an agent, wallet not found, transaction not found | Show the server message. |
| 409 | Agent not approved (cash-in/out, withdrawal) | Show the server message. |
| 422 | Field validation **or** business rule (insufficient balance, cap exceeded, self-transfer, recipient not a user, agent short of balance for cash-in, unverified account) | `fieldErrors` non-empty → under the fields. Otherwise a dialog with the message verbatim (cap messages include the cap and usage). |
| 429 | 5 attempts/min on login & register | "Too many attempts, try again in a minute." Disable the button ~60s. |
| 5xx | — | "Something went wrong." Never show server text. (In production, G0 currently makes 422/429 look like this.) |
| Network/timeout | — | "No connection." On a money call → unknown-outcome flow (§7.2). |

### 5.5 Money helpers (`core/money/money.dart`)

- `Decimal parseMoney(Object? json)`: accepts `num` or `String` (both occur, D6). Convert via `toString()` then `Decimal.parse`.
- `String formatBdt(Decimal)`: `৳1,234.50` via `intl` `NumberFormat`, 2 fraction digits.
- `String toApiAmount(Decimal)`: `"1234.50"`.
- `String formatRate(Object? json)`: `0.0100` → `1%`, `0.05` → `5%` (up to 2 decimals).

Unit-test these **first**.

### 5.6 Client validators (mirror the Form Requests, not memory)

| Field | Backend rule (source) | Client |
|---|---|---|
| `phone_number`, `agent_phone` | `regex:/^01[3-9]\d{8}$/` (`PinLoginRequest`, `RegisterRequest`, `CashInRequest`, `CashOutRequest`) | Same regex, `TextInputType.phone`, 11 digits. |
| `pin` | `string`, `digits_between:5,10` | 5–10 digits, `digitsOnly`, `obscureText`, send as **string** (leading zeros). |
| `pin_confirmation` | `confirmed` | Equals `pin`. |
| `name` (register) | `required|string|min:2|max:255` | Same. |
| `role` (register) | `in:USER,AGENT` | Segmented control. |
| `amount` | `numeric|gt:0` (no decimals rule — G8) | Positive, **max 2 decimals** (stricter than server on purpose), `Decimal.tryParse`. |
| `description` | `nullable|string|max:500` | Optional, `maxLength: 500`. |
| `recipient_id` | `integer|exists`, ≠ own ID | Positive integer, ≠ session `userId`. |
| `agent_phone` | ≠ own phone ("The agent must be a different user.") | ≠ session `phoneNumber`. |
| `idempotency_key` | `required|string|max:255` | UUID v4, generated by the flow controller, never typed. |

Client validation is UX. The server is the authority (CLAUDE.md §17).

### 5.7 Router + guard

```
/splash
/login
/register
/agent-pending          (registered agent, or agent login 403)
/account-action-needed  (staff must change password, §6.7)
/forgot-pin             (info screen, Case C)
/reset-pin              (deep link, §9.4)
/user                   (ShellRoute, bottom nav)
  /user/home
  /user/send
  /user/cash-in
  /user/cash-out
  /user/top-up
  /user/history
  /user/history/:id
  /user/my-qr
  /user/profile
/agent                  (ShellRoute, bottom nav)
  /agent/home
  /agent/withdraw
  /agent/history
  /agent/history/:id
  /agent/my-qr
  /agent/profile
```

`redirect:` (the `useAuthGuard` equivalent):

1. Session loading → `/splash`.
2. No session and target not public → `/login`.
3. Ability `user` opening `/agent/**` → `/user/home`, and vice versa.
4. Logged in and on `/login` or `/register` → own panel home.

Use a Riverpod-driven `refreshListenable` so a 401-triggered logout re-runs the guard.

### 5.8 Shared widgets

- `AmountField` (decimal keyboard, `৳` prefix, 2-decimal formatter)
- `PhoneField` (11 digits, BD regex) — used for login, register and agent phone
- `PinField` (obscured, digits only, 5–10, no autocorrect/suggestions)
- `PrimaryButton` (spinner + **disabled while loading** → no double taps)
- `AsyncValueView<T>` (skeleton / error + retry / data)
- `SkeletonList` (animated opacity, no package)
- `ConfirmSheet` (summary, Confirm, Cancel)
- `showSuccess` / `showError`
- `BlockedWalletBanner`

---

## 6. Authentication Feature

### 6.1 Models

- `AuthUser`: `id`, `name`, `phoneNumber`, `email?`, `roles: List<String>`, `isVerified`, `isActive` (`"ACTIVE"|"INACTIVE"`). Don't model `permissions` (always `[]` for these tokens).
- `Session`: `token`, `abilities`, `user`, getter `panel` (`agent` if `abilities` contains `agent`, else `user`).

Use `fieldRename: FieldRename.snake`.

Real response shape (login, register, refresh — `AuthResource`):

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { "id": 7, "name": "…", "phone_number": "017…", "email": null,
              "roles": ["USER"], "permissions": [], "is_verified": true, "is_active": "ACTIVE" },
    "token": "12|plain…",
    "token_type": "Bearer",
    "abilities": ["user"]
  }
}
```

`GET /auth/me` returns the same shape with `token: null`, `token_type: null` and the current token's `abilities`.

### 6.2 `AuthRepository`

| Method | Endpoint | Body | Returns |
|---|---|---|---|
| `loginUser(phone, pin)` | `POST /auth/user/login` | `phone_number`, `pin` | `Session` (200) |
| `loginAgent(phone, pin)` | `POST /auth/agent/login` | `phone_number`, `pin` | `Session` (200) |
| `register(name, phone, pin, pinConfirmation, role)` | `POST /auth/register` | `name`, `phone_number`, `pin`, `pin_confirmation`, `role` (`USER`/`AGENT`) | `Session` (201) |
| `me()` | `GET /auth/me` | — | `AuthUser` + `abilities` |
| `refresh()` | `POST /auth/refresh-token` | — | new `token` + `abilities` |
| `logout()` | `POST /auth/logout` | — | void |

Login failure order on the server: unknown phone / no PIN / wrong PIN → **401** (same message, constant time); inactive account → **403**; missing role (user route) or missing role / not APPROVED (agent route) → **403**.

### 6.3 `SessionController` (`AsyncNotifier<Session?>`)

- `build()`: read storage. If a token exists, call `me()`:
  - 200 → session restored (update stored user). Then, once per launch, `refresh()` in the background and store the rotated token (D8).
  - 401 → clear storage, `null`.
  - 403 "must change your password" → keep session but route to `/account-action-needed`.
  - Network error → keep the stored session, screens show "offline".
- `login(...)` / `register(...)`: call repo, persist, update state.
- `logout()`: call `/auth/logout` (ignore failures), **always** clear storage, **invalidate every user-scoped provider** (wallet, caps, history, profile, money flows), set `null`.
- `switchPanel()`: `logout()` then go to `/login` with the other panel selected and the phone prefilled.

> On logout or panel switch, clear every cached provider. Otherwise the USER wallet balance flashes on the agent dashboard (same person, different wallet) or user A's data flashes for user B.

### 6.4 Login screen

- Segmented control **Customer | Agent** → selects the endpoint.
- Phone + PIN, validators from §5.6, button disabled in flight.
- 401 → "Incorrect phone number or PIN." (don't reveal which).
- 403 on the **agent** route → `/agent-pending` with generic copy: "Agent access isn't available for this number yet. If you registered recently, an admin must approve you first. Otherwise contact support." (The server can't tell you which, G10.)
- 403 on the **user** route → server message ("…inactive" or "…permission to access this portal").
- 429 → cooldown.
- "Forgot PIN?" → `/forgot-pin` info screen: PIN resets go through customer care (Case C). **No** self-service form exists or should exist.

### 6.5 Registration screen

- Name, phone, PIN, confirm PIN, role (Customer / Agent).
- 201 with `USER` → persist session → `/user/home`, show "Welcome — ৳50.00 starting balance".
- 201 with `AGENT` → **do not persist** the token as an agent session (D16, G5). Call `/auth/logout` with it to revoke it, then `/agent-pending`.
- 422 `errors.phone_number` ("This phone number is already registered.") under the phone field.

### 6.6 Splash

Logo while `SessionController.build()` runs; router redirects. If `me()` is slow (>1–2s), route on the stored session and let screens refresh.

### 6.7 Account-action-needed screen

Reached when any call returns 403 "You must change your password before proceeding." This only happens to a **staff** member with an unfinished invite who also holds USER/AGENT and logged in by PIN. Copy: "Finish setting up your staff account on the web, then sign in again." Button: Log out.

---

## 7. User Panel (token ability `user`, USER wallet)

Bottom navigation: **Home · Send · History · Profile**. Cash-in, Cash-out, Top-up and My QR are Home quick actions.

### 7.1 Home / Wallet

Data:

- `GET /wallets/me` → `data.wallet`: `id`, `user_id`, `kind` (`"USER"`), `balance` (float), `currency`, `is_blocked`, `blocked_by`, `blocked_at`, timestamps. Note there is **no `message`** key in this response.
- `GET /transactions/history?per_page=5`
- `GET /users/{self}` → `data.user.caps` (`daily_cap`, `monthly_cap`, `daily_used`, `monthly_used`, all **strings**) (G3)

UI:

- Balance card, tap-to-reveal.
- `is_blocked` → red banner "Your wallet is blocked. Contact support." and disable **all** money actions, including Top-up and Cash-in: a blocked wallet can't receive either. The server enforces it anyway (403).
- 404 on `/wallets/me` → "No customer wallet on this account. Contact support." (possible for an account given the USER role by an admin before its wallet existed — rare, but handle it instead of crashing).
- Quick actions, recent 5 → "See all", pull-to-refresh.
- Caps card with progress bars. Label it "Daily/monthly limit (shared across your accounts)" if the user also holds AGENT.

### 7.2 The money-movement pattern (applies to 7.3–7.6 and 8.3)

The most important section. Build it once as a reusable controller + screens.

```
[1. Form] → [2. Review] → [3. Submitting] → [4a. Success receipt]
                                          ↘ [4b. Failed (definite)]
                                          ↘ [4c. Unknown outcome]
```

**Stage 1 — Form.** Client validation. "Continue" doesn't call the API.

**Stage 2 — Review.** Counterparty, amount, and (cash-out) the fee notice (G4). Generate the idempotency key **here** (`Uuid().v4()`), store it in the flow controller. If the user goes back and changes **anything** in the payload (amount, recipient, agent phone, description), generate a **new** key. This matters more than in v1: the server doesn't compare payloads (G7), so a stale key with a new amount returns the *old* transaction as a success.

**Stage 3 — Submitting.** Disable Confirm, show progress, block back (`PopScope(canPop: false)`).

**Stage 4a — Success (201).** Parse `data.transaction`. Receipt: ID, type, amount, fee, counterparty, **your** balance after, local time. Then invalidate wallet, caps and history providers; discard the key.
- Sanity check: compare the receipt's `amount`/type with what was submitted. A mismatch means a key was reused for a different intent (an app bug — G7). Log it and show the real transaction, never the submitted values.

**Stage 4b — Definite failure.** A 4xx on the **first** attempt. Map with §5.4. Money did not move; the user may edit and retry; a changed intent gets a new key.

**Stage 4c — Unknown outcome.** Timeout / connection dropped after sending. Don't say "failed". Show "We couldn't confirm this transaction. Checking…" and **retry the same request with the same key** (backoff, ~3 tries). The backend returns the original transaction (201) for a repeated key, so retries can't double-charge.
- **A 4xx on a retry is not a definite failure** (G7): the server runs agent/recipient checks before the replay lookup, so e.g. a 409 "agent not approved" on retry can hide an original success. Treat any non-201 on a retry as "Status unknown — check your history before trying again" and link to History.
- Never generate a new key in 4c.

> Without 4c, a user on flaky 3G taps Send, times out, taps Send again with a fresh key, and pays twice — the double-spend CLAUDE.md §7/§18 warns about, on the client side.

**Also:**

- The key lives in an `autoDispose` flow-scoped provider; it dies when the flow closes.
- Keys are plain UUID v4 (never the reserved `commission-payout-` prefix, G11).
- Amounts via `toApiAmount()` as strings.
- No PIN prompt on Review until G2 is done.

### 7.3 Send Money (transfer)

- Recipient: **user ID** typed, or scanned from a `user` QR (§9.1). After G1: phone number with masked-name confirmation.
- Guard: ≠ own ID ("You can't send money to yourself"); server also returns 422.
- `POST /transactions/transfer` — `recipient_id` (int), `amount`, `idempotency_key`, `description?`.
- Debits your USER wallet, credits the recipient's **USER** wallet. Sending to someone who is only an agent fails.
- Errors: insufficient balance (422), daily/monthly cap (422, show verbatim), recipient doesn't exist (422 field error on `recipient_id`), recipient not a user / no user wallet (422), either wallet blocked (403).

### 7.4 Cash-Out (to an agent)

- Agent: scan the agent's QR (primary) or type the **agent's phone number**.
- Guard: ≠ own phone.
- Fee: until G4, show "A cash-out fee applies and is deducted in addition to the amount. The exact fee appears on your receipt." **Never hardcode a percentage** (default is 5% but admins change it).
- `POST /transactions/cash-out` — `agent_phone`, `amount`, `idempotency_key`, `description?`.
- Receipt: `amount`, `system_fee_amount`, total debited = `amount + system_fee_amount`.
- Errors: agent phone not found / not an agent (404), agent not approved (409), insufficient balance for amount + fee (422), caps (422, applied to `amount`), blocked wallet on either side (403).

### 7.5 Cash-In (from an agent)

- Same agent input as cash-out.
- `POST /transactions/cash-in` — `agent_phone`, `amount`, `idempotency_key`, `description?`. Free; no caps.
- Debits the agent's AGENT wallet, credits your USER wallet.
- Errors: agent has insufficient balance (422), agent not approved (409), not found (404), blocked (403).

> **Domain note (unchanged):** here the **customer** initiates cash-in by naming the agent. In real bKash the *agent* initiates it to the customer's number. Keep the API as-is but flag it for a product review; agent-initiated cash-in is a backend decision.

### 7.6 Top-Up

- `POST /transactions/top-up` — `amount`, `idempotency_key`, `description?`. Credits your USER wallet; no fee, no caps.
- Label it **"Demo top-up"**. It has no funding source and no limit (G8); it must not look like a bank or card payment.

### 7.7 Transaction History

- `GET /transactions/history` — `page`, `per_page` (use 20; max 100), `type?` (`TOP_UP`, `CASH_IN`, `CASH_OUT`, `TRANSFER`, `AGENT_WITHDRAWAL`, `COMMISSION_PAYOUT`), `status?`, `from_date?`, `to_date?` (`YYYY-MM-DD`, whole days).
- Response: `data.transactions` + `data.pagination` (`total`, `per_page`, `current_page`, `last_page`, `from`, `to`). Newest first.
- Wallet-scoped: a user token sees TOP_UP, TRANSFER (sent and received), CASH_IN, CASH_OUT. Filter chips for the user panel: Send/Receive (TRANSFER), Cash-in, Cash-out, Top-up. No COMMISSION_PAYOUT chip here.
- Infinite scroll via an `AsyncNotifier` (`items`, `currentPage`, `hasMore`, `isLoadingMore`); stop at `current_page == last_page`. Filter change or pull-to-refresh → page 1.
- Row: icon, counterparty, signed amount (+ green credit / − debit), local time.
- **Direction logic** — a pure `domain/` function. You can't compare wallet IDs (they're hidden from non-staff), but you don't need to, because history is already scoped to one wallet:

  | Panel | Type | Direction |
  |---|---|---|
  | user | TOP_UP | credit |
  | user | CASH_IN | credit |
  | user | CASH_OUT | debit (`amount + system_fee_amount`) |
  | user | TRANSFER | debit if `sender_id == myUserId`, else credit |
  | agent | CASH_IN | debit |
  | agent | CASH_OUT | credit (`amount`; commission arrives as its own row) |
  | agent | COMMISSION_PAYOUT | credit |
  | agent | AGENT_WITHDRAWAL | debit |

  Signature: `Direction directionFor(Transaction tx, {required Panel panel, required int myUserId})`. Unit-test every row, plus "unknown type → neutral, no sign" so a new backend type can't crash the list.
- Empty state: "No transactions yet".

### 7.8 Transaction Detail

- `GET /transactions/{id}` → `data.transaction`.
- **Model with nullable fields for everything that can be absent:** `sender_wallet_balance_after`, `recipient_wallet_balance_after`, `agent_wallet_balance_after` (only your side is present), `agent_id`, `sender_id`, `recipient_id`, `user_id`, `description`, `meta`. `*_wallet_id` keys never appear for you; don't model them.
- "Your balance after" = whichever `*_balance_after` key is present.
- Show: type, status (`COMPLETED`/`FAILED`), amount, fee (`system_fee_amount`), commission (agent panel only), counterparty, your balance after, description, time, ID.
- 403 → "Not available" (not your wallet's transaction); 404 → "Not found".
- Optional: "Share receipt".

### 7.9 Profile

- `GET /users/{self}` → name, phone, email, address, verification, roles. (It also returns **both** wallets and `agent_info` regardless of token; ignore what doesn't belong to the current panel.)
- Edit **name and address** via `PATCH /users/{self}`. `email`, `phone_number`, `password`, `role` are prohibited (422) — don't offer them.
- My QR (§9.1).
- "Change PIN" → info text (Case C, customer care). No self-service form.
- "Switch to Agent panel" — shown only if `roles` contains `AGENT` (D1, `switchPanel()`).
- App lock toggle (§9.3).
- Logout (confirm first).

---

## 8. Agent Panel (token ability `agent`, AGENT wallet)

Bottom navigation: **Home · Withdraw · History · Profile**, with a big **My QR** on Home.

### 8.1 Pending / unavailable state (`/agent-pending`)

- Reached after AGENT registration (§6.5) or agent login 403 (§6.4).
- Copy explains approval, offers "Contact support", "Try signing in again" (→ agent login, prefilled), and — if the person also holds USER — "Use the customer app" (→ user login).
- No wallet or transaction UI, no API calls with a registration token.

### 8.2 Agent Home / Dashboard

Data:

- `GET /wallets/me` → AGENT wallet (`kind: "AGENT"`), `is_blocked`.
- `GET /users/{self}` → `data.user.agent_info`: `status`, `commission_rate` (string, e.g. `"0.0100"`), `total_commission` (string), `approved_at`.
- `GET /transactions/history?per_page=5`.

UI:

- Balance card (tap to reveal). Note: an agent whose AGENT role was added by an admin starts at **৳0.00**; one who self-registered starts at **৳50.00**.
- Commission card: total earned, current rate (`formatRate`).
- Status badge (will be APPROVED here, since login requires it).
- **My QR** button.
- Recent 5 → "See all". Blocked banner as in the user panel.
- Low-balance hint: cash-in to customers is paid **from this wallet**; if it's low, cash-ins fail with 422 on the customer's phone.

### 8.3 Agent Withdrawal

- §7.2 pattern exactly.
- `POST /transactions/agent/withdrawal` — `amount`, `idempotency_key`, `description?`. Debits the AGENT wallet; no fee, no caps.
- Show available balance; warn client-side if amount > balance; server is the authority.
- Errors: insufficient balance (422), blocked (403), agent no longer approved (409).

### 8.4 Agent History & Detail

- Reuse §7.7 / §7.8 widgets with `panel: agent`. The agent sees CASH_IN, CASH_OUT, COMMISSION_PAYOUT and AGENT_WITHDRAWAL from the AGENT wallet's side.
- Each cash-out appears **twice** in agent history: the CASH_OUT row (credit `amount`) and a COMMISSION_PAYOUT row (credit commission; `meta.related_transaction_id` points to the cash-out). Show both, and link the detail screens through `meta.related_transaction_id`.
- Filter chips: Cash-in, Cash-out, Commission, Withdrawal.

### 8.5 Agent Profile

As §7.9 (name/address edit, logout, app lock, My QR) plus read-only commission rate and approval date, and "Switch to Customer panel" if `roles` contains `USER`.

---

## 9. Cross-Cutting Features

### 9.1 QR codes

**Payload:** a versioned, namespaced string so random QR codes can't be mistaken for payment targets, and carrying exactly what the API accepts (D11):

```
walletms:v1:agent:<phone_number>     e.g. walletms:v1:agent:01712345678
walletms:v1:user:<user_id>           e.g. walletms:v1:user:42
```

- **My QR**: agent panel encodes the agent's phone; user panel encodes the user ID. Render with `qr_flutter`, show name + identifier underneath.
- **Scanner** (`mobile_scanner`): parse prefix, version and type; validate the value (phone regex / positive int).
  - Send accepts only `user:`; Cash-in and Cash-out accept only `agent:` ("This is not an agent QR").
  - A scan **always** lands on Review. Never auto-submit.
- When G1 lands (transfer by phone), add `walletms:v2:user:<phone>` and keep parsing v1 for printed codes already in circulation.
- Camera permission with a clear rationale (Android manifest, iOS `NSCameraUsageDescription`).

> QR codes carry only public identifiers. The prefix check is UX/mistake-prevention, not security.

### 9.2 Session lifecycle & app resume

- Resume after ≥5 minutes in background: app lock (§9.3) if on; otherwise `GET /auth/me` to confirm the session.
- Any 401 → clear session → login.
- Tokens never expire server-side (D7). Logout is the only revocation the user controls, so make it easy to find.

### 9.3 App lock (optional, D10)

- `local_auth`, biometrics with device-credential fallback. Off by default, toggled in Profile.
- On cold start (with a session) and on resume after N minutes.
- Copy: this protects **the app on this device**; it is not your wallet PIN.

### 9.4 PIN reset deep link (Case C) — needs G9 first

Today the backend only **logs** a signed **API** URL: `POST {APP_URL}/api/v1/auth/reset-pin/{user}?expires=…&signature=…`, valid 15 minutes, body `pin` + `pin_confirmation`.

**Plan:**

1. **Backend (G9):** actually send the email (Notification/Mailable, queued), and link to a landing URL, e.g. `https://app.yourdomain/reset-pin?link=<urlencoded signed API URL>`.
2. **Web fallback:** a small public Vue/Inertia page at that URL with PIN + confirm that POSTs to the embedded signed URL. Works on any device; satisfies the requirement alone.
3. **App:** Android App Links / iOS Universal Links for that host+path. `go_router` maps `/reset-pin` to a screen that reads `link`, shows PIN + confirm, and POSTs to it.
   - **Validate `link`:** must start with the configured `API_BASE_URL` and the path must match `/auth/reset-pin/<digits>`. Otherwise refuse. This stops a crafted link from making the app send a new PIN to an attacker's server.
   - Don't attach the session token to this request (it's authorised by the signature, not by a login).
   - Success → "PIN updated, please sign in."
   - 403 (`{ "message": "Invalid signature." }`, non-envelope) → "This link has expired or is invalid. Contact customer care for a new one."
   - Consider the 15-minute expiry in the copy of the email.

Build this last.

### 9.5 Localisation & formatting

- All strings from `app_en.arb`.
- Currency via `intl` (`৳`, 2 decimals, separators).
- Dates: API returns UTC ISO-8601 → `DateTime.parse(...).toLocal()` → `DateFormat`.
- `app_bn.arb` later, no code changes.

### 9.6 Mobile security checklist

- Token only in `flutter_secure_storage`. Tokens never expire, so this matters more, not less.
- Never log the token, PIN, signed reset URLs, or bodies of auth/money calls. Strip `Authorization` in any logging interceptor; logging only in debug builds.
- `obscureText` on PIN; no autocorrect/suggestions on PIN and phone.
- Release: HTTPS only. Certificate pinning optional (operational cost on rotation).
- Android: `allowBackup="false"` or exclude secure storage from backups.
- Optional: `FLAG_SECURE` on balance, receipt and PIN screens.
- Obfuscate release builds (`--obfuscate --split-debug-info=…`).
- Validate deep-link targets (§9.4) and QR payloads (§9.1).
- The UI hides; the server forbids. Never rely on client checks for authorisation.

---

## 10. Testing

Mirror the backend's focus on financial invariants (CLAUDE.md §22).

### 10.1 Unit tests (most of your tests)

- `money.dart`: `num` and `String` inputs (`50`, `50.5`, `"50.00"`, `"0.0100"`), formatting, `toApiAmount`, no float drift.
- Validators: phone (`012…` rejected, `019…` accepted, 10/11/12 digits), PIN lengths 4/5/10/11 and leading zero, amounts `0`, `-1`, `1.234`, `abc`, own ID / own phone.
- `directionFor`: every row of the §7.7 table, plus an unknown type.
- `ApiException` mapping from **real** fixture bodies: envelope, validation (no `success`), `{message}` only, `errors: []` as a list.
- QR parser: valid v1 agent/user, wrong prefix, wrong version, wrong type for the screen, bad phone, non-numeric ID.
- Deep-link validator: correct host+path accepted; other host, other path, `http` vs `https` mismatch rejected.

### 10.2 Repository tests (`http_mock_adapter`)

- Correct path, method and body keys (`agent_phone`, `recipient_id`, `idempotency_key`, `pin_confirmation`, …).
- Parses real fixtures, including a history page where `*_balance_after` keys are **absent**.
- 401/403/404/409/422/429 → correct `ApiException`.

### 10.3 Controller tests (`ProviderContainer`, no widgets)

- `SessionController`: login persists; `me()` 401 → null session; logout clears storage **and** invalidates wallet/caps/history; `switchPanel` clears everything and preselects the other panel; AGENT registration doesn't produce an agent session (D16).
- **Money-flow controller (critical):**
  - Key created on entering Review; **identical** across timeout → retry.
  - Any payload change after going back → **new** key.
  - First-attempt 4xx → failed state, new attempt allowed.
  - Timeout → unknown → retry same key → success once.
  - Timeout → retry returns 409 → **unknown** state, not failed (G7).
  - Receipt mismatch (returned amount ≠ submitted) is detected.
  - Confirm can't fire twice while submitting.

### 10.4 Widget tests

- Login: field errors, disabled button while loading, agent 403 routes to pending.
- Home: blocked wallet → banner + all money actions disabled.
- History: skeleton → list → empty → error with retry.

### 10.5 Integration tests (`integration_test/`, real local backend)

Seed via Laravel seeders: a user with balance, an approved agent with balance, a person holding both USER and AGENT.

- Register user → ৳50 → transfer to another user → balance decreases → history shows it.
- Cash-out to the approved agent by phone → user debited amount + fee; agent panel shows CASH_OUT and COMMISSION_PAYOUT.
- Dual-role person: user panel and agent panel show **different** balances and histories; switching panel never flashes the other wallet.
- Agent: withdraw → balance decreases.

Run before each release, not every commit.

### 10.6 Manual test matrix

| Scenario | Expected |
|---|---|
| Airplane mode, then Confirm | Unknown-outcome flow; no duplicate when the network returns |
| Double-tap Confirm | Exactly one transaction |
| Edit amount after Review, confirm | New key; new transaction with the new amount |
| Wallet blocked by admin while open | Next money call → 403 message; banner after refresh |
| Only the AGENT wallet blocked (dual-role person) | Agent panel blocked; user panel unaffected |
| Agent suspended while a customer is on Review | 409 on first attempt → definite failure message |
| Kill the app on the receipt, reopen | Balance correct, no duplicate |
| Log out as A, log in as B | No A data at any point |
| Register as agent | Pending screen; no dashboard, no wallet calls |
| Staff with unfinished invite logs in by PIN | Account-action-needed screen |
| Production build against a production-mode backend, submit invalid form | Field errors shown (fails today because of G0) |
| Rotate on every screen; 5" screen; large font | No lost input, no overflow |

---

## 11. Build & Release

1. Icons and splash (`flutter_launcher_icons`, `flutter_native_splash`, dev-only).
2. `applicationId` / bundle ID, version in `pubspec.yaml`.
3. Signing (keystore never committed), iOS team.
4. `flutter build appbundle --release --obfuscate --split-debug-info=build/symbols --dart-define=API_BASE_URL=https://api.yourdomain/api/v1`
5. CI: `flutter analyze`, `flutter test`, build on every PR.
6. Play internal testing / TestFlight before public release.
7. Crash reporting (Crashlytics or Sentry) — optional but recommended. **Scrub PII, tokens and URLs with signatures.**

**Release gate (backend):** G0 fixed, G2 fixed, G8 decided. Don't put real money behind this app before those three.

---

## 12. Implementation Order

Each step ends runnable and committed. Bring each one back for review.

| Step | Deliverable | Depends on |
|---|---|---|
| 1 | Dart + Flutter + Riverpod learning (§1); throwaway app | — |
| 2 | Project, deps, strict lints, folder skeleton (§3–4) | 1 |
| 3 | Env, secure storage, Dio + interceptors, `ApiException` with all three body shapes (§5.1–5.4) + tests | 2 |
| 4 | Money helpers + validators (§5.5–5.6), **tests first** | 2 |
| 5 | Save real fixtures for every endpoint you'll use, from both a `user` and an `agent` token (G6) | backend running |
| 6 | Models: `AuthUser`, `Session`, `Wallet`, `Caps`, `AgentInfo`, `Transaction` (nullable absent keys), `Pagination` + fixture parsing tests | 4, 5 |
| 7 | Router, guard, splash, placeholder screens (§5.7) | 3 |
| 8 | Auth: repo, `SessionController`, Login, Register, pending, account-action-needed (§6) | 6, 7 |
| 9 | Shared widgets (§5.8) | 4 |
| 10 | User Home (§7.1) | 8, 9 |
| 11 | **Money-flow controller + tests (§7.2)** before any money screen | 9 |
| 12 | Top-up (§7.6) — proves step 11 end-to-end | 11 |
| 13 | Send by user ID (§7.3) | 12 |
| 14 | Cash-out and Cash-in by agent phone (§7.4–7.5) | 13 |
| 15 | History + direction logic + Detail (§7.7–7.8) | 10 |
| 16 | Profile + panel switch (§7.9) | 10 |
| 17 | Agent Home, Withdrawal, History, Profile (§8) — mostly reuse | 11, 15 |
| 18 | QR show + scan wired into Send, Cash-in, Cash-out (§9.1) | 13, 14, 17 |
| 19 | Resume handling, app lock (§9.2–9.3) | 8 |
| 20 | Localisation pass (§9.5) | all screens |
| 21 | Widget + integration tests, manual matrix (§10) | all |
| 22 | PIN-reset deep link (§9.4) | G9 |
| 23 | Release pipeline (§11) | release gate |

**Backend tasks to schedule alongside** (each its own small Laravel task):

| When | Gap |
|---|---|
| Now (affects the web app too) | **G0** production error masking |
| Before real money | **G2** transaction PIN, **G8** amount precision/limits, **G7** idempotency payload check + lookup order |
| Before step 8 polish | **G5** no agent token for pending registrations; optionally **G10** |
| Before step 13 (if you want phone-based send) | **G1** |
| Before cash-out polish (step 14) | **G4** quote endpoint |
| Before step 22 | **G9** send the reset mail + landing URL |
| Any time | **G6** docs drift, **G11** reserved key prefix |

---

## 13. Checklist

- [ ] Flutter + Riverpod fundamentals (§1)
- [ ] Project, deps, lints, structure (§3–4)
- [ ] Dio + interceptors + typed errors (three body shapes) + secure storage (§5.1–5.4)
- [ ] `Decimal` everywhere; parses both float and string amounts (§5.5)
- [ ] Validators mirror the Form Requests, incl. `agent_phone` and own-ID/own-phone guards (§5.6)
- [ ] Router guard routes on **token ability**, never on `roles` (§5.7)
- [ ] Login (user/agent), Register, agent-pending, account-action-needed (§6)
- [ ] AGENT registration never yields a usable agent session (D16)
- [ ] Logout / panel switch clears storage **and** all cached providers
- [ ] User Home with blocked-wallet and missing-wallet handling (§7.1)
- [ ] Money flow: key per intent, reused on retry, new on any payload change, 4xx-on-retry = unknown (§7.2)
- [ ] Top-up, Send, Cash-in, Cash-out (§7.3–7.6)
- [ ] History reads `data.transactions`; direction table fully unit-tested (§7.7)
- [ ] Detail handles absent `*_balance_after` keys (§7.8)
- [ ] Profile with Case C info and panel switch (§7.9)
- [ ] Agent Home, Withdrawal, History (with linked commission rows), Profile (§8)
- [ ] QR v1 (agent phone / user ID), validated, never auto-submits (§9.1)
- [ ] Resume handling + optional app lock (§9.2–9.3)
- [ ] All strings localised; UTC → local (§9.5)
- [ ] Mobile security checklist (§9.6)
- [ ] Unit, repository, controller, widget, integration tests (§10)
- [ ] Manual matrix passed (§10.6)
- [ ] Backend gaps G0–G11 each fixed or consciously deferred; release gate met (§11)

---

## Notes

- Build the **money-movement pattern (§7.2) once and well**. Every financial screen is the same flow with different fields.
- **Token ability = panel = wallet.** If you ever find yourself reading `roles` to decide which wallet or screen to show, stop.
- When a response doesn't match expectations, check the real response before changing code. The docs have drifted before (G6).
- Keep business rules on the server. The app collects input, shows what the server says, and never moves money twice.
- Same workflow as the backend: implement a step, then bring it back for review.
