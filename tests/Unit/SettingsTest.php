<?php

declare(strict_types=1);

use JustinHoltWeb\VelvetRope\Settings;

it('has sensible defaults', function () {
    $settings = new Settings;

    expect($settings->stripeKey)->toBe(null);
});
