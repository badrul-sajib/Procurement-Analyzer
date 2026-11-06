<?php

use App\Support\AuditLogger;

it('masks secrets and refuses to modify or delete log entries', function () {
    actingWithPermissions();
    $log = AuditLogger::log('user.created', 'users', 1, null, null, ['email' => 'a@b.co', 'password' => 'secret']);

    expect($log->new_values['password'])->toBe('***');
    expect(fn () => $log->update(['action' => 'x']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

it('diffs only changed values', function () {
    [$old, $new] = AuditLogger::diff(['name' => 'A', 'code' => 'X'], ['name' => 'B', 'code' => 'X']);
    expect($old)->toBe(['name' => 'A'])->and($new)->toBe(['name' => 'B']);
});
