<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

// Every Pudo locker for the enquiry-list map. Served from the 12-hour disk cache
// in Pudo::lockers(), so the API is only called a couple of times a day.
if (!Pudo::enabled()) {
    api_fail('Pudo lockers are not available right now.', 404);
}
try {
    $lockers = Pudo::lockers();
} catch (Throwable $e) {
    error_log('Pudo lockers failed: ' . $e->getMessage());
    api_fail('Could not load the locker map. Please choose courier delivery or try again shortly.', 502);
}
header('Cache-Control: public, max-age=3600');
api_ok(['lockers' => $lockers]);
