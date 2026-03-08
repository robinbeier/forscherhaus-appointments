<?php

namespace Tests\Unit\Libraries;

use Email_messages;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class EmailMessagesTest extends TestCase
{
    private const CONFIG_KEYS = [
        'protocol',
        'mailtype',
        'smtp_debug',
        'smtp_auth',
        'smtp_host',
        'smtp_user',
        'smtp_pass',
        'smtp_crypto',
        'smtp_port',
        'from_name',
        'from_address',
        'reply_to',
    ];

    private Email_messages $library;

    private ReflectionMethod $getPhpMailerMethod;

    private array $originalConfig = [];

    private string $originalCompanyEmail;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::requireFile(APPPATH . 'libraries/Email_messages.php');

        get_instance()->load->helper('config');
        get_instance()->load->helper('setting');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::CONFIG_KEYS as $key) {
            $this->originalConfig[$key] = config($key);
        }

        $this->originalCompanyEmail = (string) setting('company_email');

        $reflection = new ReflectionClass(Email_messages::class);

        $this->library = $reflection->newInstanceWithoutConstructor();
        $this->getPhpMailerMethod = new ReflectionMethod(Email_messages::class, 'get_php_mailer');
        $this->getPhpMailerMethod->setAccessible(true);

        config([
            'protocol' => 'mail',
            'mailtype' => 'html',
            'smtp_debug' => false,
            'smtp_auth' => false,
            'smtp_host' => null,
            'smtp_user' => null,
            'smtp_pass' => null,
            'smtp_crypto' => null,
            'smtp_port' => null,
            'from_name' => 'Forscherhaus',
            'from_address' => 'noreply@example.org',
            'reply_to' => 'reply@example.org',
        ]);
    }

    protected function tearDown(): void
    {
        config($this->originalConfig);
        setting(['company_email' => $this->originalCompanyEmail]);

        parent::tearDown();
    }

    public function test_get_php_mailer_keeps_phpmailer_defaults_when_optional_smtp_values_are_missing(): void
    {
        config([
            'protocol' => 'smtp',
            'smtp_host' => '   ',
            'smtp_crypto' => '   ',
            'smtp_port' => '',
        ]);

        $php_mailer = $this->createPhpMailer();

        $this->assertSame('smtp', $php_mailer->Mailer);
        $this->assertSame(SMTP::DEBUG_OFF, $php_mailer->SMTPDebug);
        $this->assertSame('localhost', $php_mailer->Host);
        $this->assertFalse($php_mailer->SMTPAuth);
        $this->assertSame('', $php_mailer->Username);
        $this->assertSame('', $php_mailer->Password);
        $this->assertSame('', $php_mailer->SMTPSecure);
        $this->assertSame(25, $php_mailer->Port);
    }

    public function test_get_php_mailer_applies_configured_smtp_values_and_html_body(): void
    {
        config([
            'protocol' => 'smtp',
            'smtp_debug' => true,
            'smtp_auth' => true,
            'smtp_host' => 'smtp.example.org',
            'smtp_user' => 'mailer-user',
            'smtp_pass' => 'mailer-pass',
            'smtp_crypto' => PHPMailer::ENCRYPTION_STARTTLS,
            'smtp_port' => '587',
        ]);

        $php_mailer = $this->createPhpMailer(
            'student@example.org',
            'Terminbestaetigung',
            '<p>Hello <strong>world</strong></p>',
        );

        $this->assertSame('smtp', $php_mailer->Mailer);
        $this->assertSame(SMTP::DEBUG_SERVER, $php_mailer->SMTPDebug);
        $this->assertSame('smtp.example.org', $php_mailer->Host);
        $this->assertTrue($php_mailer->SMTPAuth);
        $this->assertSame('mailer-user', $php_mailer->Username);
        $this->assertSame('mailer-pass', $php_mailer->Password);
        $this->assertSame(PHPMailer::ENCRYPTION_STARTTLS, $php_mailer->SMTPSecure);
        $this->assertSame(587, $php_mailer->Port);
        $this->assertSame('Terminbestaetigung', $php_mailer->Subject);
        $this->assertSame('<p>Hello <strong>world</strong></p>', $php_mailer->Body);
        $this->assertSame('Hello world', $php_mailer->AltBody);
        $this->assertSame([['student@example.org', '']], $php_mailer->getToAddresses());
    }

    public function test_get_php_mailer_skips_empty_reply_to_addresses(): void
    {
        config([
            'reply_to' => '   ',
        ]);
        setting([
            'company_email' => '   ',
        ]);

        $php_mailer = $this->createPhpMailer();

        $this->assertSame([], $php_mailer->getReplyToAddresses());
    }

    private function createPhpMailer(
        ?string $recipient_email = 'recipient@example.org',
        ?string $subject = 'Subject',
        ?string $html = '<p>Hello</p>',
    ): PHPMailer {
        return $this->getPhpMailerMethod->invoke($this->library, $recipient_email, $subject, $html);
    }
}
