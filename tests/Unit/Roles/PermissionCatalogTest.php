<?php

use Modules\Roles\Enums\Permission;

test('frontend TypeScript permissions list matches backend Permission enum exactly', function () {
    $tsFilePath = dirname(__DIR__, 3).'/resources/js/types/permissions.ts';
    expect(file_exists($tsFilePath))->toBeTrue();

    $content = file_get_contents($tsFilePath);

    // Extract all quoted permission string literals inside PERMISSIONS = [...]
    preg_match('/export\s+const\s+PERMISSIONS\s*=\s*\[(.*?)\]\s*as\s+const/s', $content, $matches);
    expect($matches)->toHaveCount(2);

    preg_match_all('/\'([^\']+)\'/', $matches[1], $permMatches);
    $tsPermissions = $permMatches[1] ?? [];

    $backendPermissions = array_map(fn ($p) => $p->value, Permission::cases());

    sort($tsPermissions);
    sort($backendPermissions);

    expect($tsPermissions)->toBe($backendPermissions);
});
