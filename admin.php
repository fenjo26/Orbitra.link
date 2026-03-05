<?php
// Запрет кэширования
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// admin.php - входная точка для React приложения (SPA)
$html = file_get_contents(__DIR__ . '/frontend/dist/index.html');
echo $html;