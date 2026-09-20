<?php

namespace App\Modules\Organization\Http\Requests;

/**
 * Server messages for Teams administration, in both UI languages.
 * Kept here until they move to lang/{id,en}/teams.php.
 */
class TeamMessages
{
    private const TEXT = [
        'id' => [
            'name_taken' => 'Nama tim ini sudah dipakai. Pilih nama lain.',
            'lead_not_member' => 'Team Lead harus anggota tim ini. Tambahkan dia ke tim dulu.',
            'lead_without_role' => 'Orang ini belum punya peran Team Lead. Beri peran itu di halaman Orang dulu.',
            'already_member' => 'Orang ini sudah anggota tim.',
            'created' => 'Tim :name dibuat.',
            'saved' => 'Tim :name disimpan.',
            'member_added' => ':person masuk ke tim :name.',
            'member_removed' => ':person dikeluarkan dari tim :name.',
            'deleted' => 'Tim :name dihapus. Akun anggotanya tetap ada.',
        ],
        'en' => [
            'name_taken' => 'A team with this name already exists. Pick another name.',
            'lead_not_member' => 'The Team Lead must be a member of this team. Add them to the team first.',
            'lead_without_role' => 'This person does not hold the Team Lead role yet. Give them that role on the People page first.',
            'already_member' => 'This person is already on the team.',
            'created' => 'Team :name created.',
            'saved' => 'Team :name saved.',
            'member_added' => ':person joined :name.',
            'member_removed' => ':person was removed from :name.',
            'deleted' => 'Team :name deleted. Its members keep their accounts.',
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
