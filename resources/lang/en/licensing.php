<?php

declare(strict_types=1);

return [
    'alerts' => [
        'source' => 'Licence',
        'development' => 'Development copy: every capability is open without a licence.',
        'stale' => 'Could not check the licence. The site runs on the last snapshot from :date.',
        'clock_set_back' => 'Could not check the licence: the server clock is behind. The site runs on the last snapshot from :date.',
        'expired' => 'The licence has ended. Installed code keeps working; paid modules update only to releases up to the end date.',
        'revoked' => 'The licence was revoked. Paid capabilities are closed; data is kept.',
        'missing' => 'The licence has not been checked yet: paid capabilities are closed.',
        'invalid' => 'The licence snapshot was not accepted (:reason). Paid capabilities are closed.',
    ],
];
