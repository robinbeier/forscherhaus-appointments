<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Bounded localhost proof for the customer API update target contract. */
final class CustomersApiWriteProbe
{
    public function __construct(
        private readonly GateHttpClient $client,
        private readonly object $db,
        private readonly DefenseVerificationFixture $fixture,
    ) {}

    public static function forApp(
        string $baseUrl,
        string $username,
        string $password,
        object $db,
        DefenseVerificationFixture $fixture,
        string $indexPage = 'index.php',
    ): self {
        if ($username === '' || $password === '') {
            throw new RuntimeException('Customers API probe credentials are unavailable.');
        }

        return new self(
            new GateHttpClient(
                $baseUrl,
                indexPage: $indexPage,
                additionalHeaders: [
                    'X-FH-Ordinary-Probe' => '1',
                    'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                ],
            ),
            $db,
            $fixture,
        );
    }

    public function run(?callable $observe = null): array
    {
        $observe ??= static function (string $phase, string $outcome): void {};
        $state = $this->fixture->read();
        if (($state['profile'] ?? null) !== 'customer_boundary') {
            throw new RuntimeException('Customers API verification requires the customer-boundary graph.');
        }

        $customerA = (int) ($state['ids']['customer_find_update'] ?? 0);
        $customerB = (int) ($state['ids']['customer_destroy'] ?? 0);
        if ($customerA < 1 || $customerB < 1 || $customerA === $customerB) {
            throw new RuntimeException('Customers API customer targets are unavailable.');
        }

        $beforeA = $this->userSnapshot($customerA, (string) $state['marker']);
        $beforeB = $this->userSnapshot($customerB, (string) $state['marker']);

        $observe('customers_api_conflict_put', 'started');
        try {
            $conflictPayload = $this->apiPayload($beforeB);
            $conflictPayload['id'] = $customerB;
            $conflictPayload['firstName'] = 'Conflict Must Not Persist';
            $conflict = $this->client->requestJsonApp('PUT', 'api/v1/customers/' . $customerA, $conflictPayload);
            if ($conflict->statusCode !== 400) {
                throw new RuntimeException('Customers API conflicting body ID was not rejected.');
            }
            if ($this->userSnapshot($customerA, (string) $state['marker']) !== $beforeA) {
                throw new RuntimeException('Customers API conflict changed customer A.');
            }
            if ($this->userSnapshot($customerB, (string) $state['marker']) !== $beforeB) {
                throw new RuntimeException('Customers API conflict changed customer B.');
            }
            $observe('customers_api_conflict_put', 'passed');
        } catch (\Throwable $error) {
            $observe('customers_api_conflict_put', 'failed');
            throw $error;
        }

        $observe('customers_api_matching_put', 'started');
        try {
            $positivePayload = $this->apiPayload($beforeA);
            $positivePayload['id'] = $customerA;
            $positivePayload['firstName'] = 'Customers API Verified';
            $positive = $this->client->requestJsonApp('PUT', 'api/v1/customers/' . $customerA, $positivePayload);
            if ($positive->statusCode !== 200) {
                throw new RuntimeException('Customers API matching body ID was not accepted.');
            }
            $response = $this->decodeObject($positive->body);
            if ((int) ($response['id'] ?? 0) !== $customerA) {
                throw new RuntimeException('Customers API positive PUT returned another customer.');
            }
            if (($response['firstName'] ?? null) !== 'Customers API Verified') {
                throw new RuntimeException('Customers API positive PUT returned the wrong field.');
            }
            $afterA = $this->userSnapshot($customerA, (string) $state['marker']);
            $afterB = $this->userSnapshot($customerB, (string) $state['marker']);
            $expectedA = $beforeA;
            $expectedA['first_name'] = 'Customers API Verified';
            $expectedA['update_datetime'] = $afterA['update_datetime'];
            if ($afterA !== $expectedA) {
                throw new RuntimeException('Customers API positive PUT changed unexpected customer A fields.');
            }
            if ($afterB !== $beforeB) {
                throw new RuntimeException('Customers API positive PUT changed customer B.');
            }
            $observe('customers_api_matching_put', 'passed');
        } catch (\Throwable $error) {
            $observe('customers_api_matching_put', 'failed');
            throw $error;
        }

        return [
            'status' => 'verified',
            'coverage' => 'put_target_binding',
            'conflict_status' => $conflict->statusCode,
            'positive_status' => $positive->statusCode,
            'observed' =>
                'A conflicting customer body ID returned 400 with both complete owned user rows unchanged; a matching customer body ID returned 200, persisted only the intended A first-name change, and left B unchanged.',
        ];
    }

    /** @return array<string,mixed> */
    private function userSnapshot(int $id, string $marker): array
    {
        $rows = $this->db->get_where('users', ['id' => $id])->result_array();
        if (count($rows) !== 1 || ($rows[0]['notes'] ?? null) !== $marker) {
            throw new RuntimeException('Customers API owned user snapshot is missing or drifted.');
        }

        return $rows[0];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function apiPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'firstName' => $row['first_name'],
            'lastName' => $row['last_name'],
            'email' => $row['email'],
            'phone' => $row['phone_number'],
            'address' => $row['address'],
            'city' => $row['city'],
            'zip' => $row['zip_code'],
            'notes' => $row['notes'],
            'timezone' => $row['timezone'],
            'language' => $row['language'],
            'customField1' => $row['custom_field_1'],
            'customField2' => $row['custom_field_2'],
            'customField3' => $row['custom_field_3'],
            'customField4' => $row['custom_field_4'],
            'customField5' => $row['custom_field_5'],
            'ldapDn' => $row['ldap_dn'],
        ];
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Customers API positive PUT returned invalid JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Customers API positive PUT returned no customer object.');
        }

        return $decoded;
    }
}
