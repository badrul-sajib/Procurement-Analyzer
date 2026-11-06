<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ListQuery;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', 'string'], 'is_active' => ['nullable', 'boolean']]);
        $query = User::query()->with('roles')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', ListQuery::like($s))->orWhere('email', 'like', ListQuery::like($s))))
            ->when($request->query('role'), fn ($q, $r) => $q->role($r))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')));

        return UserResource::collection(ListQuery::paginate($query, $request, ['name' => 'name', 'email' => 'email', 'last_login_at' => 'last_login_at', 'id' => 'id'], 'name'));
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->guardRoles($request, $data['roles'] ?? []);

        $user = DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'is_active' => $data['is_active'] ?? true]);
            $user->syncRoles($data['roles'] ?? []);
            AuditLogger::log('user.created', 'users', $user->id, "Created user {$user->email}", null, ['email' => $user->email, 'roles' => $data['roles'] ?? []]);

            return $user;
        });

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        $data = $this->validated($request, $user);
        if ($user->hasRole(PermissionCatalog::SUPER_ADMIN) && ! $request->user()->hasRole(PermissionCatalog::SUPER_ADMIN)) {
            abort(403, 'Only a Super Admin can change a Super Admin account.');
        }
        if (array_key_exists('roles', $data)) {
            $this->guardRoles($request, $data['roles']);
        }
        if ($user->is($request->user()) && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw new BusinessRuleException('cannot_deactivate_self', 'You cannot deactivate your own account.');
        }

        DB::transaction(function () use ($user, $data) {
            $before = ['name' => $user->name, 'email' => $user->email, 'is_active' => $user->is_active, 'roles' => $user->roles->pluck('name')->all()];
            $user->update(array_filter(array_intersect_key($data, array_flip(['name', 'email', 'password', 'is_active'])), fn ($v) => $v !== null));
            if (array_key_exists('roles', $data)) {
                $user->syncRoles($data['roles']);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $user->tokens()->delete(); // deactivation ends API sessions immediately
            }
            $after = ['name' => $user->name, 'email' => $user->email, 'is_active' => $user->is_active, 'roles' => $user->roles()->pluck('name')->all()];
            [$old, $new] = AuditLogger::diff($before, $after);
            if (! empty($data['password'])) {
                $new['password'] = 'changed';
            }
            if ($new) {
                AuditLogger::log(isset($new['roles']) ? 'user.roles_changed' : 'user.updated', 'users', $user->id, "Updated user {$user->email}", $old, $new);
            }
        });

        return new UserResource($user->fresh('roles'));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:120'],
            'email' => [$user ? 'sometimes' : 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(12)->letters()->numbers(), 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);
    }

    private function guardRoles(Request $request, array $roles): void
    {
        if (in_array(PermissionCatalog::SUPER_ADMIN, $roles, true) && ! $request->user()->hasRole(PermissionCatalog::SUPER_ADMIN)) {
            abort(403, 'Only a Super Admin can grant the Super Admin role.');
        }
    }
}
