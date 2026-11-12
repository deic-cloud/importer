<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	],
	'ocs' => [
		// Jobs
		['name' => 'api#listJobs',   'url' => '/api/v1/jobs',      'verb' => 'GET'],
		['name' => 'api#queueJob',   'url' => '/api/v1/jobs',      'verb' => 'POST'],
		['name' => 'api#deleteJob',  'url' => '/api/v1/jobs/{id}', 'verb' => 'DELETE'],
		// One step of a whole-folder job while the Import page is open
		['name' => 'api#stepJob',    'url' => '/api/v1/jobs/{id}/step', 'verb' => 'POST'],
		// Credentials
		['name' => 'api#listCredentials',   'url' => '/api/v1/credentials',            'verb' => 'GET'],
		['name' => 'api#saveCredentials',   'url' => '/api/v1/credentials',            'verb' => 'POST'],
		// provider and host as query parameters: a host is usually a URL, and an
		// encoded "/" in a path segment never reaches PHP (Apache AllowEncodedSlashes Off)
		['name' => 'api#deleteCredentialsQuery', 'url' => '/api/v1/credentials',     'verb' => 'DELETE'],
		['name' => 'api#deleteCredentials', 'url' => '/api/v1/credentials/{provider}/{host}', 'verb' => 'DELETE'],
		// Retry failed jobs
		['name' => 'api#retryFailed', 'url' => '/api/v1/retry',        'verb' => 'POST'],
		['name' => 'api#retryJob',    'url' => '/api/v1/retry/{id}',   'verb' => 'POST'],
		// Pre-create all destination folders before parallel downloads start
		['name' => 'api#prepareDestinations', 'url' => '/api/v1/prepare', 'verb' => 'POST'],
		// Process next queued job (synchronous, called by frontend workers)
		['name' => 'api#processJob', 'url' => '/api/v1/process', 'verb' => 'POST'],
		// Directory listing (for folder picker in import form)
		['name' => 'api#listRemote', 'url' => '/api/v1/ls', 'verb' => 'POST'],
		// Grant groups for current user
		['name' => 'api#listGrantGroups', 'url' => '/api/v1/grant-groups', 'verb' => 'GET'],
	],
];
