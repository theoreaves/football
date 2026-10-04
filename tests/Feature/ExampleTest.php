<?php

test('home opens the saved-game picker on a new install', function () {
    $this->get('/')->assertRedirect(route('worlds.index'));
});
