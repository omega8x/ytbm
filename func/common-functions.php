<?php
/**
 * COMMON FUNCTIONS
 *
 * Các hàm dùng chung trong toàn bộ website.
 */

// Escape dữ liệu trước khi đưa vào HTML
if (!function_exists('h')) {

    function h($value)
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }

}
