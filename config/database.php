<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [

	'default' => 'mysql',

	'connections' => [

		'mysql' => [
			'driver' => 'mysql',
			'host' => env('DB_HOST', '127.0.0.1'),
			'port' => env('DB_PORT', '3306'),
			'database' => env('DB_DATABASE', 'forge'),
			'username' => env('DB_USERNAME', 'forge'),
			'password' => env('DB_PASSWORD', ''),
			'unix_socket' => env('DB_SOCKET', ''),
			'charset' => 'utf8',
			'collation' => 'utf8_unicode_ci',
			'prefix' => '',
			'strict' => true,
			'engine' => null,
		],

		'zonemta' => [
			'driver' => 'mysql',
			'host' => env('ZONEMTA_HOST', '127.0.0.1'),
			'port' => env('ZONEMTA_PORT', '3306'),
			'database' => env('ZONEMTA_DB', 'forge'),
			'username' => env('ZONEMTA_USERNAME', 'forge'),
			'password' => env('ZONEMTA_PASSWORD', ''),
			'unix_socket' => env('ZONEMTA_SOCKET', ''),
			'charset' => 'utf8',
			'collation' => 'utf8_unicode_ci',
			'prefix' => '',
			'strict' => true,
			'engine' => null,
			'modes' => [
				'ONLY_FULL_GROUP_BY',
				'STRICT_TRANS_TABLES',
				'NO_ZERO_IN_DATE',
				'NO_ZERO_DATE',
				'ERROR_FOR_DIVISION_BY_ZERO',
				'NO_ENGINE_SUBSTITUTION',
			],
		],

        'mongodb' => [
            'driver'   => 'mongodb',
            'host'     => env('MONGO_HOSTNAME', '127.0.0.1'),
            'database' => env('MONGO_DATABASE', null),
            'username' => env('MONGO_USERNAME', null),
            'password' => env('MONGO_PASSWORD', null),
            'port'     =>  27017,
            'options' => [
                // here you can pass more settings to the Mongo Driver Manager
                // see https://www.php.net/manual/en/mongodb-driver-manager.construct.php under "Uri Options" for a list of complete parameters that you can use

                'database' => 'admin', // required with Mongo 3+
            ],
        ],


		'sqlite' => [
			'driver' => 'sqlite',
			'database' => env('DB_DATABASE', ''),
			'prefix' => '',
		],

		'pgsql' => [
			'driver' => 'pgsql',
			'host' => env('DB_HOST', '127.0.0.1'),
			'port' => env('DB_PORT', '5432'),
			'database' => env('DB_DATABASE', 'forge'),
			'username' => env('DB_USERNAME', 'forge'),
			'password' => env('DB_PASSWORD', ''),
			'charset' => 'utf8',
			'prefix' => '',
			'schema' => 'public',
			'sslmode' => 'prefer',
		],

		'sqlsrv' => [
			'driver' => 'sqlsrv',
			'host' => env('DB_HOST', 'localhost'),
			'port' => env('DB_PORT', '1433'),
			'database' => env('DB_DATABASE', 'forge'),
			'username' => env('DB_USERNAME', 'forge'),
			'password' => env('DB_PASSWORD', ''),
			'charset' => 'utf8',
			'prefix' => '',
		],
	],
];
