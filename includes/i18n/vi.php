<?php
/*
 * KnK Inn — staff-area Vietnamese dictionary.
 *
 * Translations to be reviewed by Simmo. Anything missing from this
 * file falls back to en.php, so it's safe to ship partial coverage.
 *
 * Style guide:
 *   - Plain hospitality Vietnamese, no English jargon.
 *   - Avoid abbreviations (Simmo is ESL — full words land better).
 *   - Use "Đăng xuất" for log out, "Đăng nhập" for log in (standard).
 */

return [
    /* Navigation */
    "nav.bookings"     => "Đặt phòng",
    "nav.rates"        => "Giá phòng",
    "nav.orders"       => "Đơn hàng",
    "nav.guests"       => "Khách",
    "nav.sales"        => "Doanh thu",
    "nav.menu"         => "Thực đơn",
    "nav.market"       => "Bảng giá",
    "nav.jukebox"      => "Máy nhạc",
    "nav.darts"        => "Phi tiêu",
    "nav.photos"       => "Hình ảnh",
    "nav.settings"     => "Cài đặt",
    "nav.users"        => "Người dùng",
    "nav.logout"       => "Đăng xuất",
    "nav.brand_staff"  => "Nhân viên",

    /* Roles */
    "role.super_admin" => "Quản trị viên",
    "role.owner"       => "Chủ quán",
    "role.reception"   => "Lễ tân",
    "role.bartender"   => "Pha chế / Phục vụ",

    /* Language picker */
    "lang.picker_label"  => "Ngôn ngữ",
    "lang.tooltip"       => "Đổi ngôn ngữ",
    "lang.english"       => "English",
    "lang.vietnamese"    => "Tiếng Việt",
    "lang.user_default"  => "Ngôn ngữ mặc định",
    "lang.user_help"     => "Ngôn ngữ mà người dùng này nhìn thấy khi đăng nhập. Bất kỳ ai cũng có thể chuyển EN/VI trên thanh điều hướng cho phiên hiện tại.",

    /* 403 page */
    "403.title"     => "403 — Không được phép",
    "403.body"      => "Bạn đã đăng nhập với vai trò <strong>{role}</strong>, vai trò này không có quyền truy cập trang này.",
    "403.back"      => "Trở về bảng điều khiển",
    "403.logout"    => "Đăng xuất",
];
