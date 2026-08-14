<?php

test('the office runtime assets are valid and remain within their transfer budgets', function () {
    $assetDirectory = resource_path('assets/office');
    $assets = [
        'office.glb' => 5 * 1024 * 1024,
        'project-manager.glb' => 2.5 * 1024 * 1024,
        'coder.glb' => 2.5 * 1024 * 1024,
        'reviewer.glb' => 2.5 * 1024 * 1024,
    ];
    $hairstyles = [
        'project-manager.glb' => 'Hair_SimpleParted',
        'coder.glb' => 'Hair_Buzzed',
        'reviewer.glb' => 'Hair_Long',
    ];

    $transferredSize = 0;

    foreach ($assets as $filename => $maximumSize) {
        $path = $assetDirectory.'/'.$filename;

        expect($path)
            ->toBeFile()
            ->and(filesize($path))
            ->toBeLessThanOrEqual($maximumSize)
            ->and(file_get_contents($path, false, null, 0, 4))
            ->toBe('glTF');

        $transferredSize += filesize($path);

        if ($filename === 'office.glb') {
            continue;
        }

        $contents = file_get_contents($path);
        $jsonLength = unpack('Vlength', substr($contents, 12, 4))['length'];
        $asset = json_decode(
            substr($contents, 20, $jsonLength),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect(array_column($asset['animations'], 'name'))->toEqual([
            'Idle',
            'Present',
            'Review',
            'SitIdle',
            'Think',
            'Type',
            'Walk',
        ]);
        expect(array_column($asset['nodes'], 'name'))
            ->toContain($hairstyles[$filename]);

        $typingAnimation = array_values(array_filter(
            $asset['animations'],
            fn (array $animation): bool => $animation['name'] === 'Type',
        ))[0];
        $typingDuration = max(array_map(
            fn (array $sampler): float => $asset['accessors'][$sampler['input']]['max'][0],
            $typingAnimation['samplers'],
        ));

        expect($typingDuration)->toBe(2.0);
    }

    expect($transferredSize)->toBeLessThan(12 * 1024 * 1024);

    $attribution = json_decode(
        file_get_contents($assetDirectory.'/attribution.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($attribution)
        ->toMatchArray([
            'environment' => [
                'source' => 'https://vnbp.itch.io/low-poly-3d-office-set-vnb',
                'creator' => 'VNBP Leo',
                'license' => 'CC BY 4.0',
            ],
            'characters' => [
                'source' => 'https://quaternius.itch.io/universal-base-characters',
                'creator' => 'Quaternius',
                'license' => 'CC0 1.0',
            ],
            'animations' => [
                'source' => 'https://quaternius.itch.io/universal-animation-library',
                'creator' => 'Quaternius',
                'license' => 'CC0 1.0',
            ],
        ]);
});
