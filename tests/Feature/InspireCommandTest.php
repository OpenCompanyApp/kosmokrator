<?php

it('launches the agent', function () {
    $this->artisan('agent')->assertExitCode(0);
});
