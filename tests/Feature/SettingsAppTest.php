<?php

test('guests are redirected to the login page', function () {
    $this->get(route('settings.index'))->assertRedirect(route('login'));
});
