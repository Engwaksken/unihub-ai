<?php

namespace App\Gateway\Exceptions;

/**
 * 422 VALIDATION_FAILED. details.fields = {field: [messages]}. The exception
 * handler turns it into a Laravel ValidationException so the messages land on
 * the form fields with the same names.
 */
class GatewayValidationException extends GatewayException
{
    /**
     * Field errors keyed by top-level form field ("allowed_tasks.0" becomes
     * "allowed_tasks"). Falls back to a "form" key when no field is named.
     *
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        $fields = $this->details['fields'] ?? [];
        $errors = [];

        if (is_array($fields)) {
            foreach ($fields as $field => $messages) {
                $key = explode('.', (string) $field)[0] ?: 'form';
                foreach ((array) $messages as $message) {
                    if (is_scalar($message)) {
                        $errors[$key][] = (string) $message;
                    }
                }
            }
        }

        if ($errors === []) {
            $errors['form'] = [$this->getMessage() ?: 'The gateway rejected the request.'];
        }

        return $errors;
    }
}
