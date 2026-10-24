# Reliable Payment Workflow: Laravel + Razorpay (Test Mode)

Laravel 12 / PHP 8.2 / SQLite. The backend is the source of truth for totals and payment status.

## Requirements

- PHP 8.2 or newer with the `pdo_sqlite`, `curl`, `mbstring`, `openssl` and `fileinfo` extensions
- Composer 2
- A Razorpay account (Test Mode is enough): https://dashboard.razorpay.com
- ngrok, only if you want to receive real webhooks locally

## Setup

```bash
git clone <repo-url> laravel-task-codepluse
cd laravel-task-codepluse

composer install
cp .env.example .env
php artisan key:generate
```

Open `.env` and fill in your Razorpay **Test Mode** keys (Dashboard → Account & Settings → API Keys):

```env
RAZORPAY_KEY_ID=rzp_test_xxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxx
RAZORPAY_WEBHOOK_SECRET=any-long-random-string
```

Create the database and sample data, then start the server:

```bash
touch database/database.sqlite     # Windows PowerShell: New-Item database/database.sqlite
php artisan migrate --seed         # tables + 4 sample products
php artisan serve --port=8000
```

Open http://localhost:8000. No `npm install` is needed, the UI is a single static page.

To use MySQL instead of SQLite, set `DB_CONNECTION=mysql` and the `DB_*` values in `.env`, then run `php artisan shop:setup`. It creates the database if it does not exist, then migrates and seeds. Add `--fresh` to wipe and rebuild.

| Env var | Purpose |
|---|---|
| `RAZORPAY_KEY_ID` | Public key id (returned to the frontend) |
| `RAZORPAY_KEY_SECRET` | Server only. Creates orders and verifies the checkout signature |
| `RAZORPAY_WEBHOOK_SECRET` | Server only. Verifies `X-Razorpay-Signature` |
| `RAZORPAY_CURRENCY` | Default `INR` |
| `SHOP_NAME`, `SHOP_ADDRESS`, `SHOP_GSTIN`, `SHOP_EMAIL` | Seller details printed on invoices |

`.env` is git-ignored; secrets never appear in responses or logs.
**Money is stored in paise** (integer): `79900` = ₹799.00.

### Quick check

```bash
php artisan test                    # 36 tests, Razorpay API is faked, no keys needed
curl localhost:8000/api/products    # should list the 4 seeded products
```

### Common problems

| Problem | Fix |
|---|---|
| `No application encryption key` | Run `php artisan key:generate` |
| `could not find driver` | Enable `pdo_sqlite` in `php.ini` |
| `unable to open database file` | `database/database.sqlite` does not exist yet, create it (see above) |
| `502 payment_gateway_error` when paying | Wrong or missing Razorpay keys, or no internet. Check `RAZORPAY_KEY_ID` / `RAZORPAY_KEY_SECRET`, then `php artisan config:clear` |
| Webhook returns `400` | `RAZORPAY_WEBHOOK_SECRET` differs from the secret entered in the Razorpay dashboard |
| `payment_link` is `null` | Optional. Razorpay could not create the link; Checkout still works |

## UI

Open `http://localhost:8000`: Shop (cart + Razorpay Checkout), Orders, Products (CRUD), Webhooks log, Dashboard. Everything is loaded from the API/database.

## Webhook setup (ngrok)

1. `ngrok http 8000`
2. Razorpay Dashboard (Test Mode) → Settings → Webhooks → Add: `https://<id>.ngrok-free.app/api/webhooks/razorpay`
3. Enter a secret (the same value as `RAZORPAY_WEBHOOK_SECRET`) and enable `payment.captured`, `payment.failed`, `order.paid` and `payment_link.paid`.
4. Make a test payment with Razorpay Checkout, then check the `webhook_events`, `payments`, `payment_attempts` tables and `storage/logs/laravel.log`.

## API

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/api/products` | in-stock list (`?all=1` includes out-of-stock) |
| POST/PUT/DELETE | `/api/products[/{id}]` | product CRUD (price in paise; delete blocked with 409 if the product is in an order) |
| GET | `/api/orders` | order list |
| GET | `/api/dashboard/stats`, `/api/webhook-events` | dashboard numbers, webhook audit log |
| POST | `/api/orders` | `{"items":[{"product_id":1,"quantity":2}]}`; any client `amount` is ignored |
| POST | `/api/orders/{id}/payment` | creates (or reuses the open) Razorpay order; returns `key_id, razorpay_order_id, amount, currency, order_reference, order_id, payment_link` (hosted Razorpay Payment Link you can send to the customer; `null` if Razorpay couldn't create one) |
| POST | `/api/payments/verify` | `{razorpay_order_id, razorpay_payment_id, razorpay_signature}` from Checkout |
| POST | `/api/webhooks/razorpay` | signed by Razorpay |
| GET | `/api/orders/{id}` | order, items, payments and attempts |

```bash
curl localhost:8000/api/products
curl -X POST localhost:8000/api/orders -H "Content-Type: application/json" \
  -d '{"items":[{"product_id":1,"quantity":2},{"product_id":3,"quantity":1}]}'
curl -X POST localhost:8000/api/orders/1/payment
# ...pay in Razorpay Checkout, then:
curl -X POST localhost:8000/api/payments/verify -H "Content-Type: application/json" \
  -d '{"razorpay_order_id":"order_xxx","razorpay_payment_id":"pay_xxx","razorpay_signature":"..."}'
curl localhost:8000/api/orders/1
```

Simulate a webhook without Razorpay (sign the **exact bytes** you send):

```bash
BODY='{"event":"payment.captured","payload":{"payment":{"entity":{"id":"pay_test1","order_id":"order_xxx","amount":209700,"currency":"INR","status":"captured"}}}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$RAZORPAY_WEBHOOK_SECRET" | awk '{print $NF}')
curl -X POST localhost:8000/api/webhooks/razorpay -H "Content-Type: application/json" \
  -H "X-Razorpay-Signature: $SIG" -H "X-Razorpay-Event-Id: evt_demo_1" --data-binary "$BODY"
```

## Tests / evidence for the mandatory scenarios

```bash
php artisan test
```

`tests/Feature/PaymentWorkflowTest.php` (Razorpay API faked):

| # | Scenario | Test |
|---|---|---|
| 1 | Successful payment | `test_successful_payment` |
| 2 | Invalid signature | `test_invalid_or_missing_signature_is_rejected_without_changes` (also covers a signature computed over re-encoded JSON) |
| 3 | Duplicate webhook | `test_duplicate_webhook_has_no_extra_side_effects`, `..._deduplicated_by_body_hash` |
| 4 | Failed + retry | `test_failed_payment_then_successful_retry` |
| 5 | Browser closed | `test_webhook_settles_order_without_any_checkout_callback` |
| 6 | Out-of-order | `test_delayed_failure_cannot_downgrade_a_paid_order` |

Extras: amount mismatch, `order.paid`, unknown events, forged checkout signature, totals from DB, stock rollback.

## Design decisions

**Data model.** `orders` (1-n) `order_items` (price snapshot, unique per order/product) and `payments` (1 row per Razorpay order, unique `razorpay_order_id`). `payment_attempts` is 1 row per Razorpay payment id (unique), so failed tries and the later success stay traceable even though Razorpay lets a customer retry on the same Razorpay order. `webhook_events` is an independent audit log (unique `event_id`, raw payload, status, attempts, error).

**Orders and stock.** Totals come only from DB prices. Inside one transaction, products are locked in id order (no deadlocks) and stock is reserved with a conditional `UPDATE ... WHERE stock_quantity >= n`, so concurrent buyers can never oversell. Any failure rolls back the whole order. *Trade-off:* stock is reserved at order creation and there is no expiry job to release it for abandoned orders (the obvious next step).

**Why a browser callback alone isn't proof.** The callback is just a JS call from the customer's device. It can be forged or replayed, and it can be lost (tab closed, network drop) even though the money moved. `/api/payments/verify` therefore only trusts an HMAC made with our secret over `order_id|payment_id` (the browser can't forge it), and the signed webhook is the reliable path that works even if the browser never comes back. Both go through the same `PaymentSettlementService`.

**Webhook security.** HMAC-SHA256 is computed over `$request->getContent()` (the raw bytes, never re-encoded JSON) and compared with `hash_equals`. A missing or bad signature gets `400` before anything is written. Captured events must also match the stored amount and currency.

**Idempotency and state rules.**
- The dedup key is `X-Razorpay-Event-Id`, falling back to `sha256(raw body)` (retries are byte-identical). A unique index plus a `lockForUpdate` on the event row serialises concurrent duplicates; a repeat returns `200 {"status":"duplicate"}` with no side effects.
- `captured` is terminal: late or duplicate failures only add an attempt row and never touch the order. The order only moves `unpaid → paid`.
- Payment/order rows are locked inside a transaction for every state change.
- If a second payment is captured for an already-paid order, it is recorded and logged for refund review; the order is unchanged.

**HTTP codes (webhook).** `400` bad or missing signature or malformed body. `200` processed, ignored (unsupported event, unknown order, amount mismatch; recorded in `webhook_events`) or duplicate, so Razorpay stops retrying. `500` unexpected error: the event is marked `failed` and reprocessed on Razorpay's retry.

**Known limits.** No authentication or ownership checks on orders (out of scope for the exercise); the Razorpay order is created while holding the order row lock (simple and correct, but a slow API call holds the lock); refunds are not implemented.



## Cancel, invoice and API response format

| Method | Endpoint | Notes |
|---|---|---|
| POST | `/api/orders/{id}/cancel` | Cancels an **unpaid** order: stock is restored, open payments are closed, the Razorpay Payment Link is cancelled. Idempotent. Paid orders return `409`. |
| GET | `/api/orders/{id}/invoice` | Invoice JSON; `409` until the order is paid. |
| GET | `/invoice/{order_number}` | Printable invoice page (Print / Save as PDF). |

`POST /api/orders` also accepts an optional `customer: {name, email, contact}` which is printed on the invoice.
If a payment arrives *after* an order was cancelled (old link or Checkout window), the money is recorded (`payment_status=paid`), the order stays `cancelled` and a warning is logged so it can be refunded.

Every JSON response has the same envelope:

```json
{ "success": true,  "data": { ... }, "message": "optional" }
{ "success": false, "code": "validation_failed", "message": "Product 999 does not exist.", "errors": { "items.0.product_id": ["..."] } }
```

| Status | `code` | When |
|---|---|---|
| 400 | `invalid_signature` / `bad_request` | bad checkout or webhook signature |
| 404 | `not_found` | unknown order/product/endpoint (`"Order not found."`) |
| 405 | `method_not_allowed` | wrong HTTP verb |
| 409 | `conflict` | already paid, cancelled, product in orders, invoice before payment |
| 422 | `validation_failed` | invalid input (`message` is the first problem, `errors` has all) |
| 502 | `payment_gateway_error` | Razorpay rejected or was unreachable |
| 500 | `server_error` | unexpected; no SQL/stack trace is ever returned (`debug` only when `APP_DEBUG=true`) |
