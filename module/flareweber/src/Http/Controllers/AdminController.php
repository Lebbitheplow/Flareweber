<?php

namespace FlareWeber\Http\Controllers;

use Illuminate\Routing\Controller;

class AdminController extends Controller
{
    public function index()
    {
        return view('flareweber::admin');
    }
}
