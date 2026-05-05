<?php
/*
 * KnK Inn — site configuration (TEMPLATE)
 *
 * Copy this file to config.php and fill in the real values.
 * config.php is gitignored and must NEVER be committed.
 *
 * The Gmail app password below is NOT your Gmail login password.
 * Generate one at https://myaccount.google.com/apppasswords
 * (2-Step Verification must be enabled on the Gmail account first).
 */

return [
    // Where enquiries land (Simmo's inbox)
    "to_email"   => "knkinnsaigon@gmail.com",

    // Shared password for bookings.php, orders.php, photos.php.
    // Pick something strong — this protects the staff dashboards.
    "admin_password" => "change-me-before-going-live",

    // SMTP credentials — Gmail
    "smtp" => [
        "host"      => "smtp.gmail.com",
        "port"      => 465,
        "secure"    => "ssl",                    // ssl for 465, tls for 587
        "username"  => "knkinnsaigon@gmail.com", // the Gmail account sending mail
        "password"  => "xxxx xxxx xxxx xxxx",    // 16-char app password, spaces ok
        "from_name" => "KnK Inn Website",
    ],

    // Anti-spam
    "min_seconds" => 3,

    // Google Calendar feed — random secret that guards bookings.ics.php
    // Generate with:  php -r "echo bin2hex(random_bytes(16));"
    // The admin page will display the full subscription URL once this is set.
    "ics_key" => "",

    // YouTube Data API v3 key for the Jukebox (Phase 3).
    // Get one at https://console.cloud.google.com — create a project,
    // enable "YouTube Data API v3", create an API key, paste it here.
    // Leave empty to disable the jukebox.
    "youtube_api_key" => "",

    // Beds24 v2 channel-manager push (outbound sync).
    // When a direct booking gets confirmed on knkinn.com, we push it
    // to Beds24 so it propagates as a blocked night to Booking.com
    // and Airbnb. Without this, two guests can book the same physical
    // room from two different OTAs at the same time.
    //
    // Setup:
    //   1. In Beds24 admin go to Settings → Account → API → Long-life Tokens
    //   2. Generate a new token. Copy the refreshToken (long string).
    //   3. Paste below.
    //   4. Verify property_id matches Beds24's "propid" (325504 for KnK Inn).
    //   5. The room_map maps internal room slugs → Beds24 numeric roomIds.
    //      Find roomIds in Beds24 admin → Properties → Rooms.
    //
    // Leave refresh_token empty to cleanly disable — confirms still
    // work locally, they just won't propagate to OTAs.
    "beds24" => [
        "refresh_token" => "",         // long string from Beds24 admin
        "property_id"   => 325504,     // KnK Inn & Sports Pub w/Rooftop Garden
        "room_map"      => [
            // slug                 => beds24 room id
            "vip"               => 675740, // Premium (3 units, King + bath)
            "standard-balcony"  => 676694, // Superior (3 units, Queen + balcony)
            "standard-nowindow" => 676693, // Standard (2 units, mixed bed)
            "basic"             => 675750, // Basic (1 unit, Room 9, Queen + skylight)
        ],
    ],
];
