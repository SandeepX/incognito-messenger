<?php

it('redirects guests to login from the home page', function () {
    $this->get('/')->assertRedirect('/login');
});
