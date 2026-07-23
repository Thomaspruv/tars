<?php

test('guests are redirected to the login page', function () {
    $response = $this->get(route('today'));

    $response->assertRedirect(route('login'));
});
