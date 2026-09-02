<?php

namespace App\Enums;

enum QualityEvaluationStatus: string
{
    case OK = 'ok';
    case INCOMPLETE = 'incomplete';
    case NOT_EVALUABLE = 'not_evaluable';

    public function isOk(): bool
    {
        return $this === self::OK;
    }
}
