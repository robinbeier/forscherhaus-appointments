<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Regression coverage for public booking HTML settings rendered into HTML contexts. */
final class BookingHtmlEscapingHttpTest extends TestCase
{
    private const SETTING_NAMES = [
        'company_name',
        'company_logo',
        'label_custom_field_1',
        'display_custom_field_1',
        'require_custom_field_1',
        'disable_booking',
    ];

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var array<string, array<string, mixed>> */
    private array $settingSnapshots = [];
    private bool $settingsSnapshotTaken = false;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->snapshotSettings();
            $this->fixture->create();
            $this->configureSyntheticSettings();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            try {
                $this->server?->close();
            } finally {
                try {
                    $this->restoreSettings();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            try {
                $this->restoreSettings();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testPublicBookingNormalValuesRemainVisible(): void
    {
        $client = $this->server?->client();
        self::assertNotNull($client);

        $this->setSetting('company_name', 'ROB694 normal company');
        $this->setSetting('company_logo', '');
        $this->setSetting('label_custom_field_1', 'ROB694 normal field');
        $this->setSetting('display_custom_field_1', '1');
        $this->setSetting('require_custom_field_1', '0');
        $this->setSetting('disable_booking', '0');

        $page = $client->get('booking');
        self::assertSame(200, $page->statusCode);
        self::assertStringContainsString(
            '<title>' . lang('page_title') . ' ROB694 normal company</title>',
            $page->body,
        );
        self::assertMatchesRegularExpression(
            '~<img src="[^"]*assets/img/logo\.png" alt="logo" id="company-logo">~',
            $page->body,
        );
        self::assertMatchesRegularExpression(
            '~<label for="custom-field-1" class="form-label">\s*ROB694 normal field\s*</label>~',
            $page->body,
        );
    }

    public function testPublicBookingEscapesCompanyNameInTitleAndOgTitle(): void
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        $companyName = 'ROB694"><span data-rob694="company">Company</span>';
        $this->setSetting('company_name', $companyName);
        $this->setSetting('disable_booking', '0');

        $page = $client->get('booking');
        self::assertSame(200, $page->statusCode);
        $normalTitle = html_escape(lang('page_title') . ' ' . $companyName);
        self::assertSame(1, substr_count($page->body, '<title>' . $normalTitle . '</title>'));
        self::assertSame(1, substr_count($page->body, '<meta property="og:title" content="' . $normalTitle . '"/>'));
        $this->assertNoInjectedElements($page->body);
    }

    public function testPublicBookingEscapesCompanyLogoInHeaderImageSource(): void
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        $companyLogo = 'https://synthetic.invalid/rob694-logo.svg?x="\'><img data-rob694="logo">';
        $this->setSetting('company_logo', $companyLogo);
        $page = $client->get('booking');
        self::assertSame(200, $page->statusCode);
        self::assertSame(
            1,
            substr_count($page->body, '<img src="' . html_escape($companyLogo) . '" alt="logo" id="company-logo">'),
        );
        $this->assertNoInjectedElements($page->body);
    }

    public function testPublicBookingEscapesActiveCustomFieldLabelText(): void
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        $customLabel = 'ROB694"><em data-rob694="label">Custom</em>';
        $this->setSetting('label_custom_field_1', $customLabel);
        $this->setSetting('display_custom_field_1', '1');
        $this->setSetting('require_custom_field_1', '0');
        $page = $client->get('booking');
        self::assertSame(200, $page->statusCode);
        self::assertSame(
            1,
            preg_match(
                '~<label for="custom-field-1" class="form-label">\s*' .
                    preg_quote(html_escape($customLabel), '~') .
                    '\s*</label>~',
                $page->body,
            ),
        );
        $this->assertNoInjectedElements($page->body);
    }

    public function testDisabledBookingEscapesCompanyNameInTitle(): void
    {
        $client = $this->server?->client();
        self::assertNotNull($client);
        $companyName = 'ROB694"><span data-rob694="disabled">Company</span>';
        $this->setSetting('company_name', $companyName);
        $this->setSetting('disable_booking', '1');
        $disabledPage = $client->get('booking');
        self::assertSame(200, $disabledPage->statusCode);
        $disabledTitle = html_escape(lang('page_title') . ' ' . $companyName) . ' | Easy!Appointments';
        self::assertSame(1, substr_count($disabledPage->body, '<title>' . $disabledTitle . '</title>'));
        $this->assertNoInjectedElements($disabledPage->body);
    }

    private function assertNoInjectedElements(string $body): void
    {
        self::assertStringNotContainsString('<span data-rob694=', $body);
        self::assertStringNotContainsString('<em data-rob694=', $body);
        self::assertStringNotContainsString('<img data-rob694=', $body);
    }

    private function snapshotSettings(): void
    {
        $db = get_instance()->db;
        foreach (self::SETTING_NAMES as $name) {
            $row = $db->get_where('settings', ['name' => $name])->row_array();
            if ($row) {
                $this->settingSnapshots[$name] = $row;
            }
        }
        $this->settingsSnapshotTaken = true;
    }

    private function configureSyntheticSettings(): void
    {
        $this->setSetting('company_name', 'ROB694 baseline company');
        $this->setSetting('company_logo', '');
        $this->setSetting('label_custom_field_1', 'ROB694 baseline field');
        $this->setSetting('display_custom_field_1', '0');
        $this->setSetting('require_custom_field_1', '0');
        $this->setSetting('disable_booking', '0');
    }

    private function setSetting(string $name, string $value): void
    {
        $db = get_instance()->db;
        $row = $db->get_where('settings', ['name' => $name])->row_array();
        if ($row) {
            self::assertTrue($db->update('settings', ['value' => $value], ['id' => (int) $row['id']]));
            return;
        }
        self::assertTrue($db->insert('settings', ['name' => $name, 'value' => $value]));
    }

    private function restoreSettings(): void
    {
        if (!$this->settingsSnapshotTaken) {
            return;
        }
        $db = get_instance()->db;
        foreach (self::SETTING_NAMES as $name) {
            $db->delete('settings', ['name' => $name]);
            if (isset($this->settingSnapshots[$name])) {
                self::assertTrue($db->insert('settings', $this->settingSnapshots[$name]));
            }
        }
        $this->settingSnapshots = [];
        $this->settingsSnapshotTaken = false;
    }
}
