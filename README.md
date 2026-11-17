# POSMaster API

Multi-branch restaurant POS and inventory management backend built with Laravel 12, Sanctum, Spatie permissions, Reverb broadcasting, and Swagger/Postman documentation.

## Features

- Sanctum token authentication, password reset, device-scoped tokens.
- Multi-branch access control (`user_branches`, `branch`/`branch.access` middleware, branch-scoped pricing and settings).
- Role and permission system (`ADMIN`, `MANAGER`, `INVENTORY_STAFF`, `CASHIER`) with policies and a `Gate::before` administrator bypass.
- POS order lifecycle: draft, hold/resume, send to kitchen, in-progress, completed, cancelled.
- Kitchen display endpoints and private broadcast channels.
- Cash, KHQR, and bank-card payments with confirmation, refunds, provider transactions, and signed webhooks.
- Recipe-based inventory deduction at kitchen dispatch, stock reversals, low-stock events, consolidation.
- Purchase orders with goods receipts, stock transfers between branches, suppliers.
- Cashier shifts, cash movements, and shift summaries.
- Discounts (percentage/fixed), tax and service-charge snapshots.
- Reports: sales, best sellers, payment methods, cashiers, inventory, purchases, branch comparison, dashboard.
- Audit log, system settings, receipt generation.
- Request idempotency via `Idempotency-Key`, consistent API envelope, rate limiting.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `gd`, `zip`
- Composer 2
- MySQL 8 (or SQLite for tests)
- Redis (recommended for cache/queue; file/sync work for local development)
- Node.js is only needed for the optional frontend assets

## Quick start (local)

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure MySQL in `.env`, then:

```bash
php artisan migrate --seed
php artisan serve
```

The API is served at `http://localhost:8000/api/v1`.

Demo accounts created by `DatabaseSeeder` in local/testing environments:

| Role | Email | Password |
| --- | --- | --- |
| ADMIN | `admin@posmaster.test` | `password` |
| MANAGER | `manager@posmaster.test` | `password` |
| CASHIER | `cashier@posmaster.test` | `password` |
| INVENTORY_STAFF | `inventory@posmaster.test` | `password` |

Demo branches: `PNH-01` (default) and `SR-01`.

### Redis

Local `.env` defaults to `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, and `BROADCAST_CONNECTION=log` so the API runs without Redis. To use Redis locally, start it and set:

```dotenv
CACHE_STORE=redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb
REDIS_CLIENT=predis
```

Start the Reverb server and queue worker:

```bash
php artisan reverb:start
php artisan queue:work
```

## Docker

The compose stack provides PHP-FPM, nginx, MySQL, Redis, a queue worker, the scheduler, and Reverb.

```bash
# APP_KEY must be available for interpolation
php artisan key:generate --show
# put APP_KEY=base64:... in .env (compose reads it)
docker compose up -d --build
```

Services and ports (override with `HTTP_PORT`, `MYSQL_PORT`, `REDIS_PORT`, `REVERB_PORT`):

- API: `http://localhost:8000`
- Reverb: `ws://localhost:8080`
- MySQL: `localhost:3307`
- Redis: `localhost:6380`

The `app` container runs migrations on startup (`RUN_MIGRATIONS=1`). Seed demo data explicitly:

```bash
docker compose exec app php artisan db:seed
```

## API conventions

- Base path: `/api/v1`
- Auth: `Authorization: Bearer <sanctum-token>` from `POST /api/v1/auth/login`
- Response envelope:

```json
{
  "success": true,
  "message": "OK",
  "data": {},
  "meta": null,
  "errors": null
}
```

- Errors use `errors.code` and optional `errors.details`, for example `VALIDATION_ERROR`, `FORBIDDEN`, `INSUFFICIENT_STOCK`, `INVALID_ORDER_STATE`.
- Paginated endpoints return `meta.current_page`, `meta.per_page`, `meta.total`, `meta.last_page`.
- Branch-scoped endpoints live under `/api/v1/branches/{branch}/...`; access is checked against `user_branches`.
- Send `Idempotency-Key: <uuid>` on payment and other mutating requests to safely retry. A replay returns the stored response with `Idempotent-Replayed: true`; reuse with a different payload returns `409`.

### Main endpoints

| Area | Endpoints |
| --- | --- |
| Auth | `POST auth/login`, `GET auth/me`, `POST auth/logout`, `POST auth/change-password`, `POST auth/forgot-password`, `POST auth/reset-password` |
| Public | `GET public/menu?branch_id=`, `GET public/settings?branch_id=` |
| Branches | `GET/POST branches`, `GET/PUT/PATCH/DELETE branches/{branch}` |
| Employees | `GET/POST employees`, `GET/PUT/PATCH/DELETE employees/{employee}`, `POST employees/{employee}/branches` |
| Catalog | `categories`, `menu-items` (+ `variants`, `price`), `modifier-groups`, `recipes` |
| Customers/tables | `branches/{branch}/customers`, `branches/{branch}/tables` (+ `status`, `assign`, `transfer`, `merge`) |
| Orders | `branches/{branch}/orders`, `orders/{order}` (+ `send-to-kitchen`, `hold`, `resume`, `complete`, `cancel`, `items`, `statuses`, `receipt`) |
| Payments | `orders/{order}/payments`, `payments/{payment}` (+ `confirm`, `refund`), `POST webhooks/payments/{method}` |
| Kitchen | `branches/{branch}/kitchen/queue`, `kitchen/orders/{order}/accept`, `kitchen/orders/{order}/items/{item}` |
| Inventory | `branches/{branch}/inventory` (+ `low-stock`, `movements`, `{ingredient}/adjust`) |
| Purchasing | `branches/{branch}/purchase-orders`, `purchase-orders/{po}` (+ `submit`, `approve`, `receive`, `cancel`) |
| Transfers | `branches/{branch}/stock-transfers`, `stock-transfers/{transfer}` (+ `approve`, `dispatch`, `receive`, `cancel`) |
| Shifts | `branches/{branch}/shifts` (+ `current`, `open`), `shifts/{shift}` (+ `close`, `summary`, `cash-movements`) |
| Discounts | `branches/{branch}/discounts`, `discounts/{discount}` |
| Reports | `branches/{branch}/reports/{dashboard,sales,best-selling,payment-methods,cashiers,inventory,purchases}`, `reports/branches` |
| Settings/audit | `branches/{branch}/settings`, `branches/{branch}/audit-logs`, `audit-logs/{auditLog}` |

## Order flow

1. `POST /api/v1/branches/{branch}/orders` with `type`, `items[]` (menu item, optional variant/modifiers, quantity).
2. Optionally hold/resume, then `POST /api/v1/orders/{order}/send-to-kitchen` — inventory is deducted from the active recipe and the order emits `order.sent`.
3. `POST /api/v1/orders/{order}/payments` (cash returns `PAID` immediately; KHQR/bank card start as `PENDING`).
4. Confirm pending payments via `POST /api/v1/payments/{payment}/confirm` or a signed provider webhook.
5. `POST /api/v1/orders/{order}/receipt`, then `POST /api/v1/orders/{order}/complete`.

Cancelling an order after kitchen dispatch reverses stock with `ADJUSTMENT` movements referencing `order_reversal`.

## Broadcasting

Private channels (Laravel Echo + Sanctum-authenticated broadcasting):

- `private-branch.{branchId}.pos`
- `private-branch.{branchId}.kitchen`
- `private-branch.{branchId}.inventory`
- `private-user.{userId}`

Events: `order.sent`, `order.completed`, `kitchen.status`, `payment.confirmed`, `inventory.low`.

## Payments and webhooks

- Configure `PAYMENT_WEBHOOK_SECRET` and optional KHQR credentials (`KHQR_MERCHANT_ID`, `KHQR_ACCOUNT_ID`, `KHQR_CALLBACK_URL`).
- Signature: HMAC-SHA256 of the provider transaction id and payment context using `PAYMENT_WEBHOOK_SECRET` (see `app/Support/ProviderSignature.php`).
- Webhook payload: `payment_id`, `provider_transaction_id`, optional `provider_reference`, `signature`.
- In `local`/`testing`, an unsigned non-empty transaction id is accepted so the flow is easy to exercise.

## Documentation

```bash
composer docs
```

- Swagger UI: `/api/documentation`, JSON at `/docs`
- Postman: `docs/postman/POSMaster.postman_collection.json` and `POSMaster.postman_environment.json` (157 requests across 19 folders)

Set the Postman `base_url`/`token` variables after logging in. `storage/api-docs/api-docs.json` is generated from routes; keep `L5_SWAGGER_GENERATE_ALWAYS=false` so `composer docs` output is not overwritten.

## Testing and code style

```bash
php artisan test
vendor/bin/pint
```

Tests use SQLite in-memory with array cache and sync queue (`phpunit.xml`), and seed demo data through `DatabaseSeeder`.

## Project structure

```
app/
  Enums/                  Domain enums
  Events/                 Broadcast events
  Exceptions/             Business/stock exceptions + API renderer
  Http/Controllers/Api/V1 Versioned API controllers
  Http/Middleware/        Branch resolution, branch access, idempotency
  Policies/               Authorization policies
  Providers/              App, auth, route, broadcasting providers
  Services/               Order, payment, inventory, purchasing, reports, ...
  Support/                ApiResponse, BranchContext, ProviderSignature
database/
  migrations/             Schema
  seeders/                Roles/permissions + demo data
docs/postman/             Generated Postman collection/environment
routes/api.php            API routes
scripts/                  OpenAPI and Postman exporters
docker/                   Dockerfile, nginx, PHP config
docker-compose.yml        Local/staging stack
```

## Production notes

- Set `APP_ENV=production`, `APP_DEBUG=false`, a strong `APP_KEY`, and secure database/Redis credentials.
- Set `PAYMENT_WEBHOOK_SECRET`; never accept unsigned webhooks in production.
- Run `php artisan config:cache route:cache` after deployment, and keep `php artisan queue:work` plus `php artisan reverb:start` supervised.
- Restrict `/api/documentation` behind internal middleware or disable the L5-Swagger routes in production if the docs should not be public.
- Back up MySQL and monitor failed jobs (`failed_jobs`) and low-stock broadcasts.
