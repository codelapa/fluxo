# Fluxo — Financial import and management

Independent Laravel 12 API and Vue 3/TypeScript frontend. Built for the Full Stack Senior practical assessment. Identity and interface by CodeLapa. This is an assessment preview, not a banking product; it never moves money.

## Run with Docker

Requirements: Docker Engine/Desktop with Docker Compose v2, approximately 2 GB available RAM, port 3196 available. No PHP, Composer or Node installation is required on the host.

```sh
cp .env.example .env
```

Set `APP_KEY` to `base64:` followed by a base64-encoded random 32-byte key. Set different random passwords in `DB_PASSWORD` and `MYSQL_ROOT_PASSWORD`. For example, generate values locally with `openssl rand -base64 32` / `openssl rand -hex 24`; do not commit the resulting `.env`.

```sh
docker compose build
docker compose up -d mysql redis
docker compose run --rm app php artisan migrate --force
docker compose up -d
```

Open **http://localhost:3196** and sign in with an account created through the command below. There is no public demo login or registration. Preview credentials are provisioned privately and are not stored in the source. Each user sees only their own transactions and files. Legacy temporary accounts remain subject to the original cleanup task; permanent accounts are not pruned.

For HTTPS deployment, set `APP_URL`, `SANCTUM_STATEFUL_DOMAINS` (host without scheme), and `SESSION_SECURE_COOKIE=true`. The preview binds to loopback only; put it behind an HTTPS reverse proxy. Keep MySQL/Redis internal. `APP_DEBUG` must remain false.

Create a permanent account, with a password entered interactively:

```sh
docker compose exec app php artisan user:create evaluator@example.com --name=Evaluator
```

There is no public registration or shared default password. Login and imports are rate limited. Cookie sessions are HttpOnly/encrypted and protected against CSRF. The UI reads Laravel's XSRF cookie and sends the required header; no authentication token is stored in localStorage.

## Services

- `web`: Nginx, compiled Vue interface and API gateway.
- `app`: PHP 8.4 / Laravel API.
- `worker`: separate Redis queue consumer, 300-second timeout and up to 3 attempts.
- `scheduler`: scheduled cleanup and failed-job pruning.
- `mysql`: MySQL 8.4 with persistent data volume.
- `redis`: Redis 7.4 for cache, queue, progress, session and locks. Persistence enabled, noeviction policy.

Application files, MySQL and Redis use named volumes. Restarting containers preserves data. **Do not use `docker compose down -v` unless you deliberately intend to erase this project's data.**

## Tests and build

```sh
docker build --target testing -t fluxo-tests .
docker run --rm --network none fluxo-tests php artisan test
php tests/migration_rehearsal.php
```

PHPUnit uses in-memory SQLite, array cache/session and isolated temporary file storage. Tests cover unauthorized access, real login/logout, queuing, file idempotency, integer cents, expense subtraction, cache invalidation, rollback on failure, malformed CSV, isolation between users, a 1,201-row chunked import, pagination, filters and upload constraints. Runtime validation of the preview additionally uses real MySQL, Redis and the separate worker; SQLite tests alone are not evidence of queue infrastructure working.

Local tools (PHP 8.4+, Composer 2, Node 22):

```sh
cd backend
composer install
cp .env.example .env
# For a local backend without Docker, configure SQLite, database queue/cache/session,
# create database/database.sqlite, set APP_KEY with php artisan key:generate,
# and set SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5173,localhost:5173.
php artisan migrate
php artisan test
php tests/migration_rehearsal.php
php artisan serve --host=127.0.0.1
# Separate terminal, backend directory:
php artisan queue:work --timeout=300
# Separate terminal, frontend directory:
npm ci
npm run dev
npm run build
```

Vite proxies `/api` and `/sanctum` to localhost:8000. Production frontend build includes TypeScript checks. GitHub Actions defines backend tests and frontend build; the workflow must be pushed to a repository before GitHub can execute it.

## Import, deduplication and editing

- CSV format remains `date,description,amount,type`; `Receita` / `Despesa` are mapped to `income` / `expense`. Integer CSV values are **BRL cents**: `115346` = `R$ 1.153,46`.
- OFX banking/card statements in SGML (v1) and XML (v2) are supported. Signed decimal `TRNAMT` is converted to integer cents without floating-point arithmetic. Negative values are expenses. Only BRL statements with bank/account identification and FITID are accepted. External entities, DTDs, investment statements and automatic correction messages are rejected with a clear error instead of being applied silently.
- Maximum 20 MB / 100,000 rows. Dates must be real, descriptions 1–500 UTF-8 characters and amounts positive after normalization, up to 10 billion cents. Input validation and both streaming passes run exclusively in the worker.
- Upload responds 202 after storing a private file and enqueuing the import. The worker validates the complete file, then writes in chunks of 500 inside one SQL transaction. Errors roll everything back. Transient failures retry with backoff. The user row is locked during writes, serializing concurrent imports/edits for that owner.
- A unique per-user file checksum prevents submitting identical content twice, even under another filename. Persistent `transaction_identities` also catch overlapping files with changed line endings or order.
- CSV identity consists of date, normalized description (Unicode/case/whitespace), type and integer amount. Identical CSV rows, including duplicates within the same file, are counted as skipped. This is an explicit assumption: CSV has no bank identifier, so two legitimate transactions with exactly these four fields cannot be distinguished. Prefer OFX with distinct FITIDs for that case.
- OFX identity combines institution, account and FITID, following the [official OFX specification](https://www.financialdataexchange.org/common/Uploaded%20files/OFX%20files/OFX%202.2.pdf). Identical values with distinct FITIDs or different accounts are preserved. Reusing a FITID with a different monetary amount/type fails the import for review.
- CSV/OFX overlap can be matched when those four normalized fields agree. A different description cannot be reliably reconciled automatically without a shared bank ID. Amount alone is never a duplicate criterion.
- Original identities remain even when a transaction is edited or soft-deleted. Reimporting the source does not overwrite edits or restore deleted values. New edited identities are also retained. Manual edits that would create an identified duplicate return 409.
- Click a transaction to open an accessible native-dialog lightbox. Update description/date/type/amount, delete, or restore through the **Excluídas** filter. Deletion removes the row from totals without physically destroying it.
- Dashboard keys include a SQL generation number. Import completion increments it through an Event inside the same transaction; editing/deleting/restoring uses a Model Observer. Old concurrent cache results cannot populate the new generation. Redis cache TTL is five minutes.
- Import history reports inserted and skipped rows. Progress is polled automatically. Files are private and removed on success. Failed input cannot partially affect the ledger.
- Migration `2026_11_12_000002` backfills identities and marks pre-existing exact CSV duplicates as recoverable historical duplicate records. It preserves their original rows and bumps dashboard generations; it does not delete financial data or password hashes.

## Screens and requirement audit

The authenticated **Sobre o projeto** tab at `/sobre-o-projeto` explains every requirement, architecture, financial decisions and delivery status. Tabs have real URLs: `/`, `/transacoes`, `/importacoes`, `/sobre-o-projeto`, with deep-link refresh and browser Back/Forward support. Documentation downloads are authenticated.

| Assessment requirement                                 | Implementation / evidence                                                                                                                                               |
| ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Isolated Laravel REST API (9+)                         | Laravel 12 in `backend`, separate Vue project in `frontend`; JSON API only                                                                                              |
| Authentication on UI/API                               | Session/Sanctum, CSRF, login throttling, server-side user scoping; no public demo endpoint                                                                              |
| Background CSV upload/read/validation/persistence      | `StartImport` → Redis queue → `ProcessImport` → streaming readers/writer                                                                                                |
| Cached income/expenses/balance, automatic invalidation | `Dashboard`, `ImportCompleted`, `TransactionObserver`, atomic SQL generations                                                                                           |
| Vue pagination/upload feedback                         | Vue 3 + TypeScript; filters, pagination, progress, inserted/skipped/error results                                                                                       |
| PHP, MySQL, Redis and separate Worker in Docker        | `compose.yaml`, plus Nginx and Scheduler; private persistent volumes                                                                                                    |
| Automated business/critical endpoint tests             | `backend/tests/Feature`: authentication, isolation, queuing, balance, cache, rollback, 1,201-row import, OFX, duplicate files/overlap/cross-format, edit/delete/restore |
| Detailed README                                        | Docker setup, tests, API and all decisions in this document                                                                                                             |
| Optional architectural improvements                    | Actions, Services, Events, Observer, slim HTTP controllers; no claim of a full DDD implementation                                                                       |
| Optional API documentation                             | `docs/openapi.yaml`, authenticated download from About page                                                                                                             |
| Optional CI                                            | GitHub Actions configured in source; execution awaits pushing a repository                                                                                              |
| Optional live feedback                                 | Active-import polling, with longer interval in hidden tabs                                                                                                              |
| Optional English code                                  | Application identifiers and documentation in English; UI in Portuguese                                                                                                  |

## API

See [OpenAPI contract](docs/openapi.yaml). Responses are JSON; send `Accept: application/json`. Start with `GET /sanctum/csrf-cookie`, keep the session cookies, then send `X-XSRF-TOKEN` for login and every mutation. Browser clients must use a configured stateful origin. Authenticated API requests without a session return 401; CSRF failures return 419.

| Method     | Endpoint                | Purpose                                          |
| ---------- | ----------------------- | ------------------------------------------------ |
| POST       | /api/login              | Email/password login                             |
| GET        | /api/user               | Current profile                                  |
| POST       | /api/logout             | Invalidate session                               |
| GET        | /api/dashboard          | Totals/monthly series; optional from/to          |
| GET        | /api/transactions       | Paginated rows; from/to, type, q, page, per_page |
| GET / POST | /api/imports            | Paginated history / multipart `file` upload      |
| GET        | /api/imports/{id}       | Status/progress/errors for owned import          |
| POST       | /api/imports/{id}/retry | Retry a failed, owned import                     |
| GET        | /api/sample             | Download provided full fixture                   |

### Editing endpoints

`PATCH /api/transactions/{id}` accepts `{description,date,type,amount}` with integer cents. `DELETE /api/transactions/{id}` soft-deletes. `POST /api/transactions/{id}/restore` restores an owned user-deleted record. `GET /api/transactions?status=deleted` lists the owner's trash. All mutations require authentication and CSRF.

## Source and structure

Fixture: https://gist.github.com/limatheus/6644975c118e034db4e4d0df1cdc1b24. Original CSV preserved under `backend/storage/app/samples/`.

`Actions/StartImport` orchestrates uploads. `Jobs/ProcessImport` handles asynchronous execution. `Services/CsvReader` owns parsing/validation, `Services/Dashboard` cached aggregation, `Events/ImportCompleted` and its listener own invalidation. Controllers only validate HTTP inputs, enforce ownership and call those services. UI text is Portuguese; application identifiers and source code are English. Tests and CI accompany the implementation. No repository was supplied by the evaluator; nothing is submitted on the candidate's behalf.
