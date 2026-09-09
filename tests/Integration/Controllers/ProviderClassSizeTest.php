<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use Providers;
use Tests\TestCase;

require_once APPPATH . 'controllers/Providers.php';

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class ProviderClassSizeTest extends TestCase
{
    /** @var list<int> */
    private array $createdProviderIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        get_instance()->load->model('providers_model');
        get_instance()->load->library('backoffice_request_dto_factory');
        get_instance()->load->library('permissions');
        session(['user_id' => 1, 'role_slug' => DB_SLUG_ADMIN]);
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        foreach ($this->createdProviderIds as $providerId) {
            get_instance()->db->delete('services_providers', ['id_users' => $providerId]);
            get_instance()->db->delete('user_settings', ['id_users' => $providerId]);
            get_instance()->db->delete('users', ['id' => $providerId]);
        }
        session(['user_id' => null, 'role_slug' => null]);
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        get_instance()->output->set_output('');
        parent::tearDown();
    }

    public function testStoreAndUpdatePreserveIntegerAndStringZero(): void
    {
        $providerId = $this->storeProvider(0);
        self::assertSame(0, $this->storedClassSize($providerId));

        $this->updateProvider($providerId, '0');
        self::assertSame(0, $this->storedClassSize($providerId));

        $this->updateProvider($providerId, null, false);
        self::assertNull($this->storedClassSize($providerId));
    }

    public function testEmptyAndNullClassSizeRemainUnset(): void
    {
        foreach ([null, ''] as $value) {
            $providerId = $this->storeProvider($value);
            self::assertNull($this->storedClassSize($providerId));

            $this->updateProvider($providerId, $value);
            self::assertNull($this->storedClassSize($providerId));
        }
    }

    private function storeProvider(int|string|null $classSize): int
    {
        $payload = $this->providerPayload($classSize);
        $_POST['provider'] = $payload;
        $controller = $this->controller();
        $controller->store();
        $response = json_decode(get_instance()->output->get_output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($response['success'] ?? false, json_encode($response, JSON_THROW_ON_ERROR));
        $id = (int) ($response['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        $this->createdProviderIds[] = $id;
        get_instance()->output->set_output('');
        return $id;
    }

    private function updateProvider(int $providerId, int|string|null $classSize, bool $includeClassSize = true): void
    {
        $payload = $this->providerPayload($classSize) + ['id' => $providerId];
        if (!$includeClassSize) {
            unset($payload['class_size_default']);
        }
        $_POST['provider'] = $payload;
        $controller = $this->controller();
        $controller->update();
        $response = json_decode(get_instance()->output->get_output(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($response['success'] ?? false, json_encode($response, JSON_THROW_ON_ERROR));
        get_instance()->output->set_output('');
    }

    private function controller(): Providers
    {
        $CI = &get_instance();
        $controller = new class extends Providers {
            public function __construct() {}
        };
        $controller->load = $CI->load;
        $controller->providers_model = $CI->providers_model;
        $controller->permissions = $CI->permissions;
        return $controller;
    }

    private function providerPayload(int|string|null $classSize): array
    {
        $serviceId = (int) get_instance()->db->select('id')->from('services')->limit(1)->get()->row()->id;
        return [
            'first_name' => 'Synthetic',
            'last_name' => 'Class Size ' . bin2hex(random_bytes(4)),
            'email' => 'class-size-' . bin2hex(random_bytes(5)) . '@example.org',
            'class_size_default' => $classSize,
            'services' => [$serviceId],
            'settings' => [
                'username' => 'class-size-' . bin2hex(random_bytes(4)),
                'password' => 'SyntheticPassword123!',
            ],
        ];
    }

    private function storedClassSize(int $providerId): ?int
    {
        $value = get_instance()
            ->db->select('class_size_default')
            ->get_where('users', ['id' => $providerId])
            ->row()->class_size_default;
        return $value === null ? null : (int) $value;
    }
}
