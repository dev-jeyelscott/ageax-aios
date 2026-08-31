<?php

use App\Exceptions\UnsafeProjectPath;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\TaskWorktreeManager;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Create a clean Git-backed project inside the configured AIOS workspace for worktree tests.
 *
 * @return array{0: Project, 1: string}
 */
function taskWorktreeProject(
    ?string $workspaceRoot = null,
): array {
    $workspaceRoot ??= sys_get_temp_dir();

    File::ensureDirectoryExists($workspaceRoot);
    config()->set(
        'aios.workspace_root',
        $workspaceRoot,
    );

    $path = $workspaceRoot
        .'/aios-task-worktree-project-'
        .fake()->uuid();

    File::ensureDirectoryExists($path);

    Process::path($path)->run([
        'git',
        'init',
    ]);

    Process::path($path)->run([
        'git',
        'config',
        'user.email',
        'aios@example.test',
    ]);

    Process::path($path)->run([
        'git',
        'config',
        'user.name',
        'AIOS Test',
    ]);

    File::put(
        $path.'/baseline.txt',
        "baseline\n",
    );

    Process::path($path)->run([
        'git',
        'add',
        'baseline.txt',
    ]);

    Process::path($path)->run([
        'git',
        'commit',
        '-m',
        'Baseline',
    ]);

    $baseSha = trim(
        Process::path($path)
            ->run([
                'git',
                'rev-parse',
                'HEAD',
            ])
            ->output(),
    );

    $project = Project::create([
        'name' => 'Task Worktree Isolation '.fake()->uuid(),
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
        'git_head_sha' => $baseSha,
    ]);

    return [$project, $baseSha];
}

/**
 * Create one Coder Task whose worktree may be isolated from sibling Tasks.
 */
function taskWorktreeTask(
    Project $project,
    string $key,
    int $position,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => "Worktree task {$position}",
        'objective' => 'Change only this Task worktree.',
        'acceptance_criteria' => [
            'The Task worktree remains isolated.',
        ],
        'implementation_prompt' => 'Implement only the assigned Task.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);
}

/**
 * Create durable attempt state that pins one Task worktree to an exact base SHA.
 */
function taskWorktreeAttempt(
    Task $task,
    string $baseSha,
    int $number = 1,
): TaskAttempt {
    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => $number,
        'base_sha' => $baseSha,
        'status' => 'running',
        'started_at' => now(),
    ]);
}

/**
 * Verify sibling Tasks receive separate exact-base worktrees and cannot cross-scope manager operations.
 */
test(
    'task worktrees isolate sibling coder attempts and keep the main checkout clean',
    function () {
        [$project, $baseSha] = taskWorktreeProject();

        $taskA = taskWorktreeTask(
            $project,
            'P10-002-A',
            1,
        );

        $taskB = taskWorktreeTask(
            $project,
            'P10-002-B',
            2,
        );

        $attemptA = taskWorktreeAttempt(
            $taskA,
            $baseSha,
        );

        $attemptB = taskWorktreeAttempt(
            $taskB,
            $baseSha,
        );

        $manager = app(
            TaskWorktreeManager::class,
        );

        $pathA = $manager->acquire(
            $taskA,
            $attemptA,
        );

        $pathB = $manager->acquire(
            $taskB,
            $attemptB,
        );

        try {
            File::put(
                $pathA.'/task-a.txt',
                "task a\n",
            );

            File::put(
                $pathB.'/task-b.txt',
                "task b\n",
            );

            expect($pathA)
                ->not->toBe($pathB)
                ->and(
                    trim(
                        Process::path($pathA)
                            ->run([
                                'git',
                                'rev-parse',
                                'HEAD',
                            ])
                            ->output(),
                    ),
                )
                ->toBe($baseSha)
                ->and(
                    trim(
                        Process::path($pathB)
                            ->run([
                                'git',
                                'rev-parse',
                                'HEAD',
                            ])
                            ->output(),
                    ),
                )
                ->toBe($baseSha)
                ->and(
                    File::exists(
                        $pathA.'/task-b.txt',
                    ),
                )
                ->toBeFalse()
                ->and(
                    File::exists(
                        $pathB.'/task-a.txt',
                    ),
                )
                ->toBeFalse()
                ->and(
                    File::exists(
                        $project->path.'/task-a.txt',
                    ),
                )
                ->toBeFalse()
                ->and(
                    File::exists(
                        $project->path.'/task-b.txt',
                    ),
                )
                ->toBeFalse()
                ->and(
                    trim(
                        Process::path($project->path)
                            ->run([
                                'git',
                                'status',
                                '--porcelain',
                            ])
                            ->output(),
                    ),
                )
                ->toBe('');

            expect(
                fn () => $manager->acquire(
                    $taskA,
                    $attemptB,
                ),
            )->toThrow(
                LogicException::class,
                'Task worktree operations require a persisted attempt owned by the same Task.',
            );
        } finally {
            $manager->release(
                $taskA,
                $attemptA,
            );

            $manager->release(
                $taskB,
                $attemptB,
            );
        }
    },
);

/**
 * Verify a symlink cannot alias one Task's deterministic worktree path onto another Task worktree.
 */
test(
    'task worktree acquisition does not follow a sibling worktree symlink',
    function () {
        [$project, $baseSha] = taskWorktreeProject();

        $taskA = taskWorktreeTask(
            $project,
            'P10-002-SYMLINK-A',
            1,
        );

        $taskB = taskWorktreeTask(
            $project,
            'P10-002-SYMLINK-B',
            2,
        );

        $attemptA = taskWorktreeAttempt(
            $taskA,
            $baseSha,
        );

        $attemptB = taskWorktreeAttempt(
            $taskB,
            $baseSha,
        );

        $manager = app(
            TaskWorktreeManager::class,
        );

        $pathA = $manager->acquire(
            $taskA,
            $attemptA,
        );

        $pathB = $manager->acquire(
            $taskB,
            $attemptB,
        );

        $manager->release(
            $taskA,
            $attemptA,
        );

        File::put(
            $pathB.'/task-b-marker.txt',
            "preserve me\n",
        );

        symlink(
            $pathB,
            $pathA,
        );

        try {
            $recreatedPathA = $manager->acquire(
                $taskA,
                $attemptA,
            );

            expect($recreatedPathA)
                ->toBe($pathA)
                ->and(is_link($pathA))
                ->toBeFalse()
                ->and(
                    File::exists(
                        $pathA.'/task-b-marker.txt',
                    ),
                )
                ->toBeFalse()
                ->and(
                    File::get(
                        $pathB.'/task-b-marker.txt',
                    ),
                )
                ->toBe("preserve me\n")
                ->and(
                    trim(
                        Process::path($pathB)
                            ->run([
                                'git',
                                'rev-parse',
                                'HEAD',
                            ])
                            ->output(),
                    ),
                )
                ->toBe($baseSha);
        } finally {
            $manager->release(
                $taskA,
                $attemptA,
            );

            $manager->release(
                $taskB,
                $attemptB,
            );
        }
    },
);

/**
 * Verify interrupted worktree state is reusable and repeated cleanup tolerates stale or partial setup.
 */
test(
    'task worktree recovery and cleanup are idempotent',
    function () {
        [$project, $baseSha] = taskWorktreeProject();

        $task = taskWorktreeTask(
            $project,
            'P10-002-RECOVERY',
            1,
        );

        $attempt = taskWorktreeAttempt(
            $task,
            $baseSha,
        );

        $manager = app(
            TaskWorktreeManager::class,
        );

        $path = $manager->acquire(
            $task,
            $attempt,
        );

        File::put(
            $path.'/interrupted.txt',
            "unfinished work\n",
        );

        $reusedPath = $manager->acquire(
            $task,
            $attempt,
        );

        expect($reusedPath)
            ->toBe($path)
            ->and(
                File::get(
                    $reusedPath.'/interrupted.txt',
                ),
            )
            ->toBe("unfinished work\n");

        File::deleteDirectory($path);

        $recoveredPath = $manager->acquire(
            $task,
            $attempt,
        );

        expect($recoveredPath)
            ->toBe($path)
            ->and(is_dir($recoveredPath))
            ->toBeTrue()
            ->and(
                File::exists(
                    $recoveredPath.'/interrupted.txt',
                ),
            )
            ->toBeFalse()
            ->and(
                trim(
                    Process::path($recoveredPath)
                        ->run([
                            'git',
                            'rev-parse',
                            'HEAD',
                        ])
                        ->output(),
                ),
            )
            ->toBe($baseSha)
            ->and(
                trim(
                    Process::path($project->path)
                        ->run([
                            'git',
                            'status',
                            '--porcelain',
                        ])
                        ->output(),
                ),
            )
            ->toBe('');

        $manager->release(
            $task,
            $attempt,
        );

        $manager->release(
            $task,
            $attempt,
        );

        expect(is_dir($path))
            ->toBeFalse()
            ->and(
                Process::path($project->path)
                    ->run([
                        'git',
                        'worktree',
                        'list',
                        '--porcelain',
                    ])
                    ->output(),
            )
            ->not->toContain($path);
    },
);

/**
 * Verify untracked shared dependency directories are usable inside the isolated worktree without
 * being copied into Git or risking the main checkout when the worktree is later released.
 */
test(
    'task worktree links shared vendor and node_modules without exposing them to git or the main checkout',
    function () {
        [$project, $baseSha] = taskWorktreeProject();

        File::put($project->path.'/.gitignore', "/vendor\n/node_modules\n");

        File::ensureDirectoryExists($project->path.'/vendor/acme');
        File::put($project->path.'/vendor/acme/marker.php', "<?php\n");

        File::ensureDirectoryExists($project->path.'/node_modules/acme');
        File::put($project->path.'/node_modules/acme/marker.js', "module.exports = {};\n");

        $task = taskWorktreeTask(
            $project,
            'P10-002-DEPS',
            1,
        );

        $attempt = taskWorktreeAttempt(
            $task,
            $baseSha,
        );

        $manager = app(
            TaskWorktreeManager::class,
        );

        $path = $manager->acquire(
            $task,
            $attempt,
        );

        try {
            expect(File::exists($path.'/vendor/acme/marker.php'))
                ->toBeTrue()
                ->and(is_link($path.'/vendor'))
                ->toBeTrue()
                ->and(File::exists($path.'/node_modules/acme/marker.js'))
                ->toBeTrue()
                ->and(is_link($path.'/node_modules'))
                ->toBeTrue();

            $manager->release(
                $task,
                $attempt,
            );

            expect(File::exists($project->path.'/vendor/acme/marker.php'))
                ->toBeTrue()
                ->and(File::exists($project->path.'/node_modules/acme/marker.js'))
                ->toBeTrue();
        } finally {
            $manager->release(
                $task,
                $attempt,
            );
        }
    },
);

/**
 * Verify WorkspacePathResolver still fails closed when the managed worktree root escapes through a symlink.
 */
test(
    'task worktree root cannot escape the configured workspace',
    function () {
        $workspaceRoot = sys_get_temp_dir()
            .'/aios-task-worktree-root-'
            .fake()->uuid();

        [$project, $baseSha] = taskWorktreeProject(
            $workspaceRoot,
        );

        $task = taskWorktreeTask(
            $project,
            'P10-002-PATH',
            1,
        );

        $attempt = taskWorktreeAttempt(
            $task,
            $baseSha,
        );

        $outside = sys_get_temp_dir()
            .'/aios-task-worktree-outside-'
            .fake()->uuid();

        File::ensureDirectoryExists($outside);

        symlink(
            $outside,
            $workspaceRoot.'/.aios-task-worktrees',
        );

        expect(
            fn () => app(
                TaskWorktreeManager::class,
            )->acquire(
                $task,
                $attempt,
            ),
        )->toThrow(
            UnsafeProjectPath::class,
            'The AIOS Task worktree root must not be a symbolic link.',
        );
    },
);
