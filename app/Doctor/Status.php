<?php

namespace App\Doctor;

enum Status: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';
}
