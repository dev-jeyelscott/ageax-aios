<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\AgentHarnessResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\TokenUsageObservability;
use Illuminate\Http\Request;
use Inertia\Response;

class ProjectWorkflowController extends Controller
{
    public function __invoke(
        Project $project,
        Request $request,
        ProjectController $projects,
        AuditLogger $audit,
        TokenUsageObservability $tokens,
        AgentRunRecorder $runs,
        AgentHarnessResolver $harnesses,
    ): Response {
        return $projects->show(
            $project,
            $request,
            $audit,
            $tokens,
            $runs,
            $harnesses,
        );
    }
}
