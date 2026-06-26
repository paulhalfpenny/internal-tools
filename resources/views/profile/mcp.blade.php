<x-app-layout>
    @php
        $mcpUrl = url('/mcp');
    @endphp

    <div class="max-w-5xl space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Internal tools</p>
            <h1 class="mt-2 text-2xl font-semibold text-gray-900">MCP guide</h1>
            <p class="mt-2 max-w-3xl text-sm text-gray-600">
                Connect Claude or another MCP client to read and update Internal Tools data with your signed-in account.
                Standard writes run immediately, while high-impact writes return an approval URL for review in the web app.
            </p>
        </div>

        <section class="bg-white border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900">How to connect via MCP</h2>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-gray-100 p-4">
                    <h3 class="text-sm font-semibold text-gray-900">Connect with Claude</h3>
                    <ol class="mt-3 space-y-3 text-sm text-gray-700 list-decimal list-inside">
                        <li>Open Claude settings and go to Connectors.</li>
                        <li>Add a custom connector.</li>
                        <li>Use <span class="font-medium">Filter Internal Tools</span> as the connector name.</li>
                        <li>Paste the MCP server URL shown here.</li>
                        <li>Sign in with your Filter account and approve the OAuth access request.</li>
                        <li>Ask Claude to list your account or projects to confirm the connection is working.</li>
                    </ol>

                    <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">MCP server URL</p>
                        <code class="mt-2 block break-all rounded border border-blue-200 bg-white px-3 py-2 text-sm text-blue-950">{{ $mcpUrl }}</code>
                        <p class="mt-3 text-xs text-blue-900">
                            OAuth scope: <code class="font-mono">mcp:use</code>. The connector signs in as you, so permissions match your Internal Tools role.
                        </p>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <h3 class="text-sm font-semibold text-gray-900">Connect with Codex</h3>
                    <ol class="mt-3 space-y-3 text-sm text-gray-700 list-decimal list-inside">
                        <li>Open a terminal on the machine where you run Codex.</li>
                        <li>Add the Streamable HTTP MCP server.</li>
                        <li>Run the OAuth login command and approve the browser sign-in.</li>
                        <li>Start Codex and run <code class="font-mono">/mcp</code> to confirm the server is connected.</li>
                    </ol>

                    <div class="mt-4 space-y-2">
                        <code class="block break-all rounded border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-900">codex mcp add filter_internal_tools --url {{ $mcpUrl }} --oauth-resource {{ $mcpUrl }}</code>
                        <code class="block break-all rounded border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-900">codex mcp login filter_internal_tools --scopes mcp:use</code>
                    </div>

                    <div class="mt-4 rounded border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs font-medium text-gray-900">Config file option</p>
                        <p class="mt-1 text-xs text-gray-600">Add this to <code class="font-mono">~/.codex/config.toml</code> or a project <code class="font-mono">.codex/config.toml</code>.</p>
                        <pre class="mt-3 overflow-x-auto text-xs text-gray-900"><code>[mcp_servers.filter_internal_tools]
url = "{{ $mcpUrl }}"
oauth_resource = "{{ $mcpUrl }}"
scopes = ["mcp:use"]
default_tools_approval_mode = "prompt"</code></pre>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900">What is available</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border border-gray-100 p-4">
                    <h3 class="text-sm font-semibold text-gray-900">Account and setup</h3>
                    <p class="mt-2 text-sm text-gray-600">Check who the connector is signed in as and inspect current timer state.</p>
                    <p class="mt-3 text-xs text-gray-500 font-mono">account-info, get-running-timer</p>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <h3 class="text-sm font-semibold text-gray-900">Clients, projects, tasks, and users</h3>
                    <p class="mt-2 text-sm text-gray-600">List visible clients, projects, tasks, users, linked Asana tasks, and project budgets.</p>
                    <p class="mt-3 text-xs text-gray-500 font-mono">list-clients, list-projects, list-tasks, list-users, list-asana-tasks, get-project-budget</p>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <h3 class="text-sm font-semibold text-gray-900">Time tracking</h3>
                    <p class="mt-2 text-sm text-gray-600">Log time, list entries, update your own entries, delete entries with approval, and start or stop timers.</p>
                    <p class="mt-3 text-xs text-gray-500 font-mono">list-time-entries, log-time-entry, update-time-entry, delete-time-entry, start-timer, stop-timer</p>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <h3 class="text-sm font-semibold text-gray-900">Admin writes</h3>
                    <p class="mt-2 text-sm text-gray-600">Admins can create and update clients or projects, and assign or remove project members.</p>
                    <p class="mt-3 text-xs text-gray-500 font-mono">create-client, update-client, create-project, update-project, assign-project-member, unassign-project-member</p>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <h3 class="text-sm font-semibold text-gray-900">Reporting</h3>
                    <p class="mt-2 text-sm text-gray-600">Run date-based time reports, optionally filtered by user, client, project, task, or billable status.</p>
                    <p class="mt-3 text-xs text-gray-500 font-mono">time-report</p>
                </div>

                <div class="rounded-lg border border-red-100 bg-red-50 p-4">
                    <h3 class="text-sm font-semibold text-red-950">High-impact writes</h3>
                    <p class="mt-2 text-sm text-red-900">
                        Updating another user's time entry, deleting a time entry, archiving a client, and archiving a project return an approval URL before anything changes.
                    </p>
                    <p class="mt-3 text-xs text-red-800 font-mono">approval_url</p>
                </div>
            </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900">Suggested prompts</h2>
            <div class="mt-4 grid gap-3">
                <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-800">
                    List my active projects and the tasks I can log time against.
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-800">
                    Show me the cached Asana tasks for project 123, including the board each task belongs to.
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-800">
                    Log 1:30 today to project 123 and task 4 with the note "Client planning call".
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-800">
                    Create a client called Example Co with code EXM, then create a billable Discovery project for that client.
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-800">
                    Run a billable-only time report for this month grouped by project.
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-800">
                    Start a timer for project 123 and task 4 for today, using Asana task gid 1200000000000000.
                </div>
            </div>
        </section>

        <section class="bg-white border border-gray-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900">Common parameters</h2>
            <dl class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-gray-900">Dates</dt>
                    <dd class="mt-1 text-sm text-gray-600">Use <code class="font-mono">YYYY-MM-DD</code> for date filters and time-entry dates.</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-900">Hours</dt>
                    <dd class="mt-1 text-sm text-gray-600">Use decimal hours like <code class="font-mono">1.5</code>, time like <code class="font-mono">1:30</code>, or minutes like <code class="font-mono">90m</code>.</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-900">Project scope</dt>
                    <dd class="mt-1 text-sm text-gray-600">Managers and admins can ask for all projects; standard users see directly assigned projects.</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-900">Asana tasks</dt>
                    <dd class="mt-1 text-sm text-gray-600">Projects can require an Asana task gid when logging or updating time.</dd>
                </div>
            </dl>
        </section>
    </div>
</x-app-layout>
