<?php
// "Track your order" now means signing in with a code (or password): an order number + phone alone
// no longer shows anything. Old links and the footer still land here.
redirect('login?next=account', 302);
