<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        // Spatie's users() relation can't be counted with withCount(); one grouped query instead.
        $counts = DB::table('model_has_roles')->where('model_type', User::class)->groupBy('role_id')->pluck(DB::raw('COUNT(*)'), 'role_id');

        return response()->json(['data' => Role::with('permissions:id,name')->orderBy('id')->get()
            ->map(fn (Role $r) => $this->shape($r, (int) ($counts[$r->id] ?? 0)))]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => collect(PermissionCatalog::PERMISSIONS)
            ->groupBy(fn ($p) => explode('.', $p)[0])->map(fn ($perms, $group) => ['group' => $group, 'permissions' => $perms->values()])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);
        AuditLogger::log('role.created', 'roles', $role->id, "Created role {$role->name}", null, ['permissions' => $data['permissions'] ?? []]);

        return response()->json(['data' => $this->shape($role->load('permissions'), 0)], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->assertEditable($role);
        $data = $this->validated($request, $role);
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }
        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $after = $role->fresh('permissions')->permissions->pluck('name')->sort()->values()->all();
        AuditLogger::log('role.permissions_changed', 'roles', $role->id, "Changed role {$role->name}",
            ['permissions' => $before], ['permissions' => $after, 'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))]);

        return response()->json(['data' => $this->shape($role->fresh('permissions'), $this->userCount($role))]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->assertEditable($role);
        if ($this->userCount($role) > 0) {
            throw new BusinessRuleException('role_in_use', 'Remove this role from all users before deleting it.');
        }
        AuditLogger::log('role.deleted', 'roles', $role->id, "Deleted role {$role->name}", ['permissions' => $role->permissions->pluck('name')->all()]);
        $role->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [$role ? 'sometimes' : 'required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($role?->id)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::PERMISSIONS)],
        ]);
    }

    private function assertEditable(Role $role): void
    {
        if ($role->name === PermissionCatalog::SUPER_ADMIN) {
            throw new BusinessRuleException('role_locked', 'The Super Admin role always has every permission and cannot be changed.');
        }
    }

    private function userCount(Role $role): int
    {
        return DB::table('model_has_roles')->where('role_id', $role->id)->where('model_type', User::class)->count();
    }

    private function shape(Role $role, int $users): array
    {
        return ['id' => $role->id, 'name' => $role->name, 'locked' => $role->name === PermissionCatalog::SUPER_ADMIN,
            'users_count' => $users, 'permissions' => $role->permissions->pluck('name')->values()];
    }
}
