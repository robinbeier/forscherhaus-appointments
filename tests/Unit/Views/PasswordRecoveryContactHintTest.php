<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class PasswordRecoveryContactHintTest extends TestCase
{
    public function testLoginPageShowsContactHintInsteadOfNavigatingToRecoveryFlow(): void
    {
        $source = file_get_contents(FCPATH . 'assets/js/pages/login.js');

        $this->assertIsString($source);
        $this->assertStringContainsString('onForgotPasswordClick', $source);
        $this->assertStringContainsString('event.preventDefault();', $source);
        $this->assertStringContainsString("lang('password_recovery_contact_robin')", $source);
        $this->assertStringContainsString('alert-info', $source);
    }

    public function testRecoveryPageNoLongerRendersSelfServicePasswordForm(): void
    {
        $source = file_get_contents(VIEWPATH . 'pages/recovery.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("lang('password_recovery_contact_robin')", $source);
        $this->assertStringNotContainsString('id="username"', $source);
        $this->assertStringNotContainsString('id="email"', $source);
        $this->assertStringNotContainsString('id="get-new-password"', $source);
        $this->assertStringNotContainsString('assets/js/pages/recovery.js', $source);
    }
}
