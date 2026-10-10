# Task 12: Flutter App — User & Agent Panels

**Status:** Not started
**Estimated Duration:** 25–40 hours (spread over several weeks if Flutter is new to you)
**Difficulty:** Medium–High (new language, new framework, financial UX)

---

## 0. Scope & Decisions Log

### What this app is

One Flutter app that serves **two panels**:

| Panel | Login route | Token ability | Who |
|---|---|---|---|
| User | `POST /api/v1/auth/user/login` | `user` | Customers (`USER` role) |
| Agent | `POST /api/v1/auth/agent/login` | `agent` | Approved agents (`AGENT` role, `agent_info.status = APPROVED`) |

The **admin panel is out of scope**. It stays on the web, because password and OTP admin work is a desk task, and keeping it off phones shrinks the attack surface.

### Decisions I made for you (and why)

Where the requirements were ambiguous, I took the decision below. Revisit any of them deliberately rather than drifting away from them.

| # | Decision | Reason |
|---|---|---|
| D1 | **One app, two panels**, chosen on the login screen (Customer / Agent toggle), not two separate apps. | Mirrors the backend's three-route model. One codebase, one release pipeline. A user holding both roles can log out and log in on the other route. |
| D2 | **State management: Riverpod** (`flutter_riverpod` + `riverpod_annotation`). | Compile-safe, testable without a widget tree, no `BuildContext` needed in business logic. It is the most common choice in production Flutter today. Pinia knowledge transfers reasonably well: a provider is roughly a store. |
| D3 | **HTTP: `dio`.** | Interceptors map 1:1 to the Axios interceptors you already wrote in `resources/js/services/api.ts`. It also has timeouts, cancellation and typed errors. |
| D4 | **Routing: `go_router`.** | Official Flutter team package. Declarative routes, redirect guards (the Flutter equivalent of `useAuthGuard`), and deep-link support, which you need for the PIN-reset signed link (step 9.4). |
| D5 | **Models: `freezed` + `json_serializable`.** | Immutable models, `copyWith`, union types for UI states, and generated JSON parsing. Hand-written `fromJson` for ~6 models is error-prone. |
| D6 | **Money: the `decimal` package, never `double`.** | The API serialises amounts as JSON numbers (`(float)` casts in `TransactionResource`/`WalletResource`). Parse them into `Decimal` immediately and do all arithmetic and display from `Decimal`. Send amounts back as **strings with 2 decimals** (`"500.00"`), which Laravel's `numeric` rule accepts. |
| D7 | **Token storage: `flutter_secure_storage`** (Keychain / Android Keystore). Never `SharedPreferences`. | The Sanctum token is a bearer credential with full wallet authority for that ability. |
| D8 | **No refresh-token pair.** Treat the Sanctum token as the only credential. Rotate it via `POST /auth/refresh-token` on app start, and treat any `401` as "session over → back to login". | The backend has no separate refresh token. `refresh-token` rotates the *current* token and needs it to still be valid. `sanctum.expiration` is `null` by default, so tokens do not expire unless you set `SANCTUM_EXPIRATION`. Do not invent a refresh flow the server doesn't have. |
| D9 | **Idempotency key is generated once per confirmed intent, and reused on retry.** | This is the single most important financial rule in the app. See step 7.2. |
| D10 | **App lock with biometrics/device PIN (`local_auth`) is an optional device-level guard, not a substitute for server-side PIN verification.** | See Backend Gap G2. The client must never pretend to verify a PIN that the server doesn't check. |
| D11 | **Recipients and agents are identified by user ID in the API.** The app adds a **QR code** (the agent or recipient shows their ID as a QR, and the payer scans it) plus manual ID entry. A phone-number lookup is recommended as a backend addition (G1). | Users don't know internal IDs. QR is the bKash/Nagad pattern and needs no backend change. |
| D12 | **Feature-first folder structure** (`features/auth`, `features/wallet`, …), not layer-first. | Same reasoning as the backend's `Modules/` structure: domain boundaries over technical layers. |
| D13 | **Environments via `--dart-define`** (`API_BASE_URL`), not flavors at first. | Simplest thing that works. Add Android/iOS flavors only when you actually ship to stores. |
| D14 | **Bangla/English localisation is deferred**, but all user-facing strings go through `intl`/ARB files from day one. | Retrofitting i18n into hard-coded strings is painful. Adding a second ARB file later is cheap. |

### Backend gaps you should know about before starting

These are **not Flutter tasks**, but they affect the app. Decide whether to fix them in Laravel first.

| # | Gap | Impact on the app | Recommendation |
|---|---|---|---|
| G1 | No way to resolve a phone number → user ID. Transfer, cash-in and cash-out require `recipient_id` / `agent_id` (integers). | Users can't type a friend's phone number to send money. | Add `GET /api/v1/users/lookup?phone_number=…` in the Users module: `ability:user,agent`, rate-limited, returning only `{ id, masked_name, is_agent }`. Until then, use QR + manual ID (D11). |
| G2 | **Transactions do not verify the PIN.** CLAUDE.md §10 says "Same PIN for both login and transaction verification", but no transaction Form Request accepts or checks `pin`. | A stolen unlocked phone (or token) can move money without the PIN. | Add `pin` to the money-moving Form Requests and verify it in the service layer, with throttling and lockout. Only then add a PIN step to the app's confirm screen. Until then, the confirm screen must **not** ask for a PIN, because that would be security theatre. |
| G3 | `GET /wallets/me` does not include caps. Caps come from `GET /users/{id}` (`data.user.caps`). | The Wallet screen needs two calls. | Acceptable. Optionally embed caps in `WalletResource` later. |
| G4 | No endpoint exposes the **fee rate** to non-admins (`GET /system-settings` is admin-only). | Cash-out can't show the exact fee before confirming. | Either expose a read-only `GET /api/v1/system-settings/public` for `user,agent`, or add a `POST /transactions/cash-out/quote` endpoint. A quote endpoint is the better design: the server computes fee and commission, and the client just displays them. |
| G5 | Agents who register get an `agent` token immediately, but `POST /auth/agent/login` rejects them until approved. | After registering as an agent, the app must show a "Pending approval" state and not a broken dashboard. | Handle it in the app (step 6.5). No backend change needed. |
| G6 | `docs/API.md` examples and actual responses disagree in places. For example, the docs show `data.transaction.id`, which is correct. The Vue `Transfer.vue` reads `res.data.data?.id`, which is wrong, so its toast shows an empty "Tx #". | Don't trust the docs blindly. | Before writing each model, hit the endpoint in Postman (`docs/postman_collection.json`) and copy a **real** response into `test/fixtures/`. |

---

## 1. Prerequisites — Learn Before You Build

You know Node.js, some Java, and now some Vue. Dart will feel like Java with Kotlin-ish ergonomics. Budget real time here, because rushing it costs more later.

### 1.1 Dart (2–4 hours)

Learn specifically:

- **Sound null safety:** `String` vs `String?`, `!`, `?.`, `??`, `late`.
- **`final` vs `const`:** `const` constructors matter for widget rebuild performance.
- **Futures and `async`/`await`.** These are like JS Promises, except errors are thrown, so use `try`/`catch`.
- **Streams** (basic understanding only).
- **Classes, named constructors, factory constructors** (used by `fromJson`).
- **Records and pattern matching** (`switch` expressions). Freezed unions use these.
- **Extension methods** (useful for `Decimal` formatting).

Resource: dart.dev/language. Do the "Dart cheatsheet" codelab.

### 1.2 Flutter fundamentals (4–6 hours)

- **Everything is a widget.** Know the difference between `StatelessWidget` and `StatefulWidget`.
- **`build()` runs often.** Never do I/O inside `build()`.
- **Layout:** `Column`, `Row`, `Expanded`, `Padding`, `ListView.builder`, `SafeArea`, `Scaffold`.
- **Forms:** `Form`, `TextFormField`, `GlobalKey<FormState>`, `validator:`, `TextInputType.number`, `inputFormatters`.
- **Navigation concepts.** `go_router` replaces most of `Navigator` for you.
- **Material 3 theming:** `ThemeData`, `ColorScheme.fromSeed`.

Resource: docs.flutter.dev → "Write your first Flutter app" and "Layouts in Flutter".

### 1.3 Riverpod (2–3 hours)

- `Provider`, `FutureProvider`, `NotifierProvider` / `AsyncNotifierProvider`
- `ref.watch` (rebuild on change) vs `ref.read` (one-off, inside callbacks)
- `AsyncValue` and `.when(data:, loading:, error:)`. This replaces the `loading`/`error` refs you hand-rolled in Pinia.
- `ref.invalidate(provider)` to refetch (for example, the wallet after a transfer)
- Code generation with `@riverpod`

Resource: riverpod.dev → "Getting started" and "Essentials".

**Vue → Flutter mental map:**

| Vue / your web app | Flutter / this app |
|---|---|
| `.vue` component | Widget |
| `ref()` / `reactive()` local state | `StatefulWidget` state or a small provider |
| Pinia store | Riverpod `Notifier` / `AsyncNotifier` |
| `computed` | A provider that `ref.watch`es other providers |
| Axios instance + interceptors | `Dio` instance + `Interceptor`s |
| Inertia pages + `useAuthGuard` | `go_router` routes + `redirect:` |
| Vee-Validate | `Form` + `TextFormField.validator` |
| Toast store | `ScaffoldMessenger.showSnackBar` (wrapped in a helper) |
| `localStorage` token | `flutter_secure_storage` |

---

## 2. Environment Setup

### 2.1 Install tooling

1. Install the Flutter SDK (stable channel) and run `flutter doctor` until it is clean for at least Android.
2. Install Android Studio (for the SDK and emulator) and/or Xcode if you have a Mac.
3. Install the VS Code or Android Studio Flutter plugins.
4. Create an Android emulator (Pixel, API 34+).

### 2.2 Make the Laravel API reachable from a device

This trips everyone up the first time:

| Where the app runs | Base URL to use |
|---|---|
| Android emulator | `http://10.0.2.2:8000/api/v1` (`10.0.2.2` is the host machine's localhost) |
| iOS simulator | `http://127.0.0.1:8000/api/v1` |
| Physical phone on the same Wi-Fi | `http://<your-LAN-IP>:8000/api/v1`, with Laravel started via `php artisan serve --host=0.0.0.0` |

Also:

- Android blocks cleartext HTTP by default. For **local development only**, allow it through a debug-only `network_security_config.xml` scoped to your dev host. **Never** ship `usesCleartextTraffic="true"` in a release build. Production must be HTTPS.
- CORS does **not** apply to native mobile apps, so you need no Laravel CORS changes for Flutter.

### 2.3 Repository location

**Decision:** create the Flutter app in a **separate repository** (e.g. `wallet-mobile`), not inside the Laravel repo.

Reasons: different toolchain, different CI, different release cadence. The contract between them is the HTTP API documented in `docs/API.md`. If you strongly prefer a monorepo, put it at `mobile/` at the repo root. Never put it under `resources/`.

---

## 3. Project Creation & Dependencies

### 3.1 Create the project

```bash
flutter create --org com.yourcompany --platforms=android,ios wallet_mobile
```

### 3.2 Add dependencies

Runtime:

| Package | Purpose |
|---|---|
| `flutter_riverpod`, `riverpod_annotation` | State management (D2) |
| `dio` | HTTP (D3) |
| `go_router` | Routing (D4) |
| `freezed_annotation`, `json_annotation` | Models (D5) |
| `flutter_secure_storage` | Token storage (D7) |
| `decimal` | Money (D6) |
| `uuid` | Idempotency keys (D9) |
| `intl` | Number/date formatting, localisation (D14) |
| `flutter_localizations` (SDK) | Localisation |
| `qr_flutter` | Render "my QR" (D11) |
| `mobile_scanner` | Scan QR (D11) |
| `local_auth` | Optional app lock (D10) |

Dev:

| Package | Purpose |
|---|---|
| `build_runner` | Code generation |
| `freezed`, `json_serializable`, `riverpod_generator` | Generators |
| `riverpod_lint`, `custom_lint` | Catch Riverpod misuse |
| `mocktail` | Mocks in tests |
| `http_mock_adapter` | Fake Dio responses in tests |
| `flutter_lints` (default) or `very_good_analysis` | Lints. Pick one and keep it strict. |

Before adding any package beyond this list, ask the same question as on the backend: *what does it solve that the SDK or an existing dependency can't?*

### 3.3 Generation workflow

Run `dart run build_runner watch --delete-conflicting-outputs` in a terminal while developing. Generated files are `*.g.dart` and `*.freezed.dart`. **Decision:** commit generated files, so CI and teammates don't need to run the generator to compile.

---

## 4. Folder Structure

```
lib/
├── main.dart                     # bootstrap: ProviderScope, read env, run app
├── app.dart                      # MaterialApp.router, theme, localisation
├── core/
│   ├── config/
│   │   └── env.dart              # API_BASE_URL from --dart-define
│   ├── network/
│   │   ├── dio_provider.dart     # configured Dio instance
│   │   ├── auth_interceptor.dart # attaches Bearer token
│   │   ├── error_interceptor.dart# maps DioException → ApiException
│   │   └── api_exception.dart    # typed error (status, message, fieldErrors)
│   ├── storage/
│   │   └── secure_token_storage.dart
│   ├── money/
│   │   └── money.dart            # Decimal parsing + BDT formatting helpers
│   ├── router/
│   │   └── app_router.dart       # go_router config + redirect guard
│   ├── widgets/                  # shared UI: AmountField, PrimaryButton,
│   │                             #   ErrorView, SkeletonList, ConfirmSheet
│   └── utils/
│       └── validators.dart       # phone, pin, amount (mirror backend rules)
├── features/
│   ├── auth/
│   │   ├── data/                 # AuthRepository (API calls)
│   │   ├── domain/               # Session, AuthUser models
│   │   └── presentation/         # LoginScreen, RegisterScreen, controllers
│   ├── wallet/
│   ├── transactions/
│   ├── transfer/                 # user: send money, cash-in, cash-out
│   ├── agent/                    # agent dashboard, withdrawal, pending state
│   ├── profile/
│   └── qr/                       # my-QR + scanner
└── l10n/
    └── app_en.arb
test/
├── fixtures/                     # REAL JSON responses copied from Postman
├── core/
└── features/
integration_test/
```

**Layer rules inside each feature:**

| Layer | Contains | Must NOT contain |
|---|---|---|
| `data/` | Repository classes that call Dio and return domain models or throw `ApiException` | Widgets, `BuildContext`, navigation |
| `domain/` | Freezed models, pure logic (e.g. "is this amount within remaining daily cap?") | Dio, Flutter imports |
| `presentation/` | Screens, widgets, Riverpod controllers | Raw Dio calls, JSON parsing |

This is the mobile mirror of your backend's HTTP / domain / persistence split (CLAUDE.md §6).

---

## 5. Core Infrastructure (build this before any screen)

### 5.1 Environment config

- Read `API_BASE_URL` with `String.fromEnvironment('API_BASE_URL')`.
- Fail fast at startup if it is empty.
- Run with `flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1`.
- Put the common run configs in `.vscode/launch.json` so you don't retype them.

### 5.2 Secure token storage

Create a `SecureTokenStorage` class exposing `readToken()`, `writeToken(String)`, `clear()`. Also store the **panel** (`user` / `agent`) that issued the token, so the app knows which shell to show on cold start without guessing from roles.

> **Rule from CLAUDE.md §9:** the token's *ability* decides what it may do, not the user's role list. Store the `abilities` array from the login response, and route on it.

### 5.3 Dio instance + interceptors

Configure one `Dio` instance (exposed via a Riverpod provider):

- `baseUrl` from env
- `connectTimeout` ~10s, `receiveTimeout` ~20s
- Headers: `Accept: application/json`. Without it, Laravel may return HTML or redirects instead of JSON errors.

Then add three interceptors:

1. **`AuthInterceptor`** (`onRequest`): read the token from storage and set `Authorization: Bearer <token>`.
2. **`ErrorInterceptor`** (`onError`): convert every `DioException` into your own `ApiException`:
   - `statusCode`
   - `message` → `response.data['message']`, or a fallback
   - `fieldErrors` → `Map<String, String>` from `response.data['errors']`, taking the first message per field. The shape is the same as `parseApiError` in the Vue app.
   - Network/timeout errors get a distinct type (`NetworkException`), because the UI and retry logic treat them differently (step 7.2).
3. **`UnauthorizedInterceptor`**: on `401` from any endpoint *except* the login endpoints, clear storage and notify the session controller. The router redirect then sends the user to login. Use a callback or provider, not a direct import cycle. You solved the same problem in `api.ts` with `setOnUnauthorizedCallback`.

**Do not** retry `POST` requests automatically in an interceptor. Retries of money-moving calls are handled explicitly with the same idempotency key (step 7.2).

### 5.4 Error-to-UI mapping

Define one place that turns an `ApiException` into user-facing text, based on `docs/ERRORS.md`:

| Status | UI treatment |
|---|---|
| 401 | Session expired → login (except on the login screen itself: "Wrong phone number or PIN") |
| 403 | Show the server message (blocked wallet, unapproved agent, inactive account). Don't retry. |
| 404 | "Not found". On transfer, it means the recipient doesn't exist. |
| 409 | Show the server message (agent not approved, etc.) |
| 422 | Field errors go under the fields. Business errors (insufficient balance, cap exceeded, self-transfer) go in a dialog with the server message. |
| 429 | "Too many attempts, try again in a minute". Disable the button for ~60s. |
| 5xx | Generic "Something went wrong". Never show raw server text. |
| Network/timeout | "No connection". On a money call, go to the unknown-outcome flow (step 7.2). |

### 5.5 Money helpers

In `core/money/money.dart`:

- `Decimal parseMoney(Object? json)` accepts `num` or `String` from JSON. Convert via `toString()` first, then `Decimal.parse`, so you never route through a lossy `double` op.
- `String formatBdt(Decimal)` produces `৳1,234.50` using `intl` `NumberFormat` with 2 fraction digits.
- `String toApiAmount(Decimal)` produces `"1234.50"` for request bodies.

Write unit tests for these **first**. They are tiny and they protect every screen.

### 5.6 Client-side validators (mirror the backend exactly)

Copy the rules from the Laravel Form Requests, not from memory:

| Field | Backend rule (source) | Client validator |
|---|---|---|
| `phone_number` | `regex:/^01[3-9]\d{8}$/` (`PinLoginRequest`) | Same regex. Use `TextInputType.phone`. |
| `pin` | `digits_between:5,10` (`PinLoginRequest`, `RegisterRequest`) | 5–10 digits only. Use `FilteringTextInputFormatter.digitsOnly`, `obscureText: true`. |
| `pin_confirmation` | `confirmed` | Must equal `pin`. |
| `amount` | `numeric`, `gt:0` | Positive, max 2 decimals. Parse with `Decimal.tryParse`. |
| `description` | `nullable`, `max:500` | Optional, `maxLength: 500`. |
| `recipient_id` / `agent_id` | integer, must exist | Positive integer. |

Client validation is UX only. The server stays the authority (CLAUDE.md §17).

### 5.7 Router + guard

Define the routes:

```
/splash
/login
/register
/agent-pending
/reset-pin            (deep link, step 9.4)
/user                 (ShellRoute with bottom nav)
  /user/home
  /user/send
  /user/cash-in
  /user/cash-out
  /user/history
  /user/history/:id
  /user/profile
/agent                (ShellRoute with bottom nav)
  /agent/home
  /agent/withdraw
  /agent/history
  /agent/history/:id
  /agent/my-qr
  /agent/profile
```

`redirect:` logic (the equivalent of `useAuthGuard`):

1. Session still loading → `/splash`.
2. No token and target is not a public route → `/login`.
3. Token with ability `user` trying to open `/agent/**` → `/user/home`, and the reverse for `agent`.
4. Logged in and on `/login` → their panel's home.

Use `refreshListenable` (or a Riverpod-driven router) so the router re-evaluates when the session changes, for example on a 401-triggered logout.

### 5.8 Shared widgets

Build these once, before feature screens:

- `AmountField`: a `TextFormField` with decimal keyboard, `৳` prefix, 2-decimal formatter and amount validator.
- `PinField`: obscured, digits-only, 5–10 length.
- `PrimaryButton`: shows a spinner and is **disabled while loading**. This prevents double taps, which matters on money calls.
- `AsyncValueView<T>`: wraps `AsyncValue.when` with a standard skeleton, error and retry UI.
- `SkeletonList`: shimmering placeholders for lists. A simple animated opacity is enough, so you need no package.
- `ConfirmSheet`: a bottom sheet with a summary, Confirm and Cancel.
- `showSuccess` / `showError` helpers around `ScaffoldMessenger`.

---

## 6. Authentication Feature

### 6.1 Models

- `AuthUser` (freezed): `id`, `name`, `phoneNumber`, `email?`, `roles: List<String>`, `isVerified`, `isActive`.
- `Session` (freezed): `token`, `abilities: List<String>`, `user: AuthUser`, plus a getter `panel` derived from `abilities` (`agent` if it contains `agent`, else `user`).

Use `@JsonKey(name: 'phone_number')` or `fieldRename: FieldRename.snake` so Dart stays camelCase while JSON stays snake_case.

### 6.2 `AuthRepository` (data layer)

Methods and endpoints:

| Method | Endpoint | Body | Returns |
|---|---|---|---|
| `loginUser(phone, pin)` | `POST /auth/user/login` | `phone_number`, `pin` | `Session` from `data` |
| `loginAgent(phone, pin)` | `POST /auth/agent/login` | `phone_number`, `pin` | `Session` |
| `register(name, phone, pin, pinConfirmation, role)` | `POST /auth/register` | `name`, `phone_number`, `pin`, `pin_confirmation`, `role` (`USER`/`AGENT`) | `Session` |
| `refresh()` | `POST /auth/refresh-token` | none | new `token` + `abilities` |
| `logout()` | `POST /auth/logout` | none | void |

Send `pin` as a **string**. A leading zero (`01234`) must survive, which is why the backend validates `string` + `digits_between`.

### 6.3 `SessionController` (Riverpod `AsyncNotifier<Session?>`)

Responsibilities:

- `build()`: on app start, read the token from storage. If present, call `refresh()` (D8). On success, save the rotated token. On 401, clear storage and return `null`. On a network error, keep the stored session and let screens show "offline".
- `login(...)`, `register(...)`: call the repository, persist token + abilities + panel, and update state.
- `logout()`: call `/auth/logout` (ignore failures), then **always** clear storage, invalidate all user-scoped providers (wallet, history, profile), and set state to `null`.

> Clear every cached provider on logout. Otherwise user A's balance can flash on screen when user B logs in on the same phone. The Vue app has the same rule in `auth.clearAuth()`.

### 6.4 Login screen

- Segmented control: **Customer | Agent**. It selects which endpoint is called.
- Phone + PIN fields with validators from 5.6.
- Disable the submit button while the request is in flight.
- On 401: "Incorrect phone number or PIN". Don't reveal which part was wrong; the backend deliberately doesn't either.
- On 403 for agent login: show the server message (e.g. not approved / suspended) and offer "Contact support".
- On 429: show a cooldown message and disable the button.
- Add a "Forgot PIN?" link to an **information screen** explaining that PIN reset happens through customer care (CLAUDE.md §9 Case C). There is **no** self-service reset form.

### 6.5 Registration screen

- Fields: name, phone, PIN, confirm PIN, role (Customer / Agent).
- On 201 with role `USER`: persist the session → `/user/home`. Show "You received ৳50 signup bonus" (wallet starting balance).
- On 201 with role `AGENT`: **do not** route into the agent shell. Route to `/agent-pending` (G5), which explains that an admin must approve the account and offers "Log out". Don't persist this token as a usable agent session, because the next `agent/login` will be rejected until approval anyway.
- Map 422 `errors.phone_number` (e.g. already taken) under the phone field.

### 6.6 Splash screen

Show the logo while `SessionController.build()` runs, then let the router redirect. Keep it under ~1–2s. If refresh is slow, route on the stored session and refresh in the background.

---

## 7. User Panel

Bottom navigation: **Home · Send · History · Profile**. Cash-in and cash-out are reached from Home quick actions.

### 7.1 Home / Wallet screen

Data:

- `GET /wallets/me` → `data.wallet`: `id`, `balance`, `currency`, `is_blocked`
- `GET /transactions/history?per_page=5` → recent items
- Optional: `GET /users/{id}` → `data.user.caps` for the daily/monthly usage bars (G3)

UI:

- Balance card. Add a **tap-to-reveal** balance (hidden by default), a common privacy pattern in BD wallet apps.
- If `is_blocked == true`: a prominent red banner, "Your wallet is blocked. Contact support". Disable Send, Cash-in and Cash-out buttons. The server enforces this regardless; the UI just avoids a pointless round trip.
- Quick actions: Send Money, Cash-In, Cash-Out, Top-Up, My QR.
- Recent transactions list (5), with "See all" → History.
- Pull-to-refresh: `ref.invalidate(walletProvider)` and the recent-history provider.
- Caps card: daily and monthly used vs cap, with progress bars.

Providers:

- `walletProvider` (`FutureProvider` / `AsyncNotifier`)
- `recentTransactionsProvider`
- `capsProvider` (optional)

### 7.2 The money-movement pattern (applies to 7.3–7.6 and 8.3)

This is the most important section of the app. Every money-moving screen follows the same 5-stage flow. Implement it once, as a reusable controller and screen pattern.

```
[1. Form] → [2. Review/Confirm] → [3. Submitting] → [4a. Success receipt]
                                                  ↘ [4b. Failed (definite)]
                                                  ↘ [4c. Unknown outcome]
```

**Stage 1: Form.** Inputs with client validation. "Continue" only validates; it doesn't call the API.

**Stage 2: Review.** Show the counterparty, the amount and, for cash-out, the fee if you can know it (G4). Generate the idempotency key **here**, when the user lands on the review step: `const Uuid().v4()`. Store it in the controller state. If the user goes back and **changes** the amount or recipient, generate a **new** key, because it is a new intent.

**Stage 3: Submitting.** Disable Confirm and show progress. Block the system back button for the duration (`PopScope(canPop: false)`).

**Stage 4a: Success (201).** Parse `data.transaction`. Show a receipt screen with transaction ID, type, amount, fee, counterparty, balance after (`sender_wallet_balance_after` / `recipient_wallet_balance_after`) and time in local timezone. Then:

- `ref.invalidate(walletProvider)` and the history providers
- Discard the idempotency key

**Stage 4b: Definite failure (4xx).** Show the mapped message (5.4). The money did **not** move, so the user may edit and try again. A changed intent gets a new key.

**Stage 4c: Unknown outcome (timeout / connection dropped after sending).** The request may or may not have been processed. **Do not** tell the user it failed. Show: "We couldn't confirm this transaction. Checking…", then **retry the exact same request with the same idempotency key**. The backend returns the already-created transaction for a repeated key (`TransactionService` looks up `idempotency_key` first), so a retry can never double-charge. If retries keep failing, show "Status unknown — check your history before trying again" and link to History. Never auto-generate a new key here.

> **Why this matters:** without 4c, a user on a flaky 3G connection taps Send, gets a timeout, taps Send again with a fresh key, and pays twice. This is exactly the double-spend scenario CLAUDE.md §7/§18 warns about, just on the client side.

**Also:**

- Never put the idempotency key in a provider that survives navigation away from the flow. Scope it to the flow (`autoDispose`).
- Amounts go to the API via `toApiAmount()` as strings (D6).
- No PIN prompt on Confirm until the backend verifies PINs (G2).

### 7.3 Send Money (P2P transfer)

- Recipient input: **manual user ID** or **Scan QR** (step 9.1). Once G1 exists, add phone-number entry with lookup that shows the masked name before confirming.
- Amount + optional description (≤500 chars).
- Client guard: block sending to your own ID ("You can't send money to yourself"). The server also rejects it (`InvalidRecipientException`, 422).
- Endpoint: `POST /transactions/transfer`, with body `recipient_id` (int), `amount` (string), `idempotency_key`, `description?`.
- Errors to handle specifically: insufficient balance (422), daily/monthly cap exceeded (422, the message includes the remaining amount, so show it verbatim), recipient wallet blocked (403), recipient not found (422/404).

### 7.4 Cash-Out (via agent)

- Agent input: scan the agent's QR (primary) or enter the agent ID manually.
- Amount + optional description.
- Fee display: until G4 is fixed, show "A cash-out fee applies and is deducted in addition to the amount. Exact fee shown on your receipt." **Never hardcode a fee percentage.** The Vue app had a hardcoded 5% that disagreed with the real setting.
- Endpoint: `POST /transactions/cash-out`, with body `agent_id`, `amount`, `idempotency_key`, `description?`.
- The receipt shows `system_fee_amount` from the response. Total debited = `amount + system_fee_amount`.
- Errors: agent not approved / suspended (409/403), insufficient balance for amount + fee (422), caps (422).

### 7.5 Cash-In (via agent)

- Same agent input as cash-out.
- Endpoint: `POST /transactions/cash-in`, with body `agent_id`, `amount`, `idempotency_key`, `description?`.
- Free of charge for the user.
- Errors: agent has insufficient balance (422), agent not approved (409/403).

> **Domain note:** in this API, the **user** initiates cash-in by naming the agent. In real bKash, the *agent* initiates cash-in to the customer's number. Keep the API as-is for now, but flag it for review. Agent-initiated cash-in would need a new agent endpoint and is a backend decision, not a Flutter one.

### 7.6 Top-Up

- Amount + optional description.
- Endpoint: `POST /transactions/top-up`, with body `amount`, `idempotency_key`, `description?`.
- Label it clearly as a **demo/simulated top-up** if that's what the backend represents. Don't let it look like a real bank or card payment.

### 7.7 Transaction History

- Endpoint: `GET /transactions/history` with query `page`, `per_page` (use 20), `type?`, `status?`, `from_date?`, `to_date?`.
- Response shape: `data.items` + `data.pagination` (`total`, `per_page`, `current_page`, `last_page`, `from`, `to`).
- **Infinite scroll:** load the next page when the user nears the end, and stop when `current_page == last_page`. Implement it as an `AsyncNotifier` holding `List<Transaction>`, `currentPage`, `hasMore` and `isLoadingMore`.
- Filters: chips for type (Send, Cash-in, Cash-out, Top-up) and a date range picker. Changing a filter resets to page 1.
- Each row shows type icon, counterparty, signed amount (**+** green for credits, **−** for debits) and local time.
- **Direction logic:** the API returns raw `sender_id` / `recipient_id` / `user_id` / `agent_id`. Write a pure function in `domain/`: `TransactionDirection directionFor(Transaction tx, int myUserId)`. Unit-test it for every `type`. It is easy to get wrong, and it is the kind of thing users screenshot when it's wrong.
- Pull-to-refresh resets to page 1.
- Empty state: "No transactions yet".

### 7.8 Transaction Detail

- Endpoint: `GET /transactions/{id}` → a single transaction.
- Show all fields relevant to the user: type, status, amount, fee, counterparty, balance after (the one that belongs to **this** user's side), description, created time and ID.
- Optional: a "Share receipt" action (render to image or plain text via the share sheet).
- 403 means it isn't your transaction. Show "Not available" (the server enforces this via `TransactionPolicy`).

### 7.9 Profile

- `GET /users/{id}` (own ID from session) → name, phone, email, verification status.
- Edit name and address via `PATCH /users/{id}`. Phone number is **not** editable. It is the login identifier, and changing it is an admin/KYC process.
- "My QR" (step 9.1).
- "Change PIN" → information text: PIN changes go through customer care (Case C). Don't build a self-service form; the backend has no such endpoint by design.
- App lock toggle (step 9.3).
- Logout. Confirm first, then `SessionController.logout()`.

---

## 8. Agent Panel

Bottom navigation: **Home · Withdraw · History · Profile**, with "My QR" prominent on Home. Customers scan the agent's QR to cash-in or cash-out.

### 8.1 Pending / suspended state

- Reached after agent registration (6.5), or when agent login returns 403 with a not-approved or suspended message.
- Screen: status explanation, "Contact support", "Log out".
- No wallet or transaction UI here.

### 8.2 Agent Home / Dashboard

Data:

- `GET /wallets/me` → agent wallet balance, `is_blocked`
- `GET /users/{id}` → `agent_info`: `status`, `commission_rate`, `total_commission`, `approved_at`
- `GET /transactions/history?per_page=5` → recent activity

UI:

- Balance card (tap to reveal).
- Commission card: total commission earned, and the current rate (display `commission_rate × 100` as a percentage with up to 2 decimals).
- Status badge: APPROVED / SUSPENDED.
- **Big "My QR" button.** This is how customers find the agent ID for cash-in and cash-out.
- Recent activity (5) → "See all".
- Blocked-wallet banner, same as the user panel.

### 8.3 Agent Withdrawal

- Follows the 7.2 money-movement pattern exactly.
- Endpoint: `POST /transactions/agent/withdrawal`, with body `amount`, `idempotency_key`, `description?`.
- Show the available balance on the form. Client-side, warn if amount > balance, but still let the server be the authority.
- Errors: insufficient balance (422), wallet blocked (403), agent no longer approved (403/409).

### 8.4 Agent History & Detail

- Same endpoints and widgets as 7.7 and 7.8. Reuse them; don't fork them.
- The direction function must handle the agent's perspective. On a `CASH_OUT`, the agent *receives* money and earns commission. On a `CASH_IN`, the agent *sends* money. Add `COMMISSION_PAYOUT` and `AGENT_WITHDRAWAL` handling and unit-test them from the agent's side.
- Add a filter chip for `COMMISSION_PAYOUT` so agents can see earnings.

### 8.5 Agent Profile

- Same as 7.9 (name/address edit, logout, app lock), plus a read-only view of the commission rate and approval date.

---

## 9. Cross-Cutting Features

### 9.1 QR codes (D11)

**Payload format:** don't encode just a bare number. Use a small versioned, namespaced string so random QR codes can't be mistaken for payment targets:

```
walletms:v1:user:<id>
walletms:v1:agent:<id>
```

- **My QR** (both panels): render with `qr_flutter`, plus the name and the ID in text underneath.
- **Scanner** (user panel: Send, Cash-in, Cash-out): `mobile_scanner`. Parse and validate the prefix and version.
  - On the Send screen, accept `user:` codes. On Cash-in and Cash-out, accept only `agent:` codes, and show "This is not an agent QR" otherwise.
  - After scanning, **always** go to the normal Review step. A scan must never auto-submit.
- Request the camera permission with a clear rationale (Android manifest + iOS `NSCameraUsageDescription`).

> The QR contains only a public identifier, so it carries no secret, and the server validates everything anyway. The prefix check is about UX and mistake-prevention, not security.

### 9.2 Session lifecycle & app resume

- On app resume after **N minutes in background** (choose 5): either require the app lock (9.3) or, if app lock is off, at least call `refresh-token` to validate the session is still alive.
- On any 401: clear the session → login (5.3).
- Logout everywhere is a backend concern (token revocation). The app just clears local state.

### 9.3 App lock (optional, D10)

- Use `local_auth` for biometrics, falling back to the device PIN.
- Off by default, toggled in Profile.
- Triggered on cold start (if a session exists) and on resume after N minutes.
- Be explicit in the UI copy that this protects **the app on this device**. It is not the wallet PIN.

### 9.4 PIN reset deep link (Case C)

The backend sends the user an email with a **signed URL** for `POST /api/v1/auth/reset-pin/{user}?expires=…&signature=…`. That is an API URL, and tapping an API URL on a phone opens nothing useful.

**Decision:**

1. **Backend (small change, flag it):** the email should link to an **app/web landing URL** (e.g. `https://app.yourdomain/reset-pin?link=<urlencoded signed API URL>`), not directly to the API endpoint.
2. **Web fallback:** the Vue app gets a tiny public page at that URL with a PIN + confirm form that POSTs to the embedded signed URL. This works on any device.
3. **App:** configure Android App Links and iOS Universal Links for that host and path. `go_router` maps `/reset-pin` to a screen that reads the `link` query parameter, shows PIN + confirm fields, and POSTs `pin` and `pin_confirmation` to the signed URL.
   - **Validate the `link` parameter:** it must start with your configured `API_BASE_URL` and the path must match `/auth/reset-pin/`. Otherwise, refuse. This stops a crafted link from making the app POST a user's new PIN to an attacker's server.
   - On success → "PIN updated, please log in".
   - On 403 (expired or invalid signature) → "This link has expired. Contact customer care for a new one."

Deep links can be built last. The web fallback alone satisfies the requirement.

### 9.5 Localisation & formatting

- All strings come from `app_en.arb` via the generated `AppLocalizations`.
- Format currency with `intl` (`৳` symbol, 2 decimals, thousands separators).
- Dates: the API returns UTC ISO-8601 (CLAUDE.md §11: "store UTC, display local"). Parse with `DateTime.parse(...).toLocal()` and format with `intl` `DateFormat`.
- Add `app_bn.arb` (Bangla) later; no code changes should be needed.

### 9.6 Security checklist (mobile-specific)

- Token only in `flutter_secure_storage` (D7).
- Never log the token, PIN, or full request bodies of auth or money calls. Strip the `Authorization` header from any logging interceptor, and enable logging only in debug builds.
- `obscureText` on PIN fields. Disable autocorrect and suggestions on PIN and phone fields.
- Release builds: HTTPS only, no cleartext. Consider certificate pinning later. It's optional, and it adds operational burden when the cert rotates.
- Android: `android:allowBackup="false"`, or exclude secure storage from backups.
- Optional: hide sensitive screens in the app switcher (`FLAG_SECURE` on Android) for balance, receipt and PIN screens.
- Obfuscate release builds (`--obfuscate --split-debug-info=…`).
- Never trust client checks for authorization. The UI hides things; the server forbids them.

---

## 10. Testing

Mirror the backend's emphasis on financial invariants (CLAUDE.md §22), on the client side.

### 10.1 Unit tests (fast, most of your tests)

- `money.dart`: parsing `num` and `String`, formatting, `toApiAmount`, no float drift (`0.1 + 0.2` cases).
- Validators: phone regex edge cases (`012…` rejected, `019…` accepted, 10 vs 11 digits), PIN lengths 4/5/10/11, amounts `0`, `-1`, `1.234`, `abc`.
- `directionFor(tx, myId)`: every transaction type from both the user's and the agent's perspective.
- `ApiException` mapping from real fixture error bodies (`test/fixtures/errors/*.json`).
- QR payload parser: valid, wrong prefix, wrong version, non-numeric ID.

### 10.2 Repository tests (Dio mocked with `http_mock_adapter`)

- Each repository method sends the correct path, method and body field names (`recipient_id`, `agent_id`, `idempotency_key`, `pin_confirmation`…).
- It parses the real fixture responses into models.
- 401, 403, 422 and 429 become the correct `ApiException`s.

### 10.3 Controller tests (Riverpod `ProviderContainer`, no widgets)

- `SessionController`: login persists the token; logout clears storage **and** invalidates wallet and history providers; a 401 on refresh leads to a null session.
- **Money-flow controller (critical):**
  - The idempotency key is created on entering Review, and is **the same** across a timeout + retry.
  - Changing amount or recipient after going back produces a **new** key.
  - A 4xx moves the controller to the failed state and allows a new attempt.
  - A timeout moves it to the unknown state, then retries with the **same key**, then succeeds once.
  - Confirm can't fire twice while submitting.

### 10.4 Widget tests

- The Login screen shows field errors and disables its button while loading.
- The Home screen with a blocked wallet shows the banner and disabled actions.
- The History screen shows skeleton, then list, then empty state, then error-with-retry.

### 10.5 Integration tests (`integration_test/`, against a real local backend)

- Seed a test database via Laravel seeders (a user with a balance, an approved agent).
- Flow: register user → login → see ৳50 → transfer to another user → balance decreases → history shows the transfer.
- Flow: login as agent → see commission → withdraw → balance decreases.
- Run these before each release, not on every commit.

### 10.6 Manual test matrix (before calling it done)

| Scenario | Expected |
|---|---|
| Airplane mode, then tap Send on Confirm | Unknown-outcome flow. No duplicate when the network returns. |
| Double-tap Confirm quickly | Exactly one transaction |
| Wallet blocked by admin while the app is open | The next money call shows a 403 message. The banner appears after refresh. |
| Agent suspended while logged in | Agent actions return 403/409 with a clear message |
| Kill the app on the receipt screen, then reopen | Balance is correct, no duplicate |
| Log out as user A, log in as user B | No A data visible at any point |
| Rotate the device on every screen | No lost form input, no crash |
| Small screen (5") and large font setting | No overflow errors |

---

## 11. Build & Release (when ready)

1. App icons and splash (`flutter_launcher_icons`, `flutter_native_splash`, both dev-only tools).
2. Set the `applicationId` / bundle ID, version name and code in `pubspec.yaml`.
3. Android signing config (keystore never committed) and an iOS signing team.
4. Release build: `flutter build appbundle --release --obfuscate --split-debug-info=build/symbols --dart-define=API_BASE_URL=https://api.yourdomain/api/v1`.
5. CI (GitHub Actions): `flutter analyze`, `flutter test`, and the build on every PR.
6. Internal testing track (Play Console) and TestFlight before any public release.
7. Crash reporting (e.g. Firebase Crashlytics or Sentry): optional, but strongly recommended before real users. **Scrub PII and tokens.**

---

## 12. Implementation Order (do these in sequence)

Each step should end with something runnable and committed.

| Step | Deliverable | Depends on |
|---|---|---|
| 1 | Dart + Flutter + Riverpod learning (§1). Throwaway counter/todo app. | — |
| 2 | Project created, dependencies added, lints strict, folder skeleton (§3–4) | 1 |
| 3 | Env config, secure storage, Dio + interceptors, `ApiException` (§5.1–5.4). Unit tests. | 2 |
| 4 | Money helpers + validators (§5.5–5.6) **with unit tests first** | 2 |
| 5 | Copy real JSON fixtures from Postman for every endpoint you'll use (G6) | Backend running |
| 6 | Models (freezed) for `AuthUser`, `Session`, `Wallet`, `Caps`, `Transaction`, `AgentInfo`, `Pagination`. Fixture-based parsing tests. | 4, 5 |
| 7 | Router + guard + splash (§5.7) with placeholder screens | 3 |
| 8 | Auth: repository, `SessionController`, Login, Register, agent-pending (§6) | 6, 7 |
| 9 | Shared widgets (§5.8) | 4 |
| 10 | User Home/Wallet (§7.1) | 8, 9 |
| 11 | **Money-movement controller + tests (§7.2)**. Build this before any money screen. | 9 |
| 12 | Top-Up (§7.6). The simplest money flow, used to prove step 11 end-to-end. | 11 |
| 13 | Send Money (§7.3) with manual ID | 12 |
| 14 | Cash-Out and Cash-In (§7.4, §7.5) | 13 |
| 15 | History (infinite scroll, filters, direction logic) + Detail (§7.7–7.8) | 10 |
| 16 | Profile (§7.9) | 10 |
| 17 | Agent Home, Withdrawal, History, Profile (§8). Mostly reuse. | 11, 15 |
| 18 | QR: My QR + scanner wired into Send, Cash-in and Cash-out (§9.1) | 13, 14, 17 |
| 19 | Session resume, app lock (§9.2–9.3) | 8 |
| 20 | Localisation pass: no hard-coded strings left (§9.5) | all screens |
| 21 | Widget + integration tests, manual matrix (§10) | all |
| 22 | PIN-reset deep link (§9.4). Needs the backend email change first. | backend |
| 23 | Release pipeline (§11) | all |

**Backend changes to schedule alongside** (each is its own small Laravel task, guided the usual way):

- G2: transaction PIN verification. **Do this before any real money**, because it is a security gap.
- G4: fee quote endpoint or public settings, before cash-out polish (step 14).
- G1: phone → user ID lookup, before or alongside step 13 if you want phone-based send.
- 9.4: the PIN-reset email links to a landing URL, before step 22.

---

## 13. Checklist

- [ ] Flutter fundamentals + Riverpod learned (§1)
- [ ] Project, dependencies, lints, structure (§3–4)
- [ ] Dio + interceptors + typed errors + secure storage (§5.1–5.4)
- [ ] Money helpers use `Decimal` everywhere. No `double` for money (§5.5)
- [ ] Validators mirror the Laravel Form Requests (§5.6)
- [ ] Router guard enforces panel by **token ability** (§5.7)
- [ ] Login (user/agent), Register, agent-pending (§6)
- [ ] Logout clears storage **and** all cached providers
- [ ] User Home with blocked-wallet handling (§7.1)
- [ ] Money-movement pattern with idempotency reuse on retry (§7.2), fully tested
- [ ] Top-Up, Send, Cash-In, Cash-Out (§7.3–7.6)
- [ ] History with infinite scroll, filters and correct direction logic (§7.7)
- [ ] Transaction detail / receipt (§7.8)
- [ ] Profile, with "Forgot / Change PIN" explaining the customer-care flow (§7.9)
- [ ] Agent Home, Withdrawal, History, Profile (§8)
- [ ] QR show + scan, prefix-validated, never auto-submits (§9.1)
- [ ] App resume handling + optional app lock (§9.2–9.3)
- [ ] All strings localised. UTC → local time (§9.5)
- [ ] Mobile security checklist done (§9.6)
- [ ] Unit, repository, controller, widget and integration tests (§10)
- [ ] Manual test matrix passed (§10.6)
- [ ] Backend gaps G1–G6 each decided: fixed, or consciously deferred

---

## Notes

- Build the **money-movement pattern (§7.2) once and well**. Every financial screen is the same flow with different fields.
- When a response doesn't match what you expected, check the real response before changing code. The docs have drifted from the code before (G6).
- Keep business rules on the server. The app's job is to collect input, show what the server says, and never move money twice.
- Same workflow as the backend: implement a step, then bring it back for review before moving on.
