<?php

return [

    // Demo only: fake payment delay (ms) - widens the race-condition window.
    'payment_delay_ms' => (int) env('PAYMENT_DELAY_MS', 300),

    // How long a buyer waits in line for the lock before getting 503.
    'lock_wait_seconds' => (int) env('LOCK_WAIT_SECONDS', 5),

];
