# Beds24 v2 — direct booking outbound sync

## What this does

When a direct booking on knkinn.com gets confirmed (either via the
guest's email magic link or staff confirming in `/bookings.php`),
the website now pushes that booking to Beds24 as a confirmed
booking. Beds24 then propagates the room-night occupancy to
Booking.com and Airbnb so neither OTA can sell that night
separately.

Without this, two guests can book the same physical room from
two different OTAs simultaneously and the second arrival walks
into an already-occupied room.

This is **outbound only**. Inbound sync (bookings made on
Booking.com / Airbnb flowing back into our local system) is
already handled by Beds24's iCal feeds and by the channel-manager
connection we set up earlier.

## Files

- `includes/beds24_api.php` — new. v2 client (auth, push, cancel).
- `includes/bookings_store.php` — modified. Hooks into the two
  status-change helpers to push on confirm and cancel on decline.
- `config.example.php` — modified. Added the `beds24` config block.

## Activation steps

### 1. Generate a Beds24 v2 long-life token

1. Sign in to Beds24 admin (Simmo's account).
2. Settings → Account → API → **Long-life Tokens**.
3. Create a new token. Give it a name like "knkinn.com push".
4. Copy the **refreshToken** value (long string starting with
   `bL...` or similar). This is shown ONCE — save it now.

### 2. Add config to production

Edit `config.php` on Mat Bao (NOT `config.example.php` — that's the
template, gitignored from `config.php`). Add the `beds24` block:

```php
"beds24" => [
    "refresh_token" => "PASTE_THE_LONG_TOKEN_HERE",
    "property_id"   => 325504,
    "room_map"      => [
        "vip"               => 675740, // Premium
        "standard-balcony"  => 676694, // Superior
        "standard-nowindow" => 676693, // Standard
    ],
],
```

Leave `refresh_token` empty to keep the integration disabled — the
rest of the booking flow works fine without it.

### 3. Verify

- Tail `error_log` on Mat Bao after a confirm.
- Look for entries starting with `KnK Beds24:`.
- A successful push is silent; a failure logs the reason.
- Beds24 admin → Bookings will show the new booking with
  `referer = "Direct - knkinn.com"`.

## How it works (mental model)

```
guest fills out enquire.php
   │
   ▼
bookings_create_hold()  → status: "pending"
   │
   ▼  (email link clicked, OR staff confirms in /bookings.php)
bookings_set_status_by_token() / by_id()
   │
   ├─ flips status → "confirmed"
   ├─ saves bookings.json
   ▼
knk_bookings_after_status_change(hold, "confirm")
   │
   ▼
knk_beds24_push_confirmed(hold)
   │
   ├─ get/refresh access token (cached in /tmp)
   ├─ POST /v2/bookings   payload: arrival, departure, guest, etc.
   └─ stores beds24_booking_id back on the local hold
   │
   ▼
Beds24 propagates to Booking.com / Airbnb
   (those nights show as "blocked" within ~1-5 minutes)
```

## Idempotency / safety

- **Re-confirms are safe**: the push function checks for an
  existing `beds24_booking_id` on the hold and returns early if
  one is present. A double-confirm by accident won't create
  duplicate Beds24 bookings.
- **Beds24 outage doesn't break the site**: every API call is
  wrapped in try/catch. Failure logs to `error_log` but the
  guest's confirm still succeeds locally — staff can re-trigger
  the push manually later.
- **Decline path also runs**: if a hold is declined AFTER it was
  pushed (rare — usually decline happens from pending), the
  Beds24 booking is auto-cancelled.

## Known gaps (for future work)

1. **No "Basic" room slug yet.** `bookings_store.php` has 3 slugs
   (`vip`, `standard-balcony`, `standard-nowindow`) but our actual
   inventory has 4 categories (Premium / Superior / Standard /
   Basic). Any direct booking for "Basic" can't currently go
   through this code path because there's no slug. Fix: add
   "basic" to `KNK_ROOM_INVENTORY` and to `room_map` (Beds24 id
   675750). This is a separate piece of work because it also
   requires adjustments to enquire.php's room dropdown.

2. **Inbound sync is via iCal not API.** Beds24 → Booking.com →
   us bookings still come through the iCal channel feeds (which
   the channel-manager handles automatically). If we want
   real-time inbound (instead of every-15-min polling), we'd
   need a Beds24 webhook. Not urgent.

3. **Manual retry button.** When a push fails, staff have no UI
   to retry it. They'd have to flip the hold's status back to
   pending and re-confirm. A "Resync to Beds24" button on
   `/bookings.php` would be a nice-to-have.

4. **Token refresh resilience.** If the refreshToken gets revoked
   (Simmo regenerates it), we'll keep failing until the new one
   is pasted into config.php. The error_log will show
   "Beds24 HTTP 401" repeatedly. Worth adding a settings.php
   admin field so this can be rotated without an FTP deploy.

## What I tested

- Visual PHP 7.4 compatibility check (no `match`, `?->`,
  `str_ends_with`, `enum`, `readonly`).
- Config-disabled path (refresh_token empty): all calls return
  early with no errors.
- Mapping miss (slug not in room_map): logs and returns 0,
  doesn't blow up.

## What I did NOT test (needs Ben on Mat Bao)

- A real round-trip with a live token and a real booking.
- Mat Bao's outbound HTTPS allow-list — should be open but
  cURL connectivity to api.beds24.com hasn't been confirmed.
- Whether Mat Bao's `/tmp` is writable for the token cache.
  If not, switch `KNK_BEDS24_TOKEN_PATH` to a path under
  `__DIR__ . "/.."` (the website root) instead.
