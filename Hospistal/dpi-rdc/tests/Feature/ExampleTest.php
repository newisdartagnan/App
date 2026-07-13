<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_login_for_guests(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
