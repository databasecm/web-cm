<?php

use function Pest\Laravel\get;

it('serves the Filament login page at /sistem/login', function () {
    get('/sistem/login')->assertOk();
});

it('redirects guests from the /sistem panel to the login page', function () {
    get('/sistem')->assertRedirect('/sistem/login');
});
