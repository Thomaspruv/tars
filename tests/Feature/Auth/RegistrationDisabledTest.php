<?php

test('registration screen is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('new users cannot register', function () {
    $response = $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
});

test('login page does not offer a sign up link', function () {
    $this->get('/login')->assertDontSee('Sign up');
});
