<?php

use Illuminate\Support\Facades\File;

test('the application shell uses the uploaded branding assets', function () {
    expect(File::exists(public_path('logo.png')))->toBeTrue()
        ->and(File::exists(public_path('favicon.ico')))->toBeTrue()
        ->and(File::get(resource_path('views/app.blade.php')))->toContain('href="/favicon.ico"')
        ->toContain('href="/logo.png"')
        ->not->toContain('favicon.svg');

    expect(File::get(resource_path('js/components/app-logo-icon.tsx')))
        ->toContain('src="/logo.png"')
        ->not->toContain('<svg');
});
