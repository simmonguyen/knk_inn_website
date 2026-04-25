<?php
/*
 * KnK Inn — staff-area English dictionary.
 *
 * Keep this in sync with vi.php. New keys go in both files.
 * Page-body strings (forms, table headers, flash messages)
 * will be added here as each admin page gets translated.
 *
 * Conventions:
 *   nav.*       — top admin navigation
 *   role.*      — role labels shown in the nav and 403 page
 *   lang.*      — language picker UI itself
 *   login.*     — the login screen
 *   common.*    — buttons / words used across pages
 */

return [
    /* Navigation */
    "nav.bookings"     => "Bookings",
    "nav.orders"       => "Orders",
    "nav.guests"       => "Guests",
    "nav.sales"        => "Sales",
    "nav.menu"         => "Menu",
    "nav.market"       => "Market",
    "nav.jukebox"      => "Jukebox",
    "nav.darts"        => "Darts",
    "nav.photos"       => "Photos",
    "nav.settings"     => "Settings",
    "nav.users"        => "Users",
    "nav.logout"       => "Log out",
    "nav.brand_staff"  => "Staff",

    /* Roles */
    "role.super_admin" => "Super Admin",
    "role.owner"       => "Owner",
    "role.reception"   => "Hotel Reception",
    "role.bartender"   => "Bartender / Hostess",

    /* Language picker */
    "lang.picker_label"  => "Language",
    "lang.tooltip"       => "Switch language",
    "lang.english"       => "English",
    "lang.vietnamese"    => "Tiếng Việt",
    "lang.user_default"  => "Default language",
    "lang.user_help"     => "What this user sees when they log in. Anyone can flip the EN/VI switch in the nav for the current session.",

    /* 403 page */
    "403.title"     => "403 — Not allowed",
    "403.body"      => "You're signed in as <strong>{role}</strong>, which doesn't have access to this page.",
    "403.back"      => "Back to your dashboard",
    "403.logout"    => "Log out",
];
