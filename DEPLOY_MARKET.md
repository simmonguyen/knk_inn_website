# Deploy checklist — Beer Stock Market (Phase 2)

Short version: push the files, run migrate.php, wire up one cron, smoke-test four pages. 10 minutes.

If Phase 1 (Menu admin) has not shipped yet, do that first (see `DEPLOY_MENU_ADMIN.md`) — this phase builds on top of `menu_drinks`.

## 1. Push the changed files to knkinn.com

The GitHub Action on push-to-main handles this. If pushing by hand over FTP, these are the files:

New:
- `includes/migrations/004_market_stock.sql`
- `includes/market_engine.php`
- `market-admin.php`
- `market.php`
- `api/market_state.php`  *(create the `api/` folder first if you're on FTP)*
- `cron/market_tick.php`

Changed:
- `includes/auth.php` — adds **Market** link to the super_admin and owner nav
- `order.php` — reads live prices, enforces the 15-second price lock, runs the fair-play check

Nothing is removed.

## 2. Run the migration

Open this URL once in a browser (replace the placeholder with the real admin password from `config.php`):

```
https://knkinn.com/migrate.php?key=YOUR-ADMIN-PASSWORD
```

You should see `004_market_stock.sql` in the "Applied" list. It creates three tables (`market_config`, `market_pinned`, `market_events`) and seeds one config row and two empty pin slots. Running it twice is safe — it's `INSERT IGNORE` + `CREATE TABLE IF NOT EXISTS`.

## 3. Wire up the cron (Matbao → Cron Jobs)

One entry, runs every minute:

```
curl -s "https://knkinn.com/cron/market_tick.php?key=YOUR-ADMIN-PASSWORD"
```

Schedule: `* * * * *`  (every minute)

What it does each minute:
- Unwinds crashes whose timers have run out
- Refreshes band + demand prices on eligible drinks
- Rolls the dice on whether to fire an auto-crash

Matbao emails the cron log to Simmo by default — **turn that off** in the Cron Jobs panel (uncheck "Send email on output"), otherwise he'll get 1,440 emails a day.

The cron is a no-op whenever the market kill-switch is off (see step 5), so you can leave the cron wired up and just flip the market on/off from the admin page.

## 4. Smoke test (4 quick checks)

1. **`/market-admin.php`** — log in as super_admin. Confirm you see seven sections: Kill switch, Live state, Pin slots, Social Crash, Configuration, Reset to defaults, Recent events. Leave the kill switch **off** for now.

2. **`/market.php`** — open in a private window. With the market off you should see **"Market Closed"** on a dark page. No prices. No errors in the browser console.

3. **`/api/market_state.php`** — hit it directly. You should get JSON starting with `{"enabled":false,...}` and a `poll_seconds` of 60.

4. **`/order.php`** — open in a private window. Prices should match `menu.php` (the market is still off). Place a small test order — it should go through the regular path.

## 5. Turn it on

On `/market-admin.php`:

1. Hit **Pin slots**. Pick one beer for the "House Beer" slot and one drink Simmo likes for "Owner's Pick". Save.
2. Hit the **kill switch** — flip to ON.
3. Reload `/market.php` — you should now see at least the two pinned drinks on the Big Board, with green dots and sparklines.
4. Reload `/order.php` — each drink on the board should show a small trend arrow next to its price. Add one to an order and confirm you can place it.
5. Back on `/market-admin.php`, hit **Social Crash** with a 20% drop for 3 minutes. Watch `/market.php` — a red "Flash Crash" banner should appear, and the crashed drinks should go red with "Crashing" tags. After 3 minutes the cron tick unwinds them back to their computed band price.

## 6. Point the TV at it

On the ground-floor Sony:
- Open Chrome, go to `https://knkinn.com/market.php`
- Press **F11** for full-screen, or install the Chrome "Fullscreen" launcher
- The board redraws itself every few seconds — no keyboard needed after that

## If anything looks off

- **Big Board empty even though the market is on** → not enough orders in the last 7 days to hit the "min 5 orders" eligibility floor. Pin two drinks from `/market-admin.php` and they'll show regardless. Or lower `eligibility_min_orders` in Configuration.
- **Prices keep bouncing around wildly** → `demand_max_bp` is too high, or `demand_min_bp` too low. Tighten the band on `/market-admin.php → Demand engine`.
- **Crashes firing back-to-back** → `crash_cadence_min` is too low. Push it up to 60+. Or hit "Reset to defaults" on `/market-admin.php`, which restores the recommended values and turns the market off so Simmo can re-enable when he's ready.
- **Price-lock reconfirms happen on every order** → `price_lock_seconds` is too short for the menu being slow to fill in. Bump it to 30 on `/market-admin.php → Fair-play & timing`.
- **Cron mailer flooding Simmo** → uncheck "Send email on output" on the Matbao Cron Jobs panel.
- **403 "forbidden" on the cron URL** → the `?key=` doesn't match `admin_password` in `config.php`. Update the Matbao cron command.

## What this unlocks

Simmo (or you) can now:
- Run a live-pricing "stock market" for drinks during busy nights
- Pin a House Beer and an Owner's Pick onto the Big Board regardless of order volume
- Fire a Social Crash by hand when the bar needs a jolt
- Force a specific price on any drink (with an optional lock timer)
- Tune every knob — bands, demand sensitivity, crash cadence, caps, fair-play guards — from one admin page
- Reset everything back to the recommended defaults with one button when it gets out of hand

## Rollback

Phase 2 is isolated behind the `market_` table prefix. If you need to fully unwind it:

1. Flip the kill switch off on `/market-admin.php` (Simmo keeps trading on menu prices).
2. Remove the cron entry on Matbao.
3. (Optional — only if you want the tables gone too) Run, against `knk59267_knkinn`:
   ```sql
   DROP TABLE market_events;
   DROP TABLE market_pinned;
   DROP TABLE market_config;
   DELETE FROM schema_migrations WHERE name = '004_market_stock.sql';
   ```

Phase 1 (menu admin) is untouched either way.
