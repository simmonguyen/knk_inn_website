# Room rates + OTA channel sync — deploy notes

This is the short version of how the per-room rate engine is wired
and what to plug into Airbnb / Booking.com / Tripadvisor.

## What's where

| Piece | URL | Who uses it |
| --- | --- | --- |
| Rate calendar editor | `/room-rates.php` | Staff (bookings permission) |
| Settings → export key | `/settings.php` | Super admin |
| Rates JSON feed | `/api/room_rates_export.php` | Channel manager |
| Availability JSON feed | `/api/room_availability_export.php` | Channel manager |
| iCal feed per room type | `/bookings.ics.php?type=…` | Channel manager (or Google Calendar) |

## First-time setup

1. Run migrations on the live site so the new tables exist:

   ```
   https://knkinn.com/migrate.php?key=Knk@070475
   ```

   This creates `rooms`, `room_rate_seasons`, `room_rates` and
   `darts_games.scoring_mode` from migrations 026 + 027.

2. Open `/room-rates.php` (Bookings permission required).
   The 7 physical rooms are already seeded with placeholder rates
   (650k / 850k / 1.25M VND). Update each room's "Default rate" to
   the rack price you actually want listed.

3. Optionally paint seasonal overrides — Tet, NYE, big public
   holidays. The "Paint a date range" form bulk-sets every night
   in the range to one rate + tier.

4. Open `/settings.php` → "Room-rates export key" and click
   **Regenerate** to produce a fresh 32-char hex key. The card
   then shows you a copy-pasteable URL — that's what the channel
   manager wants.

## Plugging into the OTAs

Most channel managers (and Booking.com / Tripadvisor direct) accept
two kinds of inputs:

### Rates + availability (JSON pull, ours)

The channel manager calls these URLs every few hours:

```
https://knkinn.com/api/room_rates_export.php?key=<KEY>&room=<SLUG>&days=180
https://knkinn.com/api/room_availability_export.php?key=<KEY>&type=<TYPE>&days=180
```

Room slugs (one per physical room — used by the rates feed):

- `standard-nowindow-1`
- `standard-balcony-2` / `-3` / `-4`
- `vip-2` / `-3` / `-4`

Room types (one per category — used by the availability feed):

- `standard-nowindow`
- `standard-balcony`
- `vip`

### Calendar block (iCal pull, universal)

Every OTA accepts iCal. Use these in the listing's "Sync calendar /
Import iCal" field:

```
https://knkinn.com/bookings.ics.php?key=<ICS_KEY>&type=<TYPE>
```

`<ICS_KEY>` is from `config.php` (`ics_key`) — it's separate from
the rates export key on purpose so Simmo's Google Calendar
subscription doesn't break when he regenerates the rates key.

## Matching listings to slugs

Each Airbnb / Booking.com listing should map to one physical room:

| Listing | Room slug | Type |
| --- | --- | --- |
| Standard No Window — F1 | `standard-nowindow-1` | `standard-nowindow` |
| Balcony — F2 | `standard-balcony-2` | `standard-balcony` |
| Balcony — F3 | `standard-balcony-3` | `standard-balcony` |
| Balcony — F4 | `standard-balcony-4` | `standard-balcony` |
| VIP — F2 | `vip-2` | `vip` |
| VIP — F3 | `vip-3` | `vip` |
| VIP — F4 | `vip-4` | `vip` |

When a booking comes in via an OTA, the channel manager writes an
event to our iCal feed (or back to a webhook — whichever it
supports). For now we're not running a write-back endpoint, so an
OTA booking will need to be manually recorded in `/bookings.php`
to keep the public-site calendar in sync.

## Smoke test

Once a key is set:

```
curl "https://knkinn.com/api/room_rates_export.php?key=<KEY>&room=vip-3&days=7"
curl "https://knkinn.com/api/room_availability_export.php?key=<KEY>&type=vip&days=7"
```

Both should return `{"ok": true, ...}`. A 403 means the key is
wrong; a 400 means a missing/invalid parameter.

## Safety notes

- The rates feed and availability feed are read-only — no writes
  are exposed. Worst case if the key leaks: someone gets to see
  our rate calendar.
- Regenerating the key in `/settings.php` instantly invalidates
  the old one. Update the channel-manager URLs after rotating.
- Migration 026 left placeholder rates seeded. Don't go live with
  those — they're sane defaults but not the real rack rates.
