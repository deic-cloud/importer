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
		// Credentials
		['name' => 'api#listCredentials',   'url' => '/api/v1/credentials',            'verb' => 'GET'],
		['name' => 'api#saveCredentials',   'url' => '/api/v1/credentials',            'verb' => 'POST'],
		['name' => 'api#deleteCredentials', 'url' => '/api/v1/credentials/{provider}/{host}', 'verb' => 'DELETE'],
		// Directory listing (for folder picker in import form)
		['name' => 'api#listRemote', 'url' => '/api/v1/ls', 'verb' => 'POST'],
	],
];
