# KYC Bank integration

ZuruBank can ask KYC Bank, the shared KYC service, for a customer's verified KYC. Staff send a
request by the customer's mobile number or Omang. The customer approves it in the KYC Bank app.
KYC Bank then sends ZuruBank a signed webhook and ZuruBank fetches the verified profile. Staff
read the profile and decide. KYC Bank never says whether a person has a profile: an unknown
number just looks like a request that is still waiting.

## Files

| File | What it does |
| --- | --- |
| `Backend/integrations/kycbank/KycBankClient.php` | API client: token, consent requests, profile, webhook signature check |
| `Backend/integrations/kycbank/kycbank.php` | Tables, state handling shared by the webhook and the dashboard, staff review |
| `Backend/integrations/kycbank/webhook.php` | Public webhook endpoint |
| `Frontend/dashboard/admin_kyc.php` | Admin page: Customers & KYC (linked from `admin_dashboard.php`) |
| `database/migrations/2026_10_30_kycbank.sql` | The tables, for applying by hand |
| `tests/kycbank_signature_test.php` | CLI test for webhook signatures: `php tests/kycbank_signature_test.php` |

## Environment variables

Set these on the ZuruBank service in Railway. Never commit their values.

- `KYCBANK_API_URL`: API base URL (staging: `https://api-staging-fd41.up.railway.app`)
- `KYCBANK_CLIENT_ID`
- `KYCBANK_CLIENT_SECRET`
- `KYCBANK_WEBHOOK_SECRET`: the webhook signing secret from KYC Bank

If the first three are missing, the admin page shows "KYC Bank is not configured" and still lists
customers and stored requests. If `KYCBANK_WEBHOOK_SECRET` is missing, the webhook answers 503, so
KYC Bank keeps retrying until the secret is set.

## Webhook URL

The app is served with `php -S 0.0.0.0:$PORT -t .`, so the repository root is the document root.
Register this URL with KYC Bank:

```
https://<zurubank domain>/Backend/integrations/kycbank/webhook.php
```

In production that is `https://zurubank-production.up.railway.app/Backend/integrations/kycbank/webhook.php`.

The endpoint accepts POST only. It checks `x-kyc-signature` (`t=<unix seconds>,v1=<hex>`, where
v1 is the HMAC-SHA256 of `"<t>.<raw body>"`) and rejects with 401 a missing or wrong signature, or a
`t` more than 5 minutes off. An event id that was already processed gets 200 and is not processed
again. Test pings and unknown consent ids get 200 and change nothing. When KYC Bank can't be
reached during processing, the endpoint answers 503 and KYC Bank delivers the event again.

## Database

The database is PostgreSQL. `kycbank.php` creates the tables on first use (on the first load of
the admin page or the first webhook), the same way `Backend/helpers/secondary_email.php` does. To
apply them by hand, which is idempotent and safe to re-run:

```
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_10_30_kycbank.sql
```

On Railway, run that against the project's Postgres service. From a machine with the Railway CLI,
linked to the `zurubank` project, run `railway connect <Postgres service>`. Then in psql run
`\i database/migrations/2026_10_30_kycbank.sql`. The app service's `DATABASE_URL` uses Railway's
private network and can't be reached from outside, so use the Postgres service's public URL
instead. The repository has no automatic migration step: deploys don't run SQL files.

The migration does three things:

- creates `kycbank_requests`, one row per request. The mobile/Omang value used to ask is not
  stored, only its kind.
- creates `kycbank_webhook_events`, which holds the ids of processed events.
- adds `users.kyc_status` (`pending` by default, then `approved` / `rejected`) if it is missing.

## Staff flow

1. Admin dashboard → **Open Customers & KYC** → find the customer → **Open**.
2. **Request KYC from KYC Bank**. Choose the mobile number (filled in from the phone on file, in
   +267 form) or Omang, the purpose shown to the customer (default "Open and verify your Zuru Bank
   account") and the level (basic or standard).
3. The request shows its state:
   - waiting for the customer
   - active
   - suspended, with the reason in plain words (for example, the customer has not finished
     verifying with KYC Bank, in which case sharing starts by itself when they do)
   - declined, lapsed or expired

   It also shows its dates. Webhooks keep it up to date. **Refresh status** asks KYC Bank directly
   in case a webhook was missed. If KYC Bank could not be reached when the request was sent,
   **Send again** reuses the same request: same idempotency key, so the customer is not asked twice.
4. When the consent is active, the profile is fetched and marked "needs staff review". Click
   **View KYC Bank profile**, then either:
   - **Approve KYC using this profile**, which sets the customer's KYC status to approved and
     records who decided and when
   - **Reject**

   Nothing changes the KYC status until staff click one of these.
5. When the customer's KYC Bank profile changes, ZuruBank fetches the new version and flags it
   "Updated profile — needs staff review". The KYC status stays as it was.
6. If the customer withdraws consent, the request shows "Withdrawn by the customer on <date> — data
   no longer in use". The profile is hidden and is never fetched again. The stored copy is kept
   only for legal record-keeping. The KYC status is not changed automatically, but if staff had
   approved KYC on that consent, the page warns that the KYC basis was withdrawn.

Logs, webhook replies and `audit_logs` entries carry ids only (request, consent, event, user),
never names, numbers or profile content.
