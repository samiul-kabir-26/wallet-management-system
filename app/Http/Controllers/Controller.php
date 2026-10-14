<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsPaginatedResponse;

abstract class Controller
{
    use FormatsPaginatedResponse;
}
