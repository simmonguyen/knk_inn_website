/*
 * KnK Inn — per-room booking widget.
 *
 * Room page provides:
 *   <div id="booking-widget"
 *        data-room-id="f2-vip"
 *        data-room-name="Floor 2 VIP"
 *        data-room-type="vip"
 *        data-price="900000">
 *   </div>
 *
 * This script:
 *  1. Fetches blocked dates from /availability.php
 *  2. Renders an inline flatpickr date-range calendar
 *  3. On date pick → shows price + proceed button
 *  4. Step 2 → personal details form
 *  5. Submit → POSTs to enquire.php with type=booking
 */
(function () {
  "use strict";

  const widget = document.getElementById("booking-widget");
  if (!widget) return;

  const roomId    = widget.dataset.roomId;
  const roomName  = widget.dataset.roomName;
  const price     = parseInt(widget.dataset.price, 10) || 0;

  // ---- layout ----------------------------------------------------------
  widget.innerHTML = `
    <div class="booking-steps">

      <div class="booking-step" data-step="1">
        <div class="booking-step-head">
          <span class="booking-step-num">1</span>
          <div>
            <h3>Pick your dates</h3>
            <p class="muted">Crossed-out days are already booked.</p>
          </div>
        </div>
        <input type="text" id="booking-dates" class="booking-date-input" placeholder="Select check-in and check-out" readonly>
        <div id="booking-summary" class="booking-summary" hidden></div>
        <button type="button" id="booking-next" class="btn-primary booking-next" disabled>Next: your details →</button>
      </div>

      <div class="booking-step" data-step="2" hidden>
        <div class="booking-step-head">
          <span class="booking-step-num">2</span>
          <div>
            <h3>Your details</h3>
            <p class="muted">Simmo will confirm within 24h by email.</p>
          </div>
        </div>

        <form id="booking-form" action="../enquire.php" method="POST" novalidate>
          <input type="hidden" name="type"  value="booking">
          <input type="hidden" name="room"  value="${roomId}">
          <input type="hidden" name="price" value="${price}">
          <input type="hidden" name="checkin"  id="bf-checkin">
          <input type="hidden" name="checkout" id="bf-checkout">

          <!-- honeypot renamed to dodge Chrome autofill -->
          <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
            <label>Address <input type="text" name="hp_url" tabindex="-1" autocomplete="off"></label>
          </div>

          <div class="booking-fields">
            <label>Your name<span class="req">*</span>
              <input type="text" name="name" required maxlength="100">
            </label>
            <label>Email<span class="req">*</span>
              <input type="email" name="email" required maxlength="120">
            </label>
            <label>Phone
              <input type="tel" name="phone" maxlength="40" placeholder="Optional">
            </label>
            <label>Guests
              <select name="guests">
                <option value="1">1 guest</option>
                <option value="2" selected>2 guests</option>
                <option value="3">3 guests</option>
                <option value="4">4 guests</option>
              </select>
            </label>
          </div>

          <label class="full">Anything else we should know?
            <textarea name="message" maxlength="2000" placeholder="Arrival time, quiet room, long stay, etc."></textarea>
          </label>

          <div class="booking-actions">
            <button type="button" id="booking-back" class="btn-ghost">← Back to dates</button>
            <button type="submit" class="btn-primary">Send booking request</button>
          </div>
        </form>
      </div>

    </div>
  `;

  // ---- load flatpickr on demand ---------------------------------------
  function loadCss(href) {
    const l = document.createElement("link");
    l.rel = "stylesheet"; l.href = href;
    document.head.appendChild(l);
  }
  function loadScript(src) {
    return new Promise((ok, bad) => {
      const s = document.createElement("script");
      s.src = src; s.onload = ok; s.onerror = bad;
      document.head.appendChild(s);
    });
  }

  loadCss("https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css");
  loadCss("https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/themes/dark.css");

  // ---- fetch blocked dates --------------------------------------------
  const blockedPromise = fetch(`../availability.php?room=${encodeURIComponent(roomId)}`)
    .then(r => r.ok ? r.json() : { blocked: [] })
    .catch(() => ({ blocked: [] }));

  Promise.all([
    loadScript("https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"),
    blockedPromise,
  ]).then(([_, availability]) => {
    const blocked = availability.blocked || [];
    initCalendar(blocked);
  });

  // ---- calendar --------------------------------------------------------
  function initCalendar(blocked) {
    const fmt = (d) =>
      d.getFullYear() + "-" +
      String(d.getMonth() + 1).padStart(2, "0") + "-" +
      String(d.getDate()).padStart(2, "0");

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const fp = flatpickr("#booking-dates", {
      mode: "range",
      inline: true,
      minDate: today,
      disable: blocked,      // array of "YYYY-MM-DD" → crossed out
      showMonths: window.matchMedia("(min-width: 900px)").matches ? 2 : 1,
      onChange(selected) {
        if (selected.length === 2) {
          const [a, b] = selected;
          const nights = Math.round((b - a) / 86400000);
          if (nights < 1) return;

          // validate no blocked date in the middle of the range
          for (let t = a.getTime() + 86400000; t < b.getTime(); t += 86400000) {
            if (blocked.includes(fmt(new Date(t)))) {
              alert("A booked night sits inside your selection — please pick a shorter window.");
              fp.clear();
              summary.hidden = true;
              nextBtn.disabled = true;
              return;
            }
          }

          document.getElementById("bf-checkin").value  = fmt(a);
          document.getElementById("bf-checkout").value = fmt(b);

          summary.innerHTML = `
            <div class="booking-summary-row"><span>Check-in</span><strong>${fmt(a)}</strong></div>
            <div class="booking-summary-row"><span>Check-out</span><strong>${fmt(b)}</strong></div>
            <div class="booking-summary-row"><span>Nights</span><strong>${nights}</strong></div>
            ${price ? `
              <div class="booking-summary-row booking-summary-total">
                <span>Estimated total</span>
                <strong>${fmtVnd(price * nights)}</strong>
              </div>
              <div class="booking-summary-note">Placeholder rate · Simmo will confirm final price.</div>
            ` : ""}
          `;
          summary.hidden = false;
          nextBtn.disabled = false;
        } else {
          summary.hidden = true;
          nextBtn.disabled = true;
        }
      },
    });
  }

  function fmtVnd(n) {
    return new Intl.NumberFormat("vi-VN").format(n) + " VND";
  }

  const summary = document.getElementById("booking-summary");
  const nextBtn = document.getElementById("booking-next");
  const backBtn = document.getElementById("booking-back");
  const step1   = document.querySelector('[data-step="1"]');
  const step2   = document.querySelector('[data-step="2"]');

  nextBtn.addEventListener("click", () => {
    step1.hidden = true;
    step2.hidden = false;
    step2.scrollIntoView({ behavior: "smooth", block: "start" });
  });
  backBtn.addEventListener("click", () => {
    step2.hidden = true;
    step1.hidden = false;
    step1.scrollIntoView({ behavior: "smooth", block: "start" });
  });
})();
