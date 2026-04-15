<?php

namespace App\Services;

use App\Exceptions\AppException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

class JsonSchemaValidationService
{
    public function assertValidSchema(?array $schema, string $fieldName): void
    {
        if ($schema === null) {
            return;
        }

        if ($schema === []) {
            throw new AppException("{$fieldName} must be a valid JSON Schema object, empty array given.", 'INVALID_JSON_SCHEMA', 422);
        }

        try {
            $validator = new Validator();
            $schemaObject = json_decode(json_encode($schema));

            if (! is_object($schemaObject) && ! is_bool($schemaObject)) {
                throw new AppException("{$fieldName} must be a JSON object or boolean schema.", 'INVALID_JSON_SCHEMA', 422);
            }

            $validator->loader()->loadObjectSchema($schemaObject);
        } catch (AppException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AppException("{$fieldName} is not a valid JSON Schema: {$e->getMessage()}", 'INVALID_JSON_SCHEMA', 422);
        }
    }

    public function validatePayload(array $payload, ?array $schema): void
    {
        if ($schema === null) {
            return;
        }

        $validator = new Validator(max_errors: 10, stop_at_first_error: false);
        $result = $validator->validate(
            json_decode(json_encode($payload)),
            json_decode(json_encode($schema))
        );

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        $message = $error ? $this->formatValidationError($error) : 'Payload does not match schema';

        throw new AppException($message, 'INVALID_JSON_PAYLOAD', 422);
    }

    private function formatValidationError(ValidationError $error): string
    {
        $children = $error->subErrors();
        if ($children !== []) {
            $messages = array_map(
                fn (ValidationError $child) => $this->formatValidationError($child),
                $children
            );

            return implode('; ', array_filter($messages));
        }

        return '['.$error->keyword().'] '.$this->interpolateMessage($error->message(), $error->args());
    }

    private function interpolateMessage(string $message, array $args): string
    {
        foreach ($args as $key => $value) {
            $formatted = is_array($value) ? implode(', ', $value) : (string) $value;
            $message   = str_replace('{' . $key . '}', $formatted, $message);
        }

        return $message;
    }
}
