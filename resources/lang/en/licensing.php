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
    // «Попробовать бесплатно» (larena:licensing:trial and the admin): every reason a trial ends with.
    'trial' => [
        'started' => 'The free trial has started. It lasts until :date.',
        'reasons' => [
            'trial_started' => 'The free trial has started.',
            'development_address' => 'This is a development copy: every function is already open here, no trial is needed.',
            'capability_free' => 'This function is free: it needs no trial.',
            'capability_unknown' => 'The registration server does not know this function. Update the site and try again.',
            'trial_not_eligible' => 'This function has no free trial: it comes with a licence.',
            'capability_licensed' => 'The licence already includes this function: no trial is needed.',
            'site_key_required' => 'The site is not registered yet: register it to get a trial.',
            'site_key_unknown' => 'The registration server does not know this site: register it again.',
            'trial_used' => 'This site has already had a free trial of this function.',
            'registration_unavailable' => 'The registration server is not answering now. Try again later.',
            'license_signature_invalid' => 'The registration server could not sign the licence now. Try again later.',
            'licensing_source_missing' => 'No update server is set for this site, so there is nowhere to ask for a trial.',
            'trial_request_failed' => 'Could not reach the update server. Try again later.',
            'trial_answer_refused' => 'The answer did not pass the signature check and was not kept. Nothing changed.',
            'trial_not_granted' => 'The server answered without this function: the trial did not start.',
        ],
    ],
];
