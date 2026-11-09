<?php
require_once __DIR__ . '/config/database.php';

echo '<h3>Kiểm tra kết nối Database</h3>';

if ($conn instanceof mysqli && !$conn->connect_errno) {
    echo '<p style="color:green">✓ Kết nối MySQL/MariaDB thành công!</p>';

    echo 'Database: ' . htmlspecialchars($conn->query('SELECT DATABASE()')->fetch_row()[0]);
    echo '<br>MySQL Server: ' . htmlspecialchars($conn->server_info);

} else {
    echo '<p style="color:red">✗ Kết nối thất bại!</p>';
}
?>