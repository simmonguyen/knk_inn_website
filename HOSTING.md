# KnK Inn — Uploading to www.KnKinn.com

You've got a fully static site: HTML, CSS, JS and JPEGs. No database, no backend — which means you can host it almost anywhere. Four options, cheapest and easiest first.

---

## Option 1 — Netlify (easiest, free, HTTPS included)

**Best for:** non-technical. Drag-and-drop deploy. Takes 5 minutes.

1. Go to https://app.netlify.com/drop
2. Drag the entire `knkinn_site` folder onto the page.
3. Netlify gives you a temporary URL like `glowing-fox-123.netlify.app`.
4. In Netlify dashboard → **Domain settings** → **Add custom domain** → type `knkinn.com`.
5. Follow Netlify's DNS instructions. Two ways:
   - **Easiest:** point your domain's nameservers to Netlify (they give you 4 addresses like `dns1.p03.nsone.net`). Log in to wherever you bought knkinn.com and change nameservers.
   - **Or:** keep your existing DNS and add an A record for `@` → `75.2.60.5` and a CNAME for `www` → `your-site.netlify.app`.
6. Netlify provisions a free SSL cert automatically. Done.

**Cost:** $0/month for sites of this size. Updates = drag the folder again.

---

## Option 2 — Vercel (same idea, also free)

1. Sign up at https://vercel.com.
2. Click **Add New Project** → **Upload folder** → pick `knkinn_site`.
3. In project settings → **Domains** → add `knkinn.com` and `www.knkinn.com`.
4. Vercel shows you DNS records to add at your registrar.
5. SSL is automatic.

**Cost:** $0/month. Same UX as Netlify, pick whichever feels friendlier.

---

## Option 3 — Your existing host (cPanel / FTP)

**Best for:** if you already pay for web hosting with somebody (GoDaddy, Hostinger, Bluehost, SiteGround, a Vietnamese host, etc.) and you want to use what you've got.

### Via cPanel File Manager
1. Log in to your hosting control panel.
2. Open **File Manager** and navigate to the `public_html` folder (sometimes `www` or `htdocs`).
3. Delete any existing `index.php` there.
4. Upload the contents of `knkinn_site/` into `public_html/` — **everything inside**, not the folder itself. So `public_html/index.php`, `public_html/assets/...` etc.
5. Visit `www.knkinn.com`. Done.

### Via FTP (if you prefer)
- Get the FTP host/username/password from your hosting dashboard.
- Use **FileZilla** (free) or **Cyberduck**.
- Drop the contents of `knkinn_site/` into `public_html/`.

**SSL:** most hosts now offer free Let's Encrypt SSL via a one-click install in the cPanel dashboard. Turn it on — you want your site on `https://`.

---

## Option 4 — WordPress-style (don't, unless you already have it)

If your current knkinn.com is running WordPress and you want to keep it for some reason, you can:
- Install the **Simply Static** plugin and replace the WP theme, or
- Just uninstall WordPress and use Option 3 above.

WordPress adds a database, plugins, security updates — things a static site doesn't need. For KnK Inn I'd recommend **not** using WordPress.

---

## DNS — What does `knkinn.com` currently point at?

Two ways to find out:
- Paste the domain into https://dnschecker.org and look at the A record.
- Or `dig www.knkinn.com` / `nslookup www.knkinn.com` from a terminal.

If the current site is somewhere you don't want to keep (e.g. a generic "parked" page from a registrar), you'll need to either:
- **Change nameservers** to the new host (easiest; they control everything for you), or
- **Update A / CNAME records** at your current registrar.

Either way, DNS changes can take a few minutes to a few hours to propagate.

---

## Recommendation

For a site like KnK Inn: **Netlify Drop**. Free, SSL included, no server to worry about, updating the site later is a drag-and-drop. If you have a tech-savvy friend, they can also wire it to your GitHub so every edit auto-deploys — but start simple.

---

## What's in this folder

```
knkinn_site/
├── index.php          — Home page
├── rooms.html          — Accommodation gallery
├── drinks.php         — Full drinks menu
├── gallery.html        — All 92 photos with category filters
├── HOSTING.md          — This file
└── assets/
    ├── css/styles.css  — Shared stylesheet
    ├── js/
    │   ├── i18n.js     — 8-language translations
    │   └── main.js     — Nav, lightbox, sports fixtures
    └── img/            — 92 optimised JPEGs (ex_*.jpg and nw_*.jpg)
```

**Total size:** ~10 MB. Loads fast on 4G. Works on phones, tablets, desktops.

---

## Languages

The site supports 8 languages via a dropdown in the top-right: English, Tiếng Việt, 中文, 日本語, 한국어, Français, Español, Deutsch. Selection is remembered in the browser for return visits.

## Sports fixtures

The Upcoming Sports section tries to fetch live data from TheSportsDB's free API. If that call fails (or returns nothing), it shows a curated fallback list of the next 30 days' major events with kickoff times in **Saigon time (ICT, UTC+7)**.

To replace the fallback list, edit the `fallbackFixtures()` array in `assets/js/main.js` — each entry has `sport`, `title`, `subtitle`, and an ISO `kickoff` timestamp (use a UTC time with `Z` suffix and the page converts it to Saigon time automatically).

## Photos

To add new photos: drop JPEGs into `assets/img/` and reference them in the relevant HTML page. To remove a photo: delete the file and remove the `<div class="masonry-item">` or `<div class="room-card">` from the page.

---

Questions? Email the site back to me (Claude) for edits, or if you have a developer friend give them this folder — it's standard static HTML, anyone can work with it.
