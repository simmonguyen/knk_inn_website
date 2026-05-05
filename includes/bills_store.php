<?php
/*
 * KnK Inn — combined bills (booking + drinks).
 *
 * A "linked bill" is just a view — it reads the existing booking from
 * bookings.json and pulls every drink order placed by the same email
 * between check-in and check-out. Nothing is written to a new store.
 *
 * Linking rule (V2 Phase 4 — chosen by Ben):
 *   auto-match by guest email AND stay dates
 *
 *   - Order's email (lowercased, trimmed) === booking's guest.email
 *   - Order's created_at falls in [checkin 00:00, checkout + 1 day 00:00)
 *     (gives the guest the morning of checkout to order breakfast drinks)
 *   - Order status is NOT 'cancelled'
 *
 * Totals:
 *   room_total    = nights * price_vnd_per_night
 *   drinks_total  = sum of linked orders' total_vnd (already VAT-inclusive)
 *   grand_total   = room_total + drinks_total
 *
 * VAT is only tracked on drink orders — room nights are priced gross as
 * a single rate, consistent with how Simmo already quotes them.
 */

declare(strict_types=1);

require_once __DIR__ . "/bookings_store.php";
require_once __DIR__ . "/orders_store.php";

if (!defined("KNK_BILL_CHECKOUT_GRACE_HRS")) {
    define("KNK_BILL_CHECKOUT_GRACE_HRS", 24);  // hours past checkout date we still accept drink orders
}

/* =========================================================
   ORDER LOOKUP — which orders go on this booking's bill?
   ========================================================= */

/**
 * Return every drink order that should appear on this booking's bill.
 *
 * $booking is a hold/booking row as returned by bookings_store — i.e.
 *   ["id" => "b_...", "guest" => ["email" => "...", ...],
 *    "checkin" => "YYYY-MM-DD", "checkout" => "YYYY-MM-DD", ...]
 *
 * Returns array of order records, newest first. Empty array if the
 * booking has no email, bad dates, or nothing matched.
 */
function knk_booking_linked_orders(array $booking): array {
    $email = strtolower(trim((string)($booking["guest"]["email"] ?? "")));
    if ($email === "") return [];

    $checkin  = (string)($booking["checkin"]  ?? "");
    $checkout = (string)($booking["checkout"] ?? "");
    $ci_ts = strtotime($checkin  . " 00:00:00");
    $co_ts = strtotime($checkout . " 00:00:00");
    if (!$ci_ts || !$co_ts || $co_ts <= $ci_ts) return [];

    // Grace window: accept orders up to the morning of checkout + N hours.
    $window_end = $co_ts + (KNK_BILL_CHECKOUT_GRACE_HRS * 3600);

    $candidates = orders_for_email($email);
    $out = [];
    foreach ($candidates as $o) {
        $status = (string)($o["status"] ?? "pending");
        if ($status === "cancelled") continue;
        $ts = (int)($o["created_at"] ?? 0);
        if ($ts < $ci_ts || $ts >= $window_end) continue;
        $out[] = $o;
    }
    // orders_for_email already returns newest-first; keep that.
    return $out;
}

/* =========================================================
   BILL BUILDER
   ========================================================= */

/**
 * Build a full combined bill for one booking. Returns an assoc array
 * ready for the bill.php template — all the numbers Simmo needs to
 * read out at checkout.
 */
function knk_booking_bill(array $booking): array {
    $rooms = [
        "basic"             => "Basic",
        "standard-nowindow" => "Standard",
        "standard-balcony"  => "Superior",
        "vip"               => "Premium",
    ];

    $nights = (int)($booking["nights"] ?? 0);
    $ppn    = (int)($booking["price_vnd_per_night"] ?? 0);
    $room_total = $nights * $ppn;

    $orders = knk_booking_linked_orders($booking);
    $drinks_subtotal = 0;  // pre-VAT line total
    $drinks_vat      = 0;
    $drinks_total    = 0;  // what Simmo actually collects for drinks
    foreach ($orders as $o) {
        $drinks_subtotal += (int)($o["subtotal_vnd"] ?? 0);
        $drinks_vat      += (int)($o["vat_vnd"] ?? 0);
        $drinks_total    += (int)($o["total_vnd"] ?? 0);
    }

    return [
        "booking"         => $booking,
        "room_key"        => (string)($booking["room"] ?? ""),
        "room_label"      => $rooms[$booking["room"] ?? ""] ?? (string)($booking["room"] ?? ""),
        "nights"          => $nights,
        "price_per_night" => $ppn,
        "room_total"      => $room_total,
        "orders"          => $orders,
        "drinks_subtotal" => $drinks_subtotal,
        "drinks_vat"      => $drinks_vat,
        "drinks_total"    => $drinks_total,
        "grand_total"     => $room_total + $drinks_total,
    ];
}

/* =========================================================
   DISPLAY HELPER (shared with bookings.php / bill.php)
   ========================================================= */

/** Compact total used on the "View bill" button. */
function knk_booking_bill_quick_total(array $booking): int {
    $bill = knk_booking_bill($booking);
    return (int)$bill["grand_total"];
}
