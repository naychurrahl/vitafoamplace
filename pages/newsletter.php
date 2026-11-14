<?php
$back = preg_match('#^/[a-z0-9/_-]*$#i', $_POST['back'] ?? '') ? $_POST['back'] : '/';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try { flash(Store::api()->post('/newsletter', ['email' => $_POST['email'] ?? ''])['message']); }
    catch (ApiError $e) { flash($e->getMessage(), 'err'); }
}
redirect($back);
