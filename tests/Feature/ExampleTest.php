<?php

it('answers the health route', function () {
    $this->get('/up')->assertOk();
});
