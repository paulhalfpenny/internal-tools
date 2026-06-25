<?php

namespace App\Mcp;

use App\Mcp\Tools\AccountInfo;
use App\Mcp\Tools\ArchiveClient;
use App\Mcp\Tools\ArchiveProject;
use App\Mcp\Tools\AssignProjectMember;
use App\Mcp\Tools\CreateClient;
use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\DeleteTimeEntry;
use App\Mcp\Tools\GetProjectBudget;
use App\Mcp\Tools\GetRunningTimer;
use App\Mcp\Tools\ListAsanaTasks;
use App\Mcp\Tools\ListClients;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\ListTimeEntries;
use App\Mcp\Tools\ListUsers;
use App\Mcp\Tools\LogTimeEntry;
use App\Mcp\Tools\StartTimer;
use App\Mcp\Tools\StopTimer;
use App\Mcp\Tools\TimeReport;
use App\Mcp\Tools\UnassignProjectMember;
use App\Mcp\Tools\UpdateClient;
use App\Mcp\Tools\UpdateProject;
use App\Mcp\Tools\UpdateTimeEntry;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Filter Internal Tools')]
#[Version('1.0.0')]
#[Instructions('Use this server to read and update Filter internal time tracking, project, client, task, team, reporting, and budget data. High-impact writes return an approval_url and must be approved in the web app before execution.')]
class InternalToolsServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        AccountInfo::class,
        ListClients::class,
        CreateClient::class,
        UpdateClient::class,
        ArchiveClient::class,
        ListProjects::class,
        CreateProject::class,
        UpdateProject::class,
        ArchiveProject::class,
        AssignProjectMember::class,
        UnassignProjectMember::class,
        ListAsanaTasks::class,
        ListTasks::class,
        ListUsers::class,
        ListTimeEntries::class,
        LogTimeEntry::class,
        UpdateTimeEntry::class,
        DeleteTimeEntry::class,
        StartTimer::class,
        StopTimer::class,
        GetRunningTimer::class,
        TimeReport::class,
        GetProjectBudget::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
