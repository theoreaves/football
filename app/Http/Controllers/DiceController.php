<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DiceController extends Controller
{
    public function index()
    {
        return view('dice.index');
    }
}
