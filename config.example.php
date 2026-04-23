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
];
