<?php

test('home opens the login screen on a new install', function () {
    $this->get('/')->assertOk()->assertSee('Call the play.')->assertSee(route('register'));
});
