<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    Store::api()->request('POST', '/auth/logout');
    Store::setCookie('auth', null);
}
redirect('/');
