<?php

namespace app\controller;

use support\Request;

class IndexController
{
    public function index(Request $request)
    {
        //return response('hello webman');
        //return view('index/view', ['name' => 'webman']);
        static $readme;
        if (!$readme) {
            $readme = file_get_contents(base_path('README.md'));
        }
        return $readme;
    }

    public function view(Request $request)
    {
        return view('index/view', ['name' => 'webman']);
    }

    public function json(Request $request)
    {
        return json(['code' => 0, 'msg' => 'ok']);
    }

}
