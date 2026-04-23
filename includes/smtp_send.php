<?php
/*
 * KnK Inn — minimal SMTP client
 *
 * Sends a single plain-text email over an authenticated SMTP connection.
 * Designed for Gmail on port 465 (SSL) but works with any SMTP server that
 * speaks AUTH LOGIN.  No dependencies, nothing to vendor.
 *
 * Usage:
 *   require_once __DIR__ . "/smtp_send.php";
 *   $ok = smtp_send([
 *       "host"      => "smtp.gmail.com",
 *       "port"      => 465,
 *       "secure"    => "ssl",          // ssl (465) or tls (587)
 *       "username"  => "...@gmail.com",
 *       "password"  => "app-password",
 *       "from_email"=> "...@gmail.com",
 *       "from_name" => "KnK Inn Website",
 *       "to"        => "knkinnsaigon@gmail.com",
 *       "reply_to_email" => "guest@example.com",
 *       "reply_to_name"  => "Guest Name",
 *       "subject"   => "...",
 *       "body"      => "plain text body",
 *   ], $errorOut);
 *
 * On failure the function returns false and populates $errorOut with the
 * last SMTP response for server-side logging.
 */

function smtp_send(array $cfg, ?string &$errorOut = null): bool {
    $errorOut = null;

    $host      = $cfg["host"]     ?? "";
    $port      = (int)($cfg["port"] ?? 0);
    $secure    = strtolower($cfg["secure"] ?? "ssl");
    $user      = $cfg["username"] ?? "";
    $pass      = $cfg["password"] ?? "";
    $fromAddr  = $cfg["from_email"] ?? $user;
    $fromName  = $cfg["from_name"]  ?? "";
    $to        = $cfg["to"]       ?? "";
    $replyAddr = $cfg["reply_to_email"] ?? "";
    $replyName = $cfg["reply_to_name"]  ?? "";
    $subject   = $cfg["subject"]  ?? "";
    $body      = $cfg["body"]     ?? "";

    if ($host === "" || $port === 0 || $user === "" || $pass === "" || $to === "") {
        $errorOut = "smtp_send: missing required config";
        return false;
    }

    $transport = ($secure === "ssl") ? "ssl://" : "tcp://";
    $ctx = stream_context_create([
        "ssl" => [
            "verify_peer"       => true,
            "verify_peer_name"  => true,
            "allow_self_signed" => false,
        ],
    ]);
    $errno = 0; $errstr = "";
    $fp = @stream_socket_client($transport . $host . ":" . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $errorOut = "connect failed ({$errno}): {$errstr}";
        return false;
    }
    stream_set_timeout($fp, 30);

    $read = function () use ($fp) {
        $data = "";
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            if ($line === false) break;
            $data .= $line;
            if (isset($line[3]) && $line[3] === " ") break;   // final line (no continuation dash)
        }
        return $data;
    };
    $write = function ($cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = function ($codes, $resp) use (&$errorOut, $fp) {
        $code = (int)substr($resp, 0, 3);
        $codes = (array)$codes;
        if (!in_array($code, $codes, true)) {
            $errorOut = "unexpected SMTP response: " . trim($resp);
            @fclose($fp);
            return false;
        }
        return true;
    };

    // Banner
    if (!$expect(220, $read())) return false;

    // EHLO
    $write("EHLO " . ($_SERVER["SERVER_NAME"] ?? "knkinn.com"));
    $resp = $read();
    if (!$expect(250, $resp)) return false;

    // STARTTLS for port-587 servers (not Gmail on 465)
    if ($secure === "tls") {
        $write("STARTTLS");
        if (!$expect(220, $read())) return false;
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $errorOut = "STARTTLS crypto negotiation failed";
            @fclose($fp);
            return false;
        }
        $write("EHLO " . ($_SERVER["SERVER_NAME"] ?? "knkinn.com"));
        if (!$expect(250, $read())) return false;
    }

    // AUTH LOGIN
    $write("AUTH LOGIN");
    if (!$expect(334, $read())) return false;
    $write(base64_encode($user));
    if (!$expect(334, $read())) return false;
    $write(base64_encode($pass));
    if (!$expect(235, $read())) return false;

    // Envelope
    $write("MAIL FROM:<" . $fromAddr . ">");
    if (!$expect(250, $read())) return false;
    $write("RCPT TO:<" . $to . ">");
    if (!$expect([250, 251], $read())) return false;
    $write("DATA");
    if (!$expect(354, $read())) return false;

    // Build headers + body (strip any CR/LF injection attempts from user-controlled fields)
    $safe = function ($s) {
        return trim(str_replace(["\r", "\n"], " ", (string)$s));
    };
    $fromHeader = $fromName !== ""
        ? smtp_encode_word($safe($fromName)) . " <" . $safe($fromAddr) . ">"
        : $safe($fromAddr);

    $headers  = "Date: " . date("r") . "\r\n";
    $headers .= "From: " . $fromHeader . "\r\n";
    $headers .= "To: " . $safe($to) . "\r\n";
    if ($replyAddr !== "") {
        $headers .= "Reply-To: " .
            ($replyName !== ""
                ? smtp_encode_word($safe($replyName)) . " <" . $safe($replyAddr) . ">"
                : $safe($replyAddr)) . "\r\n";
    }
    $headers .= "Subject: " . smtp_encode_word($safe($subject)) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= "X-Mailer: KnKInn-Web\r\n";

    // Normalize body line endings and dot-stuff leading "."
    $body = preg_replace("/(?<!\r)\n/", "\r\n", (string)$body);
    $body = preg_replace("/^\./m", "..", $body);

    fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
    if (!$expect(250, $read())) return false;

    $write("QUIT");
    @fclose($fp);
    return true;
}

/** RFC 2047 encoded-word for non-ASCII header values (subject, display names). */
function smtp_encode_word(string $s): string {
    if (preg_match('/[^\x20-\x7E]/', $s)) {
        return "=?UTF-8?B?" . base64_encode($s) . "?=";
    }
    return $s;
}
