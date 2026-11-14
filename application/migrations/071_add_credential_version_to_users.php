<?php defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_credential_version_to_users extends EA_Migration
{
    public function up(): void
    {
        if (!$this->db->table_exists('users') || $this->db->field_exists('credential_version', 'users')) {
            return;
        }

        $this->dbforge->add_column('users', [
            'credential_version' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'default' => 0,
                'after' => 'ldap_dn',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->table_exists('users') && $this->db->field_exists('credential_version', 'users')) {
            $this->dbforge->drop_column('users', 'credential_version');
        }
    }
}
