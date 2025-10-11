<?php

// app/Support/Helpers.php
use App\Support\PlanParameterCompat;

function pp(string $key): mixed
{
    return PlanParameterCompat::get($key);
}

