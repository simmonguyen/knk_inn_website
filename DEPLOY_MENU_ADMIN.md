# Deploy checklist — Menu admin (Stock Market Phase 1)

Short version: push the files, run migrate.php, spot-check three pages. 5 minutes.

## 1. Push the changed files to knkinn.com

The GitHub Action on push-to-main handles this. If you're pushing by hand over FTP, these are the files that moved:

New:
- `includes/migrations/003_menu_drinks.sql`
- `includes/menu_store.php`
- `menu.php`

Changed:
- `includes/orders_store.php`
- `includes/auth.php`
- `drinks.php`

## 2. Run the migration

Open this URL once in a browser (replace the password placeholder with the real one from `config.php`):

```
https://knkinn.com/migrate.php?key=YOUR-ADMIN-PASSWORD
```

You should see a short log ending with `003_menu_drinks.sql` in the "Applied" list. Running it a second time is safe — it'll skip already-applied migrations and the seed INSERTs use `INSERT IGNORE`, so nothing gets duplicated.

## 3. Smoke test (3 quick checks)

1. **`/menu.php`** — log in as you (super_admin). You should see 9 categories, 60 drinks. Change a price on one drink, hit Save, refresh. Change it back.

2. **`/drinks.php`** — open in a private window. The drinks list should match what's in `/menu.php`. If the category headings look blank in Vietnamese, the i18n file didn't pick up a new category — that's fine, it'll still work in English.

3. **`/order.php`** — open in a private window. Add a drink, confirm the order total matches. Check that the bartender email lands (Simmo's inbox).

## If anything looks off

- Blank menu on `/order.php` or `/drinks.php` → migration didn't apply. Re-run step 2.
- Menu link missing from the admin nav → hard-refresh the browser (the nav is cached by the browser).
- A drink is on `/menu.php` but missing from `/order.php` → check its "on menu" checkbox on `/menu.php`. Hidden drinks show on the admin page (greyed out) but not on the customer-facing pages.

## What this unlocks

Simmo (or you) can now:
- Change any drink price from the admin UI — no more editing code and re-deploying
- Add new drinks into existing categories, or create new categories
- Hide a drink that's out of stock without deleting it
- Reorder drinks within a category with the up/down arrows

This also lays the foundation for the Beer Stock Market — Phase 2 adds market pricing on top of this same table.
