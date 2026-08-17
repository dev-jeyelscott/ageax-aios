<?php

use Illuminate\Support\Facades\File;

test('roadmap file selection submits the upload form', function () {
    $source = File::get(resource_path('js/pages/projects/show.tsx'));

    expect($source)
        ->toContain('name="roadmap"')
        ->toContain('event.currentTarget.form?.requestSubmit();');
});
