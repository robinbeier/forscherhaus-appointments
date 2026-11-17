<?php

declare(strict_types=1);

define('ENVIRONMENT', 'testing');
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
$kdfRoot = getenv('KDF_TEST_ROOT') ?: (sys_get_temp_dir() . '/accounts-kdf-' . getmypid());
$kdfCache = $kdfRoot . '/cache';
mkdir($kdfCache, 0700, true);
mkdir(dirname($kdfCache) . '/login-kdf-locks', 0755, true);
$lockRoot = dirname($kdfCache) . '/login-kdf-locks';
chmod($lockRoot, 0755);
$lockOwner = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
$lockGroup = function_exists('posix_getegid') ? (int) posix_getegid() : 0;
chown($lockRoot, $lockOwner);
chgrp($lockRoot, $lockGroup);
$webUid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
$webGid = function_exists('posix_getegid') ? (int) posix_getegid() : 0;
putenv('EA_LOGIN_KDF_SLOT_UID=' . $webUid);
putenv('EA_LOGIN_KDF_SLOT_GID=' . $webGid);
putenv('EA_LOGIN_KDF_LOCK_ROOT_UID=' . $lockOwner);
putenv('EA_LOGIN_KDF_LOCK_ROOT_GID=' . $lockGroup);
for ($slot = 0; $slot < 4; $slot++) {
    $slotPath = $lockRoot . '/slot-' . $slot . '.lock';
    touch($slotPath);
    chown($slotPath, $webUid);
    chgrp($slotPath, $webGid);
    chmod($slotPath, 0600);
}
$hardlinkProbe = ($argv[4] ?? '') === 'hardlink';
if ($hardlinkProbe && !link($lockRoot . '/slot-0.lock', $lockRoot . '/slot-0.alias')) {
    fwrite(STDERR, "could not create hardlink probe\n");
    exit(1);
}
putenv('EA_LOGIN_KDF_LOCK_ROOT=' . realpath($lockRoot));
function config_item(string $key): mixed
{
    global $kdfCache;
    return $key === 'cache_path' ? $kdfCache . DIRECTORY_SEPARATOR : null;
}
register_shutdown_function(static function () use (&$kdfCache): void {
    foreach (glob(dirname($kdfCache) . '/login-kdf-locks/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir(dirname($kdfCache) . '/login-kdf-locks');
    @rmdir($kdfCache);
    @rmdir(dirname($kdfCache));
});
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
$mode = $argv[4] ?? '';
$holdKdfPermits = $mode === 'capacity';
$holdKdfChild = $mode === 'hold';
$duplicate = $mode === 'duplicate';
$ldapLinked = $mode === 'ldap';
$linkDuringVerify = $mode === 'ldap-transition';
if ($holdKdfChild) {
    $permitRoot = dirname($kdfCache) . '/login-kdf-locks';
    $handles = [];
    for ($slot = 0; $slot < 4; $slot++) {
        $handle = fopen($permitRoot . '/slot-' . $slot . '.lock', 'c');
        flock($handle, LOCK_EX);
        $handles[] = $handle;
    }
    usleep(2000000);
    exit(0);
}
$ci->db = new class ($format, $rotate, $duplicate, $ldapLinked, $linkDuringVerify) {
    public array $row;
    public int $reads = 0;
    public int $affected = 0;
    public array $predicate = [];
    /** @var array<string, mixed> */
    private array $queryWhere = [];
    public function __construct(
        string $format,
        private bool $rotate,
        private bool $duplicate,
        bool $ldapLinked,
        private bool $linkDuringVerify,
    )
    {
        $this->row = [
            'id_users' => 1,
            'user_id' => 1,
            'user_email' => 'synthetic.invalid',
            'user_timezone' => 'UTC',
            'user_language' => 'english',
            'credential_version' => 0,
            'ldap_dn' => $ldapLinked ? 'uid=synthetic,ou=people,dc=invalid' : '',
            'role_slug' => 'admin',
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
        $this->queryWhere = $where;
        return $this->get();
    }
    public function select(string $fields): self
    {
        return $this;
    }
    public function from(string $table): self
    {
        return $this;
    }
    public function join(string $table, string $condition, string $type = 'inner'): self
    {
        return $this;
    }
    public function where(string|array $column, mixed $value = null): self
    {
        if (is_array($column)) {
            $this->queryWhere = $column;
            $this->predicate = $column;
        } else {
            $this->queryWhere[$column] = $value;
        }
        return $this;
    }
    public function limit(int $limit): self
    {
        return $this;
    }
    public function get(): object
    {
        ++$this->reads;
        $where = [];
        foreach ($this->queryWhere as $key => $value) {
            $where[basename(str_replace('.', '/', (string) $key))] = $value;
        }
        $row = array_diff_assoc($where, $this->row) === [] ? $this->row : [];
        $this->queryWhere = [];
        if ($this->rotate) {
            $this->row['password'] = hash_password_argon2id('replacement-password');
            $this->rotate = false;
        }
        if ($this->linkDuringVerify) {
            $this->row['ldap_dn'] = 'uid=synthetic,ou=people,dc=invalid';
            ++$this->row['credential_version'];
            $this->linkDuringVerify = false;
        }
        return new class ($row, $this->duplicate && $this->reads === 1) {
            public function __construct(private array $row, private bool $duplicate) {}
            public function row_array(): array
            {
                return $this->row;
            }
            public function result_array(): array
            {
                return $this->duplicate ? [$this->row, $this->row] : ($this->row === [] ? [] : [$this->row]);
            }
        };
    }
    public function update(string $table, array $values): bool
    {
        $this->affected = 0;
        if (array_diff_assoc($this->predicate, $this->row) === []) {
            $this->row = array_replace($this->row, $values);
            $this->affected = 1;
        }
        // CI's query builder starts each statement with a fresh predicate.
        $this->queryWhere = [];
        $this->predicate = [];
        return true;
    }
    public function affected_rows(): int
    {
        return $this->affected;
    }
};
require dirname(__DIR__, 2) . '/application/libraries/Accounts.php';
$heldKdfPermits = [];
if ($holdKdfPermits) {
    $permitRoot = dirname($kdfCache) . '/login-kdf-locks';
    $child = proc_open(
        [PHP_BINARY, __FILE__, 'legacy', 'reader', 'stable', 'hold'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $childPipes,
        null,
        ['KDF_TEST_ROOT' => $kdfRoot],
    );
    usleep(300000);
}
$started = hrtime(true);
$capacityDenied = false;
try {
    $result = (new Accounts())->check_login($mode === 'unknown' ? 'missing' : 'synthetic', 'old-password');
} catch (RuntimeException $e) {
    if ((!$holdKdfPermits && !$hardlinkProbe) || $e->getMessage() !== 'Authentication capacity unavailable.') {
        throw $e;
    }
    $capacityDenied = true;
    $result = null;
}
$elapsedNs = hrtime(true) - $started;
echo json_encode(
    [
        'authenticated' => $result !== null,
        'reads' => $ci->db->reads,
        'elapsed_ns' => $elapsedNs,
        'replacement_preserved' => verify_password(
            $ci->db->row['password'],
            $ci->db->row['salt'],
            'replacement-password',
        ),
        'original_preserved' => verify_password(
            $ci->db->row['password'],
            $ci->db->row['salt'],
            'old-password',
        ),
        'modern' => str_starts_with($ci->db->row['password'], '$argon2id$'),
        'capacity_denied' => $capacityDenied,
    ],
    JSON_THROW_ON_ERROR,
);
if (is_resource($child ?? null)) {
    proc_close($child);
}
