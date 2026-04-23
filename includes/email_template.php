<?php
/*
 * KnK Inn — email branding helpers
 *
 * knk_email_html($title, $preheader, $body_html, $footer_note = null)
 *   Wraps body_html in a branded, table-based, Outlook-safe HTML email.
 *
 * knk_email_button($label, $url, $style = "primary")
 *   Returns HTML for a gold primary button or outlined secondary button.
 *
 * knk_email_row($label, $value)
 *   Returns one <tr> for a key/value booking detail.
 *
 * Colours mirror the website (styles.css):
 *   --brown-deep #180c03, --brown-mid #3d1f0d, --gold #c9aa71, --cream #f4ede0
 */

function knk_email_html(string $title, string $preheader, string $body_html, ?string $footer_note = null): string {
    $BROWN_DEEP = "#180c03";
    $BROWN_MID  = "#3d1f0d";
    $GOLD       = "#c9aa71";
    $GOLD_DARK  = "#9c7f4a";
    $CREAM      = "#f4ede0";
    $CREAM_CARD = "#fdf8ef";
    $BORDER     = "#e7dcc2";
    $MUTED      = "#6e5d40";

    $t  = htmlspecialchars($title, ENT_QUOTES, "UTF-8");
    $pre = htmlspecialchars($preheader, ENT_QUOTES, "UTF-8");

    $footer_html = $footer_note !== null ? "<p style=\"margin:0 0 8px 0;color:{$MUTED};font-size:12px;line-height:1.6;\">{$footer_note}</p>" : "";

    // Preheader trick — hidden text that shows as the preview in inbox lists.
    // Padded with zero-width joiners so Gmail doesn't leak subject/body noise after it.
    $spacer = str_repeat("&#847;&zwnj;&nbsp;", 40);

    return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<meta name="color-scheme" content="light" />
<meta name="supported-color-schemes" content="light" />
<title>{$t}</title>
</head>
<body style="margin:0;padding:0;background:{$CREAM};font-family:'Helvetica Neue',Arial,sans-serif;color:{$BROWN_DEEP};">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;color:{$CREAM};">{$pre}{$spacer}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$CREAM};">
  <tr>
    <td align="center" style="padding:28px 12px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:{$CREAM_CARD};border:1px solid {$BORDER};border-radius:8px;overflow:hidden;">
        <!-- Brand bar -->
        <tr>
          <td align="center" style="background:{$BROWN_DEEP};padding:22px 24px;">
            <div style="font-family:'Archivo Black','Helvetica Neue',Arial Black,Arial,sans-serif;font-weight:900;color:{$GOLD};font-size:22px;letter-spacing:0.08em;text-transform:uppercase;">KnK Inn</div>
            <div style="color:{$CREAM};font-size:11px;letter-spacing:0.22em;text-transform:uppercase;margin-top:4px;">Saigon · De Tham</div>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:28px 32px 12px 32px;color:{$BROWN_MID};font-size:15px;line-height:1.55;">
            {$body_html}
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:20px 32px 28px 32px;border-top:1px solid {$BORDER};">
            {$footer_html}
            <p style="margin:0;color:{$MUTED};font-size:11px;line-height:1.6;">
              KnK Inn · 96 Đề Thám, Cầu Ông Lãnh, HCM 70000 · <a href="https://knkinn.com" style="color:{$GOLD_DARK};text-decoration:none;">knkinn.com</a>
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

function knk_email_button(string $label, string $url, string $style = "primary"): string {
    $GOLD      = "#c9aa71";
    $GOLD_DARK = "#9c7f4a";
    $BROWN     = "#180c03";

    $url_esc = htmlspecialchars($url, ENT_QUOTES, "UTF-8");
    $label_esc = htmlspecialchars($label, ENT_QUOTES, "UTF-8");

    if ($style === "secondary") {
        $bg = "transparent"; $fg = $BROWN; $border = $BROWN;
    } else {
        $bg = $GOLD; $fg = $BROWN; $border = $GOLD_DARK;
    }

    // Table-wrapped button — the most reliable cross-client button pattern.
    return <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0;">
  <tr>
    <td align="center" bgcolor="{$bg}" style="border:1px solid {$border};border-radius:4px;">
      <a href="{$url_esc}" target="_blank" style="display:inline-block;padding:12px 28px;font-family:'Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:{$fg};text-decoration:none;">{$label_esc}</a>
    </td>
  </tr>
</table>
HTML;
}

function knk_email_row(string $label, string $value): string {
    $MUTED = "#6e5d40";
    $BROWN = "#180c03";
    $l = htmlspecialchars($label, ENT_QUOTES, "UTF-8");
    $v = htmlspecialchars($value, ENT_QUOTES, "UTF-8");
    return "<tr><td style=\"padding:6px 12px 6px 0;color:{$MUTED};font-size:12px;letter-spacing:0.14em;text-transform:uppercase;vertical-align:top;white-space:nowrap;\">{$l}</td><td style=\"padding:6px 0;color:{$BROWN};font-size:15px;font-weight:600;\">{$v}</td></tr>";
}

/** Render a booking/enquiry details block as a styled table. Pass assoc array of label=>value. */
function knk_email_details_table(array $rows): string {
    $CREAM_CARD = "#fdf8ef";
    $BORDER = "#e7dcc2";
    $html = "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\" style=\"margin:16px 0;background:{$CREAM_CARD};border:1px solid {$BORDER};border-radius:6px;\"><tr><td style=\"padding:16px 20px;\"><table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\">";
    foreach ($rows as $label => $value) {
        if ($value === "" || $value === null) continue;
        $html .= knk_email_row((string)$label, (string)$value);
    }
    $html .= "</table></td></tr></table>";
    return $html;
}

/** Simple horizontal divider rule inside an email body. */
function knk_email_divider(): string {
    return "<hr style=\"border:0;border-top:1px solid #e7dcc2;margin:20px 0;\" />";
}
