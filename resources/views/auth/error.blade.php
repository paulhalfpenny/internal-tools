<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access Denied — Filter Time Tracker</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen">
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 max-w-md w-full text-center">
        <h1 class="text-lg font-semibold text-gray-900 mb-2">Access Denied</h1>
        <p class="text-sm text-gray-600 mb-6">
            {{ session('error', 'You must sign in with a filter.agency Google account.') }}
        </p>
        <a href="{{ route('auth.google') }}"
           class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
            Sign in with Google
        </a>
    </div>
<script>
  window.FeedbackFlowConfig = {
    endpoint: 'https://bugfeed.co.uk/api/feedback',
    projectId: 'Xgd8K5hykzc3Q-MI',
    position: 'bottom-right',
    primaryColor: '#e1236c',
    theme: 'dark'
  };
</script>
<script src="https://bugfeed.co.uk/widget.js" defer></script>
</body>
</html>
