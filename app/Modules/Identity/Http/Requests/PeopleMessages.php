<?php

namespace App\Modules\Identity\Http\Requests;

/**
 * Server messages for People administration, in both UI languages.
 * Kept here until they move to lang/{id,en}/people.php.
 */
class PeopleMessages
{
    private const TEXT = [
        'id' => [
            'username_format' => 'Username hanya boleh huruf kecil, angka, titik, tanda hubung, dan garis bawah.',
            'username_taken' => 'Username ini sudah dipakai. Pilih username lain.',
            'email_taken' => 'Email ini sudah dipakai akun lain.',
            'employee_code_taken' => 'Kode karyawan ini sudah dipakai akun lain.',
            'employment_type_required' => 'Pilih jenis karyawan.',
            'roles_required' => 'Pilih minimal satu peran.',
            'self_status' => 'Kamu tidak bisa menonaktifkan akun kamu sendiri. Minta Superadmin lain melakukannya.',
            'self_role' => 'Kamu tidak bisa melepas peran Superadmin dari akun kamu sendiri.',
            'last_superadmin_role' => 'Ini Superadmin aktif terakhir. Jadikan orang lain Superadmin dulu sebelum melepas peran ini.',
            'last_superadmin_status' => 'Ini Superadmin aktif terakhir. Jadikan orang lain Superadmin dulu sebelum menonaktifkan akun ini.',
            'saved' => 'Data :name disimpan.',
            'imposter_blocked' => 'Saat Imposter aktif, tindakan ini tidak diizinkan. Kembali ke Superadmin dulu.',
        ],
        'en' => [
            'username_format' => 'Usernames can only use lowercase letters, digits, dots, dashes, and underscores.',
            'username_taken' => 'This username is taken. Pick another one.',
            'email_taken' => 'Another account already uses this email.',
            'employee_code_taken' => 'Another account already uses this employee code.',
            'employment_type_required' => 'Pick an employment type.',
            'roles_required' => 'Pick at least one role.',
            'self_status' => 'You cannot deactivate your own account. Ask another Superadmin to do it.',
            'self_role' => 'You cannot remove the Superadmin role from your own account.',
            'last_superadmin_role' => 'This is the last active Superadmin. Make someone else a Superadmin before removing this role.',
            'last_superadmin_status' => 'This is the last active Superadmin. Make someone else a Superadmin before deactivating this account.',
            'saved' => ':name saved.',
            'imposter_blocked' => 'This action is not allowed while Imposter is active. Return to Superadmin first.',
        ],
    ];

    /** @param  array<string, string>  $replace */
    public static function get(string $key, array $replace = []): string
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'id';
        $text = self::TEXT[$locale][$key] ?? self::TEXT['id'][$key] ?? $key;

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, $value, $text);
        }

        return $text;
    }
}
