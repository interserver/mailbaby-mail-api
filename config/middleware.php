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
    // global middleware
    '' => [
        //app\middleware\AuthCheck::class,
        //app\middleware\AccessControl::class,
    ],
    // api application middleware (application middleware is only valid in multi-application mode)
    /*
    'api' => [
        app\middleware\ApiOnly::class,
    ]
    */
];