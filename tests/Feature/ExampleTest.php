<?php

test('home opens the login screen on a new install', function () {
    $this->get('/')->assertRedirect(route('login'));
});
