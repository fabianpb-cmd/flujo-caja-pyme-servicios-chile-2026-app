<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

class UserManagementController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): View
    {
        $users = User::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('name')
            ->orderBy('email')
            ->get();

        return view('admin.users.index', [
            'users' => $users,
            'hasLastAccessColumn' => $this->lastAccessColumn() !== null,
            'lastAccessColumn' => $this->lastAccessColumn(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'userItem' => new User([
                'active' => true,
                'role' => 'user',
            ]),
            'mode' => 'create',
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = new User();
        $user->company_id = $request->user()->company_id;
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];
        $user->active = $data['active'];
        $user->password = Hash::make($data['password']);
        $user->save();

        $this->audit->record('user.created', $user->fresh(), $request->user(), null, $this->auditPayload($user));

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Usuario creado correctamente.');
    }

    public function edit(Request $request, User $user): View
    {
        return view('admin.users.form', [
            'userItem' => $this->companyUser($request, $user),
            'mode' => 'edit',
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user = $this->companyUser($request, $user);
        $data = $request->validated();
        $before = $this->auditPayload($user);

        $this->ensureAdminProtection($request->user(), $user, $data['role'], $data['active']);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];
        $user->active = $data['active'];
        $user->save();

        $this->audit->record('user.updated', $user->fresh(), $request->user(), $before, $this->auditPayload($user));

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Usuario actualizado correctamente.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $user = $this->companyUser($request, $user);
        $before = $this->auditPayload($user);
        $nextActive = ! $user->active;

        $this->ensureAdminProtection($request->user(), $user, $user->role, $nextActive);

        $user->active = $nextActive;
        $user->save();

        $this->audit->record('user.active_toggled', $user->fresh(), $request->user(), $before, $this->auditPayload($user));

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Usuario actualizado correctamente.');
    }

    public function editPassword(Request $request, User $user): View
    {
        return view('admin.users.password', [
            'userItem' => $this->companyUser($request, $user),
        ]);
    }

    public function updatePassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $user = $this->companyUser($request, $user);

        $password = $request->validated()['password'];

        $user->password = Hash::make($password);
        $user->save();

        $this->invalidateUserSessions($user, $request->user()->is($user) ? $request->session()->getId() : null);
        $this->audit->record('user.password_reset', $user->fresh(), $request->user(), null, ['password_reset' => true]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Contraseña restablecida correctamente.');
    }

    public function resetTwoFactor(Request $request, User $user, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        $user = $this->companyUser($request, $user);
        $hadTwoFactor = ! is_null($user->two_factor_secret);

        if ($hadTwoFactor) {
            $disable($user);
        }

        $this->invalidateUserSessions($user);
        $this->audit->record('user.2fa_reset', $user->fresh(), $request->user(), ['two_factor_enabled' => $hadTwoFactor], ['two_factor_enabled' => false]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Configuración 2FA restablecida correctamente.');
    }

    private function companyUser(Request $request, User $user): User
    {
        abort_unless($user->company_id === $request->user()->company_id, 403);

        return $user;
    }

    private function roleOptions(): array
    {
        return [
            'admin' => 'Administrador',
            'user' => 'Usuario',
        ];
    }

    private function auditPayload(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'active' => (bool) $user->active,
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
        ];
    }

    private function ensureAdminProtection(User $actor, User $target, string $nextRole, bool $nextActive): void
    {
        if ($actor->is($target) && ! $nextActive) {
            throw ValidationException::withMessages([
                'active' => 'No puedes desactivar tu propio usuario.',
            ]);
        }

        if ($target->role !== 'admin' || ! $target->active) {
            return;
        }

        if ($nextRole === 'admin' && $nextActive) {
            return;
        }

        $activeAdmins = User::query()
            ->where('company_id', $target->company_id)
            ->where('role', 'admin')
            ->where('active', true)
            ->count();

        if ($activeAdmins <= 1) {
            throw ValidationException::withMessages([
                'role' => 'Debe existir al menos un administrador activo en la empresa.',
            ]);
        }
    }

    private function invalidateUserSessions(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $table = (string) config('session.table', 'sessions');

        if (! Schema::hasTable($table)) {
            return;
        }

        $query = DB::table($table)->where('user_id', $user->id);

        if ($exceptSessionId) {
            $query->where('id', '!=', $exceptSessionId);
        }

        $query->delete();
    }

    private function lastAccessColumn(): ?string
    {
        return match (true) {
            Schema::hasColumn('users', 'last_login_at') => 'last_login_at',
            Schema::hasColumn('users', 'last_access_at') => 'last_access_at',
            Schema::hasColumn('users', 'last_login') => 'last_login',
            default => null,
        };
    }
}
