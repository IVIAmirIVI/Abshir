<?php

namespace App\Livewire\Orders;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait MapsDomainErrors
{
    protected function domainAction(callable $action, array $fields = []): mixed
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$fields[$field] ?? Str::camel($field)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
