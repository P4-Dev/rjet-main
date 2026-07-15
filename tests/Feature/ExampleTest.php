<?php

test('the application redirects to the admin login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('filament.admin.auth.login'));
});
