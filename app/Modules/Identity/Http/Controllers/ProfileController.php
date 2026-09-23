<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Access\Permission;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Audit\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profil: a person edits their own identity data, photo, and CV (docs/13). Files live on the private disk and are
 * served through this controller: photos to any signed-in colleague, the CV only to its owner and Superadmin.
 */
class ProfileController extends Controller
{
    public const PHOTO_MAX_KB = 2048;

    public const CV_MAX_KB = 5120;

    private const DISK = 'local';

    public function edit(Request $request): Response
    {
        $user = $request->user()->load(['roles:id,name', 'teams:id,name']);

        return Inertia::render('profile/Edit', [
            'profile' => [
                ...collect(UpdateProfileRequest::FIELDS)->mapWithKeys(fn (string $field) => [$field => $field === 'birth_date'
                    ? $user->birth_date?->format('Y-m-d')
                    : $user->{$field}])->all(),
                'username' => $user->username,
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'employment_type' => $user->employment_type->value,
                'roles' => $user->roles->pluck('name')->values(),
                'teams' => $user->teams->sortBy('name')->pluck('name')->values(),
                'initials' => $user->initials(),
                'photo_url' => $user->photoUrl(),
                'cv' => $user->cv_path ? [
                    'name' => $user->cv_original_name,
                    'uploaded_at' => $user->cv_uploaded_at?->toIso8601String(),
                    'url' => route('people.cv', $user, absolute: false),
                ] : null,
            ],
            'limits' => ['photo_max_kb' => self::PHOTO_MAX_KB, 'cv_max_kb' => self::CV_MAX_KB],
        ]);
    }

    public function update(UpdateProfileRequest $request, Auditor $auditor): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $before = $user->only(UpdateProfileRequest::FIELDS);
        $before['birth_date'] = $user->birth_date?->format('Y-m-d');

        $user->fill($data)->save();

        $changed = array_values(array_filter(
            UpdateProfileRequest::FIELDS,
            fn (string $field) => array_key_exists($field, $data) && ($before[$field] ?? null) !== ($data[$field] ?? null),
        ));

        if ($changed !== []) {
            // Names are kept in the audit; personal details only as the list of fields that changed
            $auditor->record('profile.updated', $user,
                ['name' => $before['name'], 'nickname' => $before['nickname']],
                ['name' => $user->name, 'nickname' => $user->nickname, 'fields' => $changed],
            );
        }

        return back()->with('status', __('identity::profile.saved'));
    }

    public function storePhoto(Request $request, Auditor $auditor): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PHOTO_MAX_KB, 'dimensions:min_width=64,min_height=64,max_width=6000,max_height=6000'],
        ], [
            'photo.mimes' => __('identity::profile.photo_type'),
            'photo.image' => __('identity::profile.photo_type'),
            'photo.max' => __('identity::profile.photo_size', ['mb' => self::PHOTO_MAX_KB / 1024]),
            'photo.dimensions' => __('identity::profile.photo_dimensions'),
        ]);

        $user = $request->user();
        $file = $request->file('photo');
        $path = $file->storeAs('profile-photos', $user->id.'-'.Str::random(16).'.'.$file->extension(), self::DISK);
        $old = $user->avatar_path;

        $user->forceFill(['avatar_path' => $path])->save();
        $this->deleteFile($old);
        $auditor->record('profile.photo_changed', $user);

        return back()->with('status', __('identity::profile.photo_saved'));
    }

    public function destroyPhoto(Request $request, Auditor $auditor): RedirectResponse
    {
        $user = $request->user();
        $this->deleteFile($user->avatar_path);
        $user->forceFill(['avatar_path' => null])->save();
        $auditor->record('profile.photo_removed', $user);

        return back()->with('status', __('identity::profile.photo_removed'));
    }

    public function storeCv(Request $request, Auditor $auditor): RedirectResponse
    {
        $request->validate([
            'cv' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.self::CV_MAX_KB],
        ], [
            'cv.mimes' => __('identity::profile.cv_type'),
            'cv.mimetypes' => __('identity::profile.cv_type'),
            'cv.max' => __('identity::profile.cv_size', ['mb' => self::CV_MAX_KB / 1024]),
        ]);

        $user = $request->user();
        $file = $request->file('cv');
        $path = $file->storeAs('cvs', $user->id.'-'.Str::random(16).'.pdf', self::DISK);
        $old = $user->cv_path;
        $name = Str::limit(preg_replace('/[^\pL\pN ._()-]+/u', '_', $file->getClientOriginalName()) ?: 'cv.pdf', 180, '');

        $user->forceFill(['cv_path' => $path, 'cv_original_name' => $name, 'cv_uploaded_at' => now()])->save();
        $this->deleteFile($old);
        $auditor->record('profile.cv_uploaded', $user, null, ['file' => $name]);

        return back()->with('status', __('identity::profile.cv_saved'));
    }

    public function destroyCv(Request $request, Auditor $auditor): RedirectResponse
    {
        $user = $request->user();
        $this->deleteFile($user->cv_path);
        $user->forceFill(['cv_path' => null, 'cv_original_name' => null, 'cv_uploaded_at' => null])->save();
        $auditor->record('profile.cv_removed', $user);

        return back()->with('status', __('identity::profile.cv_removed'));
    }

    /** Profile photo for any signed-in colleague (lists, boards, tasks). */
    public function photo(User $user): StreamedResponse
    {
        abort_if(blank($user->avatar_path) || ! Storage::disk(self::DISK)->exists($user->avatar_path), 404);

        return Storage::disk(self::DISK)->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'Content-Disposition' => 'inline',
        ]);
    }

    /** CV download: the owner and people who manage accounts only. */
    public function cv(Request $request, User $user): StreamedResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->id === $user->id || $viewer->hasPermission(Permission::ManageUsers), 403);
        abort_if(blank($user->cv_path) || ! Storage::disk(self::DISK)->exists($user->cv_path), 404);

        return Storage::disk(self::DISK)->download($user->cv_path, $user->cv_original_name ?: 'cv.pdf', [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function deleteFile(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
