<?php

namespace App\Services\DemandForecast\Exceptions;

class CatBoostValidationException extends CatBoostClientException
{
    protected array $errors = [];

    public function __construct(string $message = "", int $code = 422, ?\Throwable $previous = null, array $errors = [])
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
