<?php

declare(strict_types=1);

define('BASEPATH', __DIR__);
define('MAX_PASSWORD_LENGTH', 100);
define('MODERN_PASSWORD_WRITES', ($argv[2] ?? '') === 'active');
require dirname(__DIR__, 2) . '/application/helpers/password_helper.php';
class CI_Controller {}
class EA_Controller extends CI_Controller
{
    public object $db;
    public object $load;
    public object $users_model;
    public object $roles_model;
    public object $timezones;
}
class Config
{
    public const LANGUAGE = 'english';
}
$ci = new EA_Controller();
function &get_instance(): EA_Controller
{
    return $GLOBALS['ci'];
}
$ci->load = new class {
    public function model($name): void {}
    public function library($name): void {}
};
$ci->users_model = new class {
    public function find($id): array
    {
        return [
            'id' => $id,
            'id_roles' => 1,
            'email' => 'synthetic.invalid',
            'timezone' => 'UTC',
            'language' => 'english',
        ];
    }
};
$ci->roles_model = new class {
    public function find($id): array
    {
        return ['slug' => 'admin'];
    }
};
$ci->timezones = new class {
    public function get_default_timezone(): string
    {
        return 'UTC';
    }
};
$format = $argv[1] ?? 'legacy';
$rotate = ($argv[3] ?? '') === 'reset';
$ci->db = new class ($format, $rotate) {
    public array $row;
    public int $reads = 0;
    public int $affected = 0;
    public array $predicate = [];
    public function __construct(string $format, private bool $rotate)
    {
        $this->row = [
            'id_users' => 1,
            'username' => 'synthetic',
            'salt' => 'salt',
            'password' =>
                $format === 'legacy'
                    ? hash_legacy_password('salt', 'old-password')
                    : hash_password_argon2id('old-password'),
        ];
    }
    public function get_where(string $table, array $where): object
    {
        ++$this->reads;
        $row = array_diff_assoc($where, $this->row) === [] ? $this->row : [];
        if ($this->rotate) {
            $this->row['password'] = hash_password_argon2id('replacement-password');
            $this->rotate = false;
        }
        return new class ($row) {
            public function __construct(private array $row) {}
            public function row_array(): array
            {
                return $this->row;
            }
        };
    }
    public function where(array $where): self
    {
        $this->predicate = $where;
        return $this;
    }
    public function update(string $table, array $values): bool
    {
        $this->affected = 0;
        if (array_diff_assoc($this->predicate, $this->row) === []) {
            $this->row = array_replace($this->row, $values);
            $this->affected = 1;
        }
        return true;
    }
    public function affected_rows(): int
    {
        return $this->affected;
    }
};
require dirname(__DIR__, 2) . '/application/libraries/Accounts.php';
$result = (new Accounts())->check_login('synthetic', 'old-password');
echo json_encode(
    [
        'authenticated' => $result !== null,
        'reads' => $ci->db->reads,
        'replacement_preserved' => verify_password(
            $ci->db->row['password'],
            $ci->db->row['salt'],
            'replacement-password',
        ),
        'modern' => str_starts_with($ci->db->row['password'], '$argon2id$'),
    ],
    JSON_THROW_ON_ERROR,
);
